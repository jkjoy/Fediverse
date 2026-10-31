<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class FakeLocalCommentQuery
{
    public string $operation = 'select';
    public string $table = '';
    public array $values = array();
    public array $rows = array();
    public function from($table) { $this->table = $table; return $this; }
    public function where($condition, ...$values) { $this->values[$condition] = $values; return $this; }
    public function limit($limit) { return $this; }
    public function rows($rows) { $this->rows = $rows; return $this; }
}

class FakeLocalCommentDb
{
    public array $mapping = array();
    public array $post = array('cid' => 3, 'type' => 'post', 'status' => 'publish', 'password' => null);
    public bool $targetFollows = false;
    public array $comment = array(
        'coid' => 15, 'cid' => 3, 'created' => 1700000000, 'authorId' => 7,
        'agent' => 'Mozilla/5.0', 'text' => "Local reply\nSecond line", 'type' => 'comment',
        'status' => 'approved', 'parent' => 0
    );
    public function select(...$fields) { return new FakeLocalCommentQuery(); }
    public function insert($table) { $query = new FakeLocalCommentQuery(); $query->operation = 'insert'; $query->table = $table; return $query; }
    public function update($table) { $query = new FakeLocalCommentQuery(); $query->operation = 'update'; $query->table = $table; return $query; }
    public function fetchRow($query)
    {
        if ($query->table === 'fediverse_local_comments') {
            if ((int)($query->values['coid = ?'][0] ?? 0) !== (int)($this->mapping['coid'] ?? 0)
                || (isset($query->values['deleted = ?'])
                    && (int)$query->values['deleted = ?'][0] !== (int)($this->mapping['deleted'] ?? 0))) {
                return null;
            }
            return $this->mapping ?: null;
        }
        if ($query->table === 'table.comments') {
            return (int)($query->values['coid = ?'][0] ?? 0) === 15
                && (!isset($query->values['status = ?'])
                    || $query->values['status = ?'][0] === $this->comment['status']) ? $this->comment : null;
        }
        if ($query->table === 'table.contents') {
            return $this->post && (!isset($query->values['type = ?'])
                || $query->values['type = ?'][0] === $this->post['type']) ? $this->post : null;
        }
        if ($query->table === 'fediverse_reply_objects'
            && (int)($query->values['comment_id = ?'][0] ?? 0) === 11) {
            return array('object_id' => 'https://remote.example/notes/11', 'actor' => 'https://remote.example/users/bob');
        }
        if ($query->table === 'fediverse_followers') {
            return $this->targetFollows ? array('id' => 1) : null;
        }
        return null;
    }
    public function query($query)
    {
        if ($query->operation === 'insert' && $query->table === 'fediverse_local_comments') {
            $this->mapping = $query->rows;
            return 1;
        }
        if ($query->operation === 'update' && $query->table === 'fediverse_local_comments') {
            $this->mapping = array_replace($this->mapping, $query->rows);
            return 1;
        }
        return 0;
    }
}

class Typecho_Db
{
    public static FakeLocalCommentDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static function isEnabled() { return true; }
    public static function userEnabled($uid) { return (int)$uid === 7; }
    public static function actorByUid($uid) { return (int)$uid === 7 ? array('username' => 'alice') : null; }
    public static function actorUrl($username) { return 'https://blog.example/fediverse/author/' . $username; }
    public static function objectUrl($cid) { return 'https://blog.example/fediverse/post/' . (int)$cid; }
    public static function url($path) { return 'https://blog.example/' . ltrim($path, '/'); }
    public static function activityId($prefix) { return 'https://blog.example/fediverse/activity/' . $prefix; }
    public static function iso8601($timestamp) { return gmdate('Y-m-d\TH:i:s\Z', (int)$timestamp); }
}

class Fediverse_ActivityPub
{
    public const CONTEXT = 'https://www.w3.org/ns/activitystreams';
    public const PUBLIC_AUDIENCE = 'https://www.w3.org/ns/activitystreams#Public';
}

class Fediverse_Queue
{
    public static array $activity = array();
    public static array $inboxes = array();
    public static bool $fail = false;
    public static int $calls = 0;
    public static int $deliveries = 1;
    public static function enqueueActivity($uid, $activity, $inboxes = array())
    {
        if (self::$fail) {
            throw new RuntimeException('Queue write failed');
        }
        self::$calls++;
        self::$activity = $activity;
        self::$inboxes = $inboxes;
        return self::$deliveries;
    }
}

class Fediverse_Client
{
    public static string $mode = '';
    public static bool $fail = false;
    public static string $inbox = 'https://remote.example/inbox';
    public static int $resolutions = 0;
    public static function moderationMode($uid, $actor) { return self::$mode; }
    public static function resolveActor($actor)
    {
        self::$resolutions++;
        if (self::$fail) {
            throw new RuntimeException('Recipient lookup failed');
        }
        return array('inbox' => self::$inbox);
    }
}

require dirname(__DIR__) . '/lib/Comments.php';

function checkLocalComment($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function resetLocalComment()
{
    Typecho_Db::$db = new FakeLocalCommentDb();
    Fediverse_Queue::$activity = array();
    Fediverse_Queue::$inboxes = array();
    Fediverse_Queue::$fail = false;
    Fediverse_Queue::$calls = 0;
    Fediverse_Queue::$deliveries = 1;
    Fediverse_Client::$mode = '';
    Fediverse_Client::$fail = false;
    Fediverse_Client::$inbox = 'https://remote.example/inbox';
    Fediverse_Client::$resolutions = 0;
    return Typecho_Db::$db;
}

function expectLocalCommentFailure($operation, $message)
{
    try {
        Fediverse_Comments::enqueue(15, $operation);
    } catch (RuntimeException $e) {
        return;
    }
    checkLocalComment(false, $message);
}

$db = resetLocalComment();
checkLocalComment(Fediverse_Comments::enqueue(15) === 1, 'An eligible local comment must reach delivery');
$activity = Fediverse_Queue::$activity;
checkLocalComment($activity['type'] === 'Create' && $activity['object']['type'] === 'Note',
    'A local comment must be delivered as Create Note');
checkLocalComment($activity['object']['id'] === 'https://blog.example/fediverse/comment/15',
    'A local comment must have a stable dereferenceable object ID');
checkLocalComment($activity['object']['inReplyTo'] === 'https://blog.example/fediverse/post/3',
    'A top-level local comment must reply to its article object');
checkLocalComment($activity['object']['attributedTo'] === 'https://blog.example/fediverse/author/alice',
    'A local comment must be attributed to its logged-in author');
checkLocalComment(in_array(Fediverse_ActivityPub::PUBLIC_AUDIENCE, $activity['object']['to'], true),
    'A top-level local comment must be public');
$object = Fediverse_Comments::object(15);
checkLocalComment($object !== null && str_contains($object['content'], 'Second line'),
    'The public comment route must return the stored Note');
checkLocalComment(Fediverse_Comments::enqueue(15) === 0,
    'A second create hook must not enqueue the same comment again');

$db->post['status'] = 'draft';
checkLocalComment(Fediverse_Comments::object(15) === null,
    'An existing public comment must be hidden when its article becomes a draft');
$db->post['status'] = 'private';
checkLocalComment(Fediverse_Comments::object(15) === null,
    'An existing public comment must be hidden when its article becomes private');
$db->post['status'] = 'publish';
foreach (array('secret', '0') as $password) {
    $db->post['password'] = $password;
    checkLocalComment(Fediverse_Comments::object(15) === null,
        'An existing comment must be hidden when its article is password protected');
}
$db->post['password'] = '';
$db->post['type'] = 'page';
checkLocalComment(Fediverse_Comments::object(15) === null,
    'A public comment object must still belong to an article');
$db->post = array();
checkLocalComment(Fediverse_Comments::object(15) === null,
    'An existing comment must be hidden when its article is missing');

foreach (array(array('password' => 'secret'), array('password' => '0'), array('status' => 'draft'),
    array('status' => 'private'), array('type' => 'page')) as $changes) {
    $db = resetLocalComment();
    $db->post = array_replace($db->post, $changes);
    checkLocalComment(Fediverse_Comments::enqueue(15) === 0 && !$db->mapping && Fediverse_Queue::$calls === 0,
        'A comment on an ineligible article must not become a public federated object');
}

$db = resetLocalComment();
Fediverse_Queue::$fail = true;
expectLocalCommentFailure('create', 'An initial queue write failure must be reported');
checkLocalComment(!$db->mapping, 'Failed Create delivery must not leave a mapping that suppresses retries');
Fediverse_Queue::$fail = false;
checkLocalComment(Fediverse_Comments::enqueue(15) === 1 && !empty($db->mapping),
    'A Create must remain retryable after the initial queue write fails');
$before = $db->mapping;
$db->comment['text'] = 'Updated reply';
$db->comment['parent'] = 11;
Fediverse_Queue::$fail = true;
expectLocalCommentFailure('update', 'An Update queue write failure must be reported');
checkLocalComment($db->mapping === $before, 'Failed Update delivery must preserve the previous mapping');
Fediverse_Queue::$fail = false;
checkLocalComment(Fediverse_Comments::enqueue(15, 'update') === 1
    && Fediverse_Queue::$activity['type'] === 'Update'
    && $db->mapping['in_reply_to'] === 'https://remote.example/notes/11',
    'A failed Update must be retryable with its new target');
$before = $db->mapping;
Fediverse_Queue::$fail = true;
expectLocalCommentFailure('delete', 'A Delete queue write failure must be reported');
checkLocalComment($db->mapping === $before, 'Failed Delete delivery must preserve the existing mapping');
Fediverse_Queue::$fail = false;
checkLocalComment(Fediverse_Comments::enqueue(15, 'delete') === 1 && $db->mapping['deleted'] === 1,
    'A successful Delete must mark the comment mapping deleted');
$before = $db->mapping;
Fediverse_Queue::$fail = true;
expectLocalCommentFailure('create', 'A failed restored Create must be reported');
checkLocalComment($db->mapping === $before, 'Failed restored Create must leave the deleted mapping unchanged');
Fediverse_Queue::$fail = false;
checkLocalComment(Fediverse_Comments::enqueue(15) === 1 && $db->mapping['deleted'] === 0
    && Fediverse_Queue::$activity['type'] === 'Create',
    'A previously deleted comment must be retryable after restored Create fails');

$db = resetLocalComment();
$db->comment['parent'] = 11;
Fediverse_Client::$fail = true;
expectLocalCommentFailure('create', 'Recipient lookup failure must not silently become success');
checkLocalComment(!$db->mapping && Fediverse_Queue::$calls === 0,
    'A failed direct recipient lookup must leave the reply retryable without follower-only delivery');
Fediverse_Client::$fail = false;
Fediverse_Client::$inbox = '';
expectLocalCommentFailure('create', 'An unavailable direct recipient inbox must be reported');
checkLocalComment(!$db->mapping && Fediverse_Queue::$calls === 0,
    'An unavailable direct inbox must not mark a reply delivered');
Fediverse_Client::$inbox = 'https://remote.example/inbox';
checkLocalComment(Fediverse_Comments::enqueue(15) === 1
    && Fediverse_Queue::$inboxes === array('https://remote.example/inbox'),
    'A recipient lookup retry must enqueue delivery to the remote parent author');

$db = resetLocalComment();
$db->comment['parent'] = 11;
Fediverse_Client::$mode = 'blocked';
checkLocalComment(Fediverse_Comments::enqueue(15) === 1 && Fediverse_Queue::$inboxes === array()
    && Fediverse_Client::$resolutions === 0,
    'A Create must not resolve or directly deliver to a blocked parent author');
checkLocalComment(Fediverse_Comments::enqueue(15, 'update') === 1 && Fediverse_Queue::$inboxes === array()
    && Fediverse_Client::$resolutions === 0,
    'An Update must not resolve or directly deliver to a blocked parent author');
$db->targetFollows = true;
checkLocalComment(Fediverse_Comments::enqueue(15, 'delete') === 1
    && Fediverse_Queue::$inboxes === array('https://remote.example/inbox') && $db->mapping['deleted'] === 1,
    'Delete must still reach a blocked parent author excluded from follower fan-out');

$db = resetLocalComment();
Fediverse_Queue::$deliveries = 0;
checkLocalComment(Fediverse_Comments::enqueue(15) === 0 && !empty($db->mapping)
    && Fediverse_Comments::object(15) !== null,
    'A public comment with no recipients must still have a valid public object');

fwrite(STDOUT, "local-comment-delivery: ok\n");
