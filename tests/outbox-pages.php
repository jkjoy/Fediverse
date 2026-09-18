<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Db
{
    public const SORT_DESC = 'DESC';

    public static function get()
    {
        return new self();
    }

    public function select(...$columns)
    {
        return new FakeOutboxQuery();
    }

    public function fetchRow($query)
    {
        return array('num' => 25);
    }

    public function fetchAll($query)
    {
        $start = ($query->page - 1) * $query->size + 1;
        $rows = array();
        for ($id = $start; $id < min($start + $query->size, 26); $id++) {
            $rows[] = array('cid' => $id);
        }
        return $rows;
    }
}

class FakeOutboxQuery
{
    public int $page = 1;
    public int $size = 20;

    public function from($table) { return $this; }
    public function where($condition, ...$args) { return $this; }
    public function order($column, $direction) { return $this; }
    public function page($page, $size) { $this->page = $page; $this->size = $size; return $this; }
}

class FakeOutboxArchive
{
    public string $type = 'post';
    public string $status = 'publish';
    public string $password = '';
    public int $authorId = 1;
    public string $title = 'Title';
    public string $content = 'Content';
    public string $permalink = 'https://blog.example/post';
    public int $created = 1700000000;
    public int $modified = 1700000000;
    public int $commentsNum = 0;
    public int $cid;

    public function have() { return true; }
}

class Typecho_Widget
{
    public static function widget($name, $params, $config)
    {
        $archive = new FakeOutboxArchive();
        $archive->cid = $config['cid'];
        return $archive;
    }
}

class Fediverse_Core
{
    public static function ensureActor($user) { return array('username' => 'alice'); }
    public static function actorUrl($username) { return 'https://blog.example/fediverse/author/' . $username; }
    public static function objectUrl($cid) { return 'https://blog.example/fediverse/post/' . $cid; }
    public static function userById($uid) { return array('uid' => $uid); }
    public static function userEnabled($uid) { return true; }
    public static function iso8601($timestamp) { return gmdate('Y-m-d\TH:i:s\Z', $timestamp); }
}

require dirname(__DIR__) . '/lib/ActivityPub.php';

function checkOutbox($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$user = array('uid' => 1);
$root = Fediverse_ActivityPub::outbox($user);
checkOutbox($root['type'] === 'OrderedCollection' && $root['totalItems'] === 25, 'Root collection must report total');
checkOutbox(count($root['orderedItems']) === 20 && str_ends_with($root['first'], '?page=1'),
    'Root must preserve the initial items and link to first page');
$first = Fediverse_ActivityPub::outbox($user, 1);
checkOutbox($first['type'] === 'OrderedCollectionPage' && str_ends_with($first['next'], '?page=2'),
    'First page must link to older posts');
$second = Fediverse_ActivityPub::outbox($user, 2);
checkOutbox(count($second['orderedItems']) === 5 && !isset($second['next'])
    && str_ends_with($second['prev'], '?page=1'), 'Last page must contain remaining posts');

fwrite(STDOUT, "outbox-pages: ok\n");
