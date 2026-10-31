<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class FakeQueueModerationQuery
{
    public string $table = '';
    public array $rows = array();
    public function from($table) { $this->table = $table; return $this; }
    public function where($condition, ...$values) { return $this; }
    public function rows($rows) { $this->rows = $rows; return $this; }
}

class FakeQueueModerationDb
{
    public array $deliveries = array();
    public function select(...$columns) { return new FakeQueueModerationQuery(); }
    public function insert($table) { $query = new FakeQueueModerationQuery(); $query->table = $table; return $query; }
    public function fetchAll($query)
    {
        if ($query->table === 'fediverse_moderation') {
            return array(array('actor' => 'https://blocked.example/users/a'));
        }
        return array(
            array('actor' => 'https://blocked.example/users/a', 'inbox' => 'https://blocked.example/inbox', 'shared_inbox' => null),
            array('actor' => 'https://allowed.example/users/b', 'inbox' => 'https://allowed.example/inbox', 'shared_inbox' => null)
        );
    }
    public function query($query) { $this->deliveries[] = $query->rows; return 1; }
}

class Typecho_Db
{
    public static FakeQueueModerationDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static function json($value) { return json_encode($value); }
    public static function isEnabled() { return false; }
}

require dirname(__DIR__) . '/lib/Queue.php';

$db = new FakeQueueModerationDb();
Typecho_Db::$db = $db;
$count = Fediverse_Queue::enqueueActivity(2, array('id' => 'https://blog.example/activity/1', 'type' => 'Create'));
if ($count !== 1 || count($db->deliveries) !== 1
    || $db->deliveries[0]['inbox'] !== 'https://allowed.example/inbox') {
    fwrite(STDERR, "Blocked followers must be excluded from delivery fan-out\n");
    exit(1);
}

fwrite(STDOUT, "queue-moderation: ok\n");
