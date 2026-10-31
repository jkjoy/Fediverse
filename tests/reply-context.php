<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class FakeContextQuery
{
    public string $table = '';
    public array $values = array();
    public function from($table) { $this->table = $table; return $this; }
    public function where($condition, ...$values) { $this->values[$condition] = $values; return $this; }
    public function limit($limit) { return $this; }
}

class FakeContextDb
{
    public array $remote = array();
    public array $local = array();
    public array $comments = array();
    public function select(...$fields) { return new FakeContextQuery(); }
    public function fetchRow($query)
    {
        if ($query->table === 'fediverse_reply_objects') {
            $objectId = (string)($query->values['object_id = ?'][0] ?? '');
            return isset($this->remote[$objectId]) ? array('comment_id' => $this->remote[$objectId]) : null;
        }
        if ($query->table === 'fediverse_local_comments') {
            $objectId = (string)($query->values['object_id = ?'][0] ?? '');
            return isset($this->local[$objectId]) ? array('coid' => $this->local[$objectId]) : null;
        }
        if ($query->table === 'table.comments') {
            return $this->comments[(int)($query->values['coid = ?'][0] ?? 0)] ?? null;
        }
        return null;
    }
}

class Typecho_Db
{
    public static FakeContextDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

require dirname(__DIR__) . '/lib/ActivityPub.php';

function checkContext($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

Typecho_Db::$db = new FakeContextDb();
Typecho_Db::$db->comments[41] = array('coid' => 41, 'cid' => 9, 'authorId' => 0);
Typecho_Db::$db->comments[42] = array('coid' => 42, 'cid' => 9, 'authorId' => 7);
Typecho_Db::$db->remote['https://remote.example/notes/parent'] = 41;
Typecho_Db::$db->local['https://blog.example/fediverse/comment/42'] = 42;

$method = new ReflectionMethod(Fediverse_ActivityPub::class, 'commentTargetForObject');
$remote = $method->invoke(null, 'https://remote.example/notes/parent');
$local = $method->invoke(null, 'https://blog.example/fediverse/comment/42');
checkContext($remote['coid'] === 41 && $remote['cid'] === 9,
    'A remote reply object must resolve to its Typecho parent comment');
checkContext($local['coid'] === 42 && $local['authorId'] === 7,
    'A federated local comment must resolve to its Typecho parent comment');
checkContext($method->invoke(null, 'https://remote.example/notes/missing') === null,
    'An unknown reply object must not create a false parent');

fwrite(STDOUT, "reply-context: ok\n");
