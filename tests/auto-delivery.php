<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class FakeAutoQuery
{
    public int $limitValue = 0;
    public function from($table) { return $this; }
    public function where($condition, ...$values) { return $this; }
    public function order($column, $direction) { return $this; }
    public function limit($limit) { $this->limitValue = (int)$limit; return $this; }
    public function rows($rows) { return $this; }
}

class FakeAutoDb
{
    public int $fetches = 0;
    public int $inserts = 0;
    public int $limit = 0;
    public function select(...$columns) { return new FakeAutoQuery(); }
    public function insert($table) { return new FakeAutoQuery(); }
    public function query($query) { $this->inserts++; return 1; }
    public function fetchAll($query) { $this->fetches++; $this->limit = $query->limitValue; return array(); }
}

class Typecho_Db
{
    public const SORT_ASC = 'ASC';
    public static FakeAutoDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static bool $enabled = true;
    public static array $settings = array('autoDelivery' => '0', 'autoDeliveryBatch' => 1);
    public static function isEnabled() { return self::$enabled; }
    public static function setting($name, $default = null) { return self::$settings[$name] ?? $default; }
    public static function json($value) { return json_encode($value); }
}

require dirname(__DIR__) . '/lib/Queue.php';

function checkAuto($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

Typecho_Db::$db = new FakeAutoDb();
$disabled = Fediverse_Queue::dispatch();
checkAuto($disabled === array('processed' => 0, 'sent' => 0, 'failed' => 0),
    'Disabled automatic delivery must be a no-op');
checkAuto(Typecho_Db::$db->fetches === 0, 'Disabled automatic delivery must not query the queue');
checkAuto(!Fediverse_Queue::scheduleAutoDispatch(), 'Disabled automatic delivery must not register a worker');

Fediverse_Core::$settings['autoDelivery'] = '1';
$empty = Fediverse_Queue::dispatch();
checkAuto($empty === array('processed' => 0, 'sent' => 0, 'failed' => 0),
    'An empty automatic run must report zero work');
checkAuto(Typecho_Db::$db->fetches === 1, 'Enabled automatic delivery must inspect the queue');
checkAuto(Typecho_Db::$db->limit === 1, 'Automatic delivery must default to one queue item');
Fediverse_Queue::enqueueDelivery(1, 'https://remote.example/inbox', array('type' => 'Follow'));
checkAuto(Typecho_Db::$db->inserts === 1, 'Enqueuing a delivery must persist it');
checkAuto(!Fediverse_Queue::scheduleAutoDispatch(), 'Only one worker may be registered per request');

fwrite(STDOUT, "auto-delivery: ok\n");
