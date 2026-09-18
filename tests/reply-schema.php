<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class FakeSchemaDb
{
    public string $adapter = '';
    public array $queries = array();

    public function getPrefix() { return 'tc_'; }
    public function getAdapterName() { return $this->adapter; }
    public function query($sql) { $this->queries[] = $sql; }
}

class Typecho_Db
{
    public static FakeSchemaDb $db;
    public static function get() { return self::$db; }
}

require dirname(__DIR__) . '/lib/Database.php';

foreach (array('Mysql', 'Pgsql', 'Sqlite') as $adapter) {
    Typecho_Db::$db = new FakeSchemaDb();
    Typecho_Db::$db->adapter = $adapter;
    Fediverse_Database::install();
    $matches = array_filter(Typecho_Db::$db->queries, static fn($sql) =>
        str_contains($sql, 'CREATE TABLE IF NOT EXISTS') && str_contains($sql, 'tc_fediverse_reply_objects'));
    if (count($matches) !== 1 || !str_contains(reset($matches), 'object_hash')
        || !str_contains(reset($matches), 'comment_id')) {
        fwrite(STDERR, $adapter . ' reply table is missing' . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, "reply-schema: ok\n");
