<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Common
{
    public static function subStr($text, $start, $length, $trim = '') { return substr((string)$text, $start, $length); }
}

class FakeRemoteActorQuery
{
    public string $operation;
    public string $table;
    public array $rows = array();
    public function __construct($operation = 'select', $table = '') { $this->operation = $operation; $this->table = $table; }
    public function from($table) { $this->table = $table; return $this; }
    public function rows($rows) { $this->rows = $rows; return $this; }
    public function where($condition, ...$values) { return $this; }
    public function limit($limit) { return $this; }
}

class FakeRemoteActorDb
{
    public bool $existing = false;
    public array $queries = array();
    public function select(...$columns) { return new FakeRemoteActorQuery(); }
    public function insert($table) { return new FakeRemoteActorQuery('insert', $table); }
    public function update($table) { return new FakeRemoteActorQuery('update', $table); }
    public function fetchRow($query) { return $this->existing ? array('actor_hash' => 'cached') : null; }
    public function query($query) { $this->queries[] = $query; $this->existing = true; return 1; }
}

class Typecho_Db
{
    public static FakeRemoteActorDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

require dirname(__DIR__) . '/lib/Client.php';

Typecho_Db::$db = new FakeRemoteActorDb();
$stored = Fediverse_Client::cacheRemoteActor(array(
    'id' => 'https://social.example/users/alice',
    'preferredUsername' => '<b>alice</b>',
    'name' => '<strong>Alice</strong>',
    'url' => array('href' => 'https://social.example/@alice'),
    'icon' => array('url' => 'https://cdn.example/alice.png')
));
$insert = end(Typecho_Db::$db->queries);
if (!$stored || $insert->operation !== 'insert' || $insert->table !== 'fediverse_remote_actors'
    || $insert->rows['username'] !== 'alice' || $insert->rows['name'] !== 'Alice'
    || $insert->rows['profile_url'] !== 'https://social.example/@alice'
    || $insert->rows['avatar_url'] !== 'https://cdn.example/alice.png') {
    fwrite(STDERR, "Remote actor metadata must be sanitized and cached\n");
    exit(1);
}

Fediverse_Client::cacheRemoteActor(array(
    'id' => 'https://social.example/users/alice',
    'icon' => 'https://user:secret@cdn.example/alice.png'
));
$update = end(Typecho_Db::$db->queries);
if ($update->operation !== 'update' || $update->rows['avatar_url'] !== null) {
    fwrite(STDERR, "Credential-bearing avatar URLs must not be cached\n");
    exit(1);
}

$before = count(Typecho_Db::$db->queries);
if (Fediverse_Client::cacheRemoteActor(array('id' => 'http://social.example/users/alice'))
    || count(Typecho_Db::$db->queries) !== $before) {
    fwrite(STDERR, "Non-HTTPS actors must not be cached\n");
    exit(1);
}

fwrite(STDOUT, "remote-actor-cache: ok\n");
