<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Common
{
    public static function subStr($text, $start, $length, $trim = '') { return substr((string)$text, $start, $length); }
}

class FakeModerationQuery
{
    public string $operation;
    public string $table;
    public array $rows = array();
    public array $where = array();
    public function __construct($operation = 'select', $table = '') { $this->operation = $operation; $this->table = $table; }
    public function from($table) { $this->table = $table; return $this; }
    public function rows($rows) { $this->rows = $rows; return $this; }
    public function where($condition, ...$values) { $this->where[] = array($condition, $values); return $this; }
    public function limit($limit) { return $this; }
}

class FakeModerationDb
{
    public ?array $moderation = null;
    public array $boosts = array(array('tid' => 8));
    public array $queries = array();
    public function select(...$columns) { return new FakeModerationQuery(); }
    public function insert($table) { return new FakeModerationQuery('insert', $table); }
    public function update($table) { return new FakeModerationQuery('update', $table); }
    public function delete($table) { return new FakeModerationQuery('delete', $table); }
    public function fetchRow($query) { return $query->table === 'fediverse_moderation' ? $this->moderation : null; }
    public function fetchAll($query) { return $query->table === 'fediverse_timeline_meta' ? $this->boosts : array(); }
    public function query($query) { $this->queries[] = $query; return 1; }
}

class Typecho_Db
{
    public static FakeModerationDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

require dirname(__DIR__) . '/lib/Notifications.php';
require dirname(__DIR__) . '/lib/Client.php';

function checkModeration($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$actor = 'https://social.example/users/alice';
Typecho_Db::$db = new FakeModerationDb();
checkModeration(Fediverse_Client::setModeration(2, $actor, 'muted'), 'Muting must report a state change');
$operations = array_map(static fn($query) => $query->operation . ':' . $query->table, Typecho_Db::$db->queries);
checkModeration(in_array('insert:fediverse_moderation', $operations, true)
    && in_array('update:fediverse_timeline', $operations, true)
    && in_array('delete:fediverse_notifications', $operations, true),
    'Muting must persist state, hide cached posts, and remove notifications');
checkModeration(count(array_filter($operations, static fn($operation) => $operation === 'update:fediverse_timeline')) === 2,
    'Muting must also hide cached boosts from the actor');

Typecho_Db::$db = new FakeModerationDb();
Typecho_Db::$db->moderation = array('id' => 4, 'mode' => 'blocked');
checkModeration(Fediverse_Client::moderationMode(2, $actor) === 'blocked'
    && Fediverse_Client::isSuppressed(2, $actor), 'Blocked actors must be suppressed');
checkModeration(Fediverse_Client::setModeration(2, $actor, ''), 'Clearing moderation must remove existing state');
$last = end(Typecho_Db::$db->queries);
checkModeration($last->operation === 'delete' && $last->table === 'fediverse_moderation',
    'Clearing moderation must delete the stored rule');

try {
    Fediverse_Client::setModeration(2, 'http://social.example/users/alice', 'blocked');
    checkModeration(false, 'Non-HTTPS moderation targets must be rejected');
} catch (InvalidArgumentException $e) {
    checkModeration(true, 'Invalid actor rejected');
}
try {
    Fediverse_Client::setModeration(2, 'https://user:secret@social.example/users/alice', 'blocked');
    checkModeration(false, 'Credential-bearing actor URLs must be rejected');
} catch (InvalidArgumentException $e) {
    checkModeration(true, 'Credential-bearing actor rejected');
}

fwrite(STDOUT, "moderation: ok\n");
