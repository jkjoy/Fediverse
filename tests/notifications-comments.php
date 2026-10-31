<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class FakeNoticeQuery
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

class FakeNoticeDb
{
    public array $rows = array();
    public function select(...$fields) { return new FakeNoticeQuery(); }
    public function insert($table) { $query = new FakeNoticeQuery(); $query->operation = 'insert'; $query->table = $table; return $query; }
    public function update($table) { $query = new FakeNoticeQuery(); $query->operation = 'update'; $query->table = $table; return $query; }
    public function delete($table) { $query = new FakeNoticeQuery(); $query->operation = 'delete'; $query->table = $table; return $query; }
    public function fetchRow($query)
    {
        foreach ($this->rows as $row) {
            if ((int)$row['uid'] === (int)($query->values['uid = ?'][0] ?? 0)
                && $row['activity_hash'] === ($query->values['activity_hash = ?'][0] ?? '')) {
                return $row;
            }
        }
        return null;
    }
    public function query($query)
    {
        if ($query->operation === 'insert') {
            $query->rows['nid'] = count($this->rows) + 1;
            $this->rows[] = $query->rows;
            return 1;
        }
        if ($query->operation === 'update') {
            $count = 0;
            foreach ($this->rows as &$row) {
                if ($row['read_at'] === null) {
                    $row = array_replace($row, $query->rows);
                    $count++;
                }
            }
            unset($row);
            return $count;
        }
        if ($query->operation === 'delete') {
            $before = count($this->rows);
            $uid = (int)($query->values['uid = ?'][0] ?? 0);
            $hash = (string)($query->values['activity_hash = ?'][0] ?? '');
            $actor = (string)($query->values['actor = ?'][0] ?? '');
            $objectId = (string)($query->values['object_id = ?'][0] ?? '');
            $this->rows = array_values(array_filter($this->rows, static fn($row) =>
                !((int)$row['uid'] === $uid
                    && ($hash === '' || (string)$row['activity_hash'] === $hash)
                    && ($actor === '' || (string)$row['actor'] === $actor)
                    && ($objectId === '' || (string)($row['object_id'] ?? '') === $objectId))));
            return $before - count($this->rows);
        }
        return 0;
    }
}

class Typecho_Db
{
    public static FakeNoticeDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static function userEnabled($uid) { return (int)$uid === 7; }
}

require dirname(__DIR__) . '/lib/Notifications.php';
require dirname(__DIR__) . '/lib/Comments.php';

function checkNotice($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

Typecho_Db::$db = new FakeNoticeDb();
$activityId = 'https://remote.example/activities/1';
checkNotice(Fediverse_Notifications::add(7, 'Like', 'https://remote.example/users/alice', $activityId),
    'A new notification must be stored');
checkNotice(!Fediverse_Notifications::add(7, 'Like', 'https://remote.example/users/alice', $activityId),
    'The same activity must not create duplicate notifications');
checkNotice(count(Typecho_Db::$db->rows) === 1, 'Notification deduplication failed');
checkNotice(Fediverse_Notifications::markAllRead() === 1 && Typecho_Db::$db->rows[0]['read_at'] !== null,
    'Unread notifications must be markable as read');
checkNotice(Fediverse_Notifications::removeActivity(
    7,
    'https://remote.example/users/alice',
    $activityId
) === 1 && Typecho_Db::$db->rows === array(),
    'Undo must remove its original notification');

$sharedObject = 'https://blog.example/fediverse/post/9';
Fediverse_Notifications::add(7, 'Like', 'https://remote.example/users/alice',
    'https://remote.example/activities/alice-like', $sharedObject);
Fediverse_Notifications::add(7, 'Like', 'https://remote.example/users/bob',
    'https://remote.example/activities/bob-like', $sharedObject);
checkNotice(Fediverse_Notifications::removeActivity(
    7,
    'https://remote.example/users/alice',
    'https://remote.example/activities/bob-like'
) === 0 && count(Typecho_Db::$db->rows) === 2,
    'Undo must not remove another actor notification by reusing its activity ID');
checkNotice(Fediverse_Notifications::removeObject(
    7,
    'https://remote.example/users/alice',
    $sharedObject
) === 1 && count(Typecho_Db::$db->rows) === 1
    && Typecho_Db::$db->rows[0]['actor'] === 'https://remote.example/users/bob',
    'Delete must remove notifications only for the matching local user and actor');

$eligible = new ReflectionMethod(Fediverse_Comments::class, 'isEligible');
$base = array('type' => 'comment', 'agent' => 'Mozilla', 'authorId' => 7, 'status' => 'approved');
checkNotice($eligible->invoke(null, $base, false), 'Approved comments from enabled local users must federate');
checkNotice(!$eligible->invoke(null, array_replace($base, array('authorId' => 0)), false),
    'Anonymous comments must not be attributed to a local actor');
checkNotice(!$eligible->invoke(null, array_replace($base, array('agent' => 'ActivityPub')), false),
    'Remote comments must not be sent back into the federation');
checkNotice(!$eligible->invoke(null, array_replace($base, array('status' => 'waiting')), false),
    'Unapproved comments must not federate');

fwrite(STDOUT, "notifications-comments: ok\n");
