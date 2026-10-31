<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class FakeClaimQuery
{
    public string $operation = 'select';
    public string $table = '';
    public array $fields = array();
    public array $values = array();
    public array $rows = array();

    public function from($table) { $this->table = $table; return $this; }
    public function where($condition, ...$values) { $this->values[$condition] = $values; return $this; }
    public function limit($limit) { return $this; }
    public function rows($rows) { $this->rows = $rows; return $this; }
}

class FakeClaimDb
{
    public array $activities = array();
    public array $inbound = array();

    public function select(...$fields)
    {
        $query = new FakeClaimQuery();
        $query->fields = $fields;
        return $query;
    }

    public function insert($table)
    {
        $query = new FakeClaimQuery();
        $query->operation = 'insert';
        $query->table = $table;
        return $query;
    }

    public function update($table)
    {
        $query = new FakeClaimQuery();
        $query->operation = 'update';
        $query->table = $table;
        return $query;
    }

    public function fetchRow($query)
    {
        if (isset($query->fields[0]) && is_array($query->fields[0])) {
            return array('num' => count($this->inbound));
        }
        if ($query->table === 'fediverse_activities') {
            $hash = (string)($query->values['activity_hash = ?'][0] ?? '');
            return $this->activities[$hash] ?? null;
        }
        return null;
    }

    public function query($query)
    {
        if ($query->operation === 'insert' && $query->table === 'fediverse_activities') {
            $hash = $query->rows['activity_hash'];
            if (isset($this->activities[$hash])) {
                throw new RuntimeException('unique activity_hash');
            }
            $query->rows['aid'] = count($this->activities) + 1;
            $this->activities[$hash] = $query->rows;
            return $query->rows['aid'];
        }
        if ($query->operation === 'insert' && $query->table === 'fediverse_inbound') {
            $hash = $query->rows['activity_hash'];
            if (isset($this->inbound[$hash])) {
                throw new RuntimeException('unique inbound activity_hash');
            }
            $this->inbound[$hash] = $query->rows;
            return 1;
        }
        if ($query->operation === 'update' && $query->table === 'fediverse_activities') {
            $hash = (string)($query->values['activity_hash = ?'][0] ?? '');
            if (!isset($this->activities[$hash])) {
                return 0;
            }
            $this->activities[$hash] = array_replace($this->activities[$hash], $query->rows);
            return 1;
        }
        return 0;
    }
}

class Typecho_Db
{
    public static FakeClaimDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static function json($value) { return json_encode($value, JSON_UNESCAPED_SLASHES); }
    public static function setting($name, $default = null) { return $default; }
    public static function userByUsername($username) { return null; }
    public static function userEnabled($uid) { return false; }
}

class Fediverse_Http
{
    public static int $verified = 0;
    public static function verifyIncoming($request, $rawBody, $actorId) { self::$verified++; }
}

require dirname(__DIR__) . '/lib/ActivityPub.php';

function checkClaim($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

Typecho_Db::$db = new FakeClaimDb();
$activity = array(
    'id' => 'https://remote.example/activities/failed-target',
    'type' => 'Ping',
    'actor' => 'https://remote.example/users/alice',
    'object' => 'https://blog.example/fediverse/author/missing'
);

try {
    Fediverse_ActivityPub::receive($activity, '{}', new stdClass(), 'missing');
    checkClaim(false, 'A missing local inbox target must be rejected');
} catch (InvalidArgumentException $e) {
    checkClaim(str_contains($e->getMessage(), 'target'), 'The target failure must remain visible');
}

$hash = hash('sha256', $activity['id']);
checkClaim(count(Typecho_Db::$db->activities) === 1
    && Typecho_Db::$db->activities[$hash]['status'] === 'rejected',
    'A verified but failed activity must keep a rejected claim');
checkClaim(count(Typecho_Db::$db->inbound) === 1,
    'A verified but failed activity must consume inbound quota');

$duplicate = Fediverse_ActivityPub::receive($activity, '{}', new stdClass(), 'missing');
checkClaim($duplicate === array('duplicate' => true),
    'The unique activity claim must reject a duplicate before side effects');
checkClaim(count(Typecho_Db::$db->activities) === 1 && count(Typecho_Db::$db->inbound) === 1,
    'A duplicate must not create another claim or quota record');
checkClaim(Fediverse_Http::$verified === 2, 'Every delivery must be verified before deduplication');

fwrite(STDOUT, "inbound-claim: ok\n");
