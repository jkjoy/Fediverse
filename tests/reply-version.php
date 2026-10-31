<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Common
{
    public static function subStr($text, $start, $length, $trim = '')
    {
        return substr((string)$text, (int)$start, (int)$length);
    }
}

class FakeVersionQuery
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

class FakeVersionDb
{
    public array $replyObjects = array();
    public array $versions = array();
    public array $comments = array();
    public function select(...$fields) { return new FakeVersionQuery(); }
    public function insert($table) { $query = new FakeVersionQuery(); $query->operation = 'insert'; $query->table = $table; return $query; }
    public function update($table) { $query = new FakeVersionQuery(); $query->operation = 'update'; $query->table = $table; return $query; }
    public function sql() { return new FakeVersionQuery(); }

    public function fetchRow($query)
    {
        $hash = (string)($query->values['object_hash = ?'][0] ?? '');
        if ($query->table === 'fediverse_reply_objects') {
            return $this->replyObjects[$hash] ?? null;
        }
        if ($query->table === 'fediverse_reply_versions') {
            return isset($this->versions[$hash])
                ? array('object_hash' => $hash, 'updated' => $this->versions[$hash])
                : null;
        }
        if ($query->table === 'table.comments') {
            return $this->comments[(int)($query->values['coid = ?'][0] ?? 0)] ?? null;
        }
        return null;
    }

    public function query($query)
    {
        if ($query->table !== 'fediverse_reply_versions') {
            return 0;
        }
        if ($query->operation === 'insert') {
            $this->versions[$query->rows['object_hash']] = (int)$query->rows['updated'];
            return 1;
        }
        $hash = (string)($query->values['object_hash = ?'][0] ?? '');
        $this->versions[$hash] = (int)$query->rows['updated'];
        return 1;
    }
}

class Typecho_Db
{
    public static FakeVersionDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static function setting($name, $default = null) { return $default; }
}

class FakeVersionContent
{
    public function have() { return true; }
}

class FakeVersionComments
{
    public static array $updates = array();
    public function update($rows, $where) { self::$updates[] = $rows; }
}

class Typecho_Widget
{
    public static function widget($name, ...$args)
    {
        return str_starts_with($name, 'Widget_Archive@')
            ? new FakeVersionContent()
            : new FakeVersionComments();
    }
}

class FakeVersionPlugin
{
    public function filter($component, $comment, $content) { return $comment; }
}

class Typecho_Plugin
{
    public static function factory($handle) { return new FakeVersionPlugin(); }
}

class FakeVersionRequest
{
    public function getIp() { return '203.0.113.10'; }
}

require dirname(__DIR__) . '/lib/ActivityPub.php';

function checkVersion($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$actor = 'https://remote.example/users/alice';
$objectId = 'https://remote.example/notes/1';
$hash = hash('sha256', $actor . "\0" . $objectId);
Typecho_Db::$db = new FakeVersionDb();
Typecho_Db::$db->replyObjects[$hash] = array('comment_id' => 42);
Typecho_Db::$db->versions[$hash] = strtotime('2026-03-01T00:00:00Z');
Typecho_Db::$db->comments[42] = array(
    'coid' => 42, 'cid' => 9, 'author' => 'Alice', 'mail' => '', 'url' => $actor,
    'ip' => '', 'agent' => 'ActivityPub', 'text' => 'Current content', 'status' => 'waiting'
);

$method = new ReflectionMethod(Fediverse_ActivityPub::class, 'updateReply');
$base = array(
    'actor' => array('id' => $actor, 'name' => 'Alice'),
    'object' => array(
        'id' => $objectId,
        'type' => 'Note',
        'attributedTo' => $actor,
        'updated' => '2026-02-01T00:00:00Z',
        'content' => 'Stale content'
    )
);

checkVersion($method->invoke(null, $base, array('uid' => 7), $actor, new FakeVersionRequest()) === 42
    && FakeVersionComments::$updates === array(),
    'An older remote reply Update must not overwrite the current comment');

$missing = $base;
unset($missing['object']['updated']);
checkVersion($method->invoke(null, $missing, array('uid' => 7), $actor, new FakeVersionRequest()) === 42
    && FakeVersionComments::$updates === array(),
    'A remote reply Update without a version must be ignored');

$newer = $base;
$newer['object']['updated'] = '2026-04-01T00:00:00Z';
$newer['object']['content'] = 'New content';
checkVersion($method->invoke(null, $newer, array('uid' => 7), $actor, new FakeVersionRequest()) === 42
    && count(FakeVersionComments::$updates) === 1
    && FakeVersionComments::$updates[0]['text'] === 'New content'
    && Typecho_Db::$db->versions[$hash] === strtotime('2026-04-01T00:00:00Z'),
    'A newer remote reply Update must update both the comment and its stored version');

fwrite(STDOUT, "reply-version: ok\n");
