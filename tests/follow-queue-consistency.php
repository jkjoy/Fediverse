<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Common
{
    public static function subStr($text, $start, $length, $trim = '')
    {
        return substr((string)$text, (int)$start, (int)$length);
    }
}

class FakeFollowQuery
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

class FakeFollowDb
{
    public array $followings = array();
    public array $remoteActors = array();
    public function select(...$columns) { return new FakeFollowQuery(); }
    public function insert($table) { $query = new FakeFollowQuery(); $query->operation = 'insert'; $query->table = $table; return $query; }
    public function update($table) { $query = new FakeFollowQuery(); $query->operation = 'update'; $query->table = $table; return $query; }
    public function delete($table) { $query = new FakeFollowQuery(); $query->operation = 'delete'; $query->table = $table; return $query; }

    public function fetchRow($query)
    {
        if ($query->table === 'fediverse_followings') {
            foreach ($this->followings as $row) {
                if ((int)$row['uid'] === (int)($query->values['uid = ?'][0] ?? 0)
                    && (string)$row['actor_hash'] === (string)($query->values['actor_hash = ?'][0] ?? '')) {
                    return $row;
                }
            }
        }
        if ($query->table === 'fediverse_remote_actors') {
            return null;
        }
        return null;
    }

    public function query($query)
    {
        if ($query->operation === 'insert' && $query->table === 'fediverse_followings') {
            $query->rows['id'] = count($this->followings) + 1;
            $this->followings[] = $query->rows;
            return $query->rows['id'];
        }
        if ($query->operation === 'insert' && $query->table === 'fediverse_remote_actors') {
            $this->remoteActors[] = $query->rows;
            return 1;
        }
        if ($query->operation === 'delete' && $query->table === 'fediverse_followings') {
            $before = count($this->followings);
            $uid = (int)($query->values['uid = ?'][0] ?? 0);
            $hash = (string)($query->values['actor_hash = ?'][0] ?? '');
            $followId = (string)($query->values['follow_id = ?'][0] ?? '');
            $this->followings = array_values(array_filter($this->followings, static fn($row) =>
                !((int)$row['uid'] === $uid && (string)$row['actor_hash'] === $hash
                    && (string)$row['follow_id'] === $followId)));
            return $before - count($this->followings);
        }
        return 1;
    }
}

class Typecho_Db
{
    public static FakeFollowDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function install() {}
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static function isEnabled() { return true; }
    public static function userById($uid) { return array('uid' => (int)$uid, 'name' => 'alice'); }
    public static function userEnabled($uid) { return true; }
    public static function ensureActor($user) { return array('username' => 'alice'); }
    public static function actorUrl($username) { return 'https://blog.example/fediverse/author/' . $username; }
    public static function activityId($type) { return 'https://blog.example/fediverse/activity/' . $type . '-1'; }
}

class Fediverse_Http
{
    public static function getJson($url, $accept = null)
    {
        return array(
            'id' => 'https://remote.example/users/bob',
            'inbox' => 'https://remote.example/inbox',
            'preferredUsername' => 'bob',
            'name' => 'Bob'
        );
    }
}

class Fediverse_ActivityPub
{
    public const CONTEXT = 'https://www.w3.org/ns/activitystreams';
}

class Fediverse_Queue
{
    public static function enqueueDelivery($uid, $inbox, $activity)
    {
        throw new RuntimeException('queue unavailable');
    }
}

require dirname(__DIR__) . '/lib/Client.php';

Typecho_Db::$db = new FakeFollowDb();
try {
    Fediverse_Client::follow(7, 'https://remote.example/users/bob');
    fwrite(STDERR, "A failed queue insert must fail the follow operation\n");
    exit(1);
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'queue unavailable') {
        fwrite(STDERR, "The original queue failure must be preserved\n");
        exit(1);
    }
}
if (Typecho_Db::$db->followings !== array()) {
    fwrite(STDERR, "A failed queue insert must not leave an undeliverable pending follow\n");
    exit(1);
}

fwrite(STDOUT, "follow-queue-consistency: ok\n");
