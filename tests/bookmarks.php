<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class FakeBookmarkQuery
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

class FakeBookmarkDb
{
    public bool $bookmarked = false;
    public array $queries = array();
    public function select(...$columns) { return new FakeBookmarkQuery(); }
    public function insert($table) { return new FakeBookmarkQuery('insert', $table); }
    public function delete($table) { return new FakeBookmarkQuery('delete', $table); }
    public function fetchRow($query)
    {
        if ($query->table === 'fediverse_timeline') {
            return array('tid' => 9, 'uid' => 3);
        }
        return $this->bookmarked ? array('tid' => 9) : null;
    }
    public function query($query)
    {
        $this->queries[] = $query;
        $this->bookmarked = $query->operation === 'insert';
        return 1;
    }
}

class Typecho_Db
{
    public static FakeBookmarkDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

require dirname(__DIR__) . '/lib/Client.php';

Typecho_Db::$db = new FakeBookmarkDb();
if (!Fediverse_Client::toggleBookmark(9)) {
    fwrite(STDERR, "Bookmark must be created\n");
    exit(1);
}
$insert = end(Typecho_Db::$db->queries);
if ($insert->operation !== 'insert' || $insert->table !== 'fediverse_bookmarks'
    || $insert->rows['uid'] !== 3 || $insert->rows['tid'] !== 9) {
    fwrite(STDERR, "Bookmark must use the timeline owner and item\n");
    exit(1);
}
if (Fediverse_Client::toggleBookmark(9)) {
    fwrite(STDERR, "Existing bookmark must be removed\n");
    exit(1);
}
$delete = end(Typecho_Db::$db->queries);
if ($delete->operation !== 'delete' || $delete->table !== 'fediverse_bookmarks') {
    fwrite(STDERR, "Bookmark removal must delete the local record\n");
    exit(1);
}

fwrite(STDOUT, "bookmarks: ok\n");
