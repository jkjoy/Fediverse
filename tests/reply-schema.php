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
    foreach (array('notifications', 'local_comments', 'timeline_meta', 'moderation', 'bookmarks', 'remote_actors',
        'reply_versions') as $table) {
        $created = array_filter(Typecho_Db::$db->queries, static fn($sql) =>
            str_contains($sql, 'CREATE TABLE IF NOT EXISTS')
            && str_contains($sql, 'tc_fediverse_' . $table));
        if (count($created) !== 1) {
            fwrite(STDERR, $adapter . ' ' . $table . ' table is missing' . PHP_EOL);
            exit(1);
        }
        if ($table === 'timeline_meta' && (!str_contains(reset($created), 'attachments')
            || !str_contains(reset($created), 'announced_actor') || !str_contains(reset($created), 'poll')
            || !str_contains(reset($created), 'visibility'))) {
            fwrite(STDERR, $adapter . ' timeline metadata columns are missing' . PHP_EOL);
            exit(1);
        }
        if ($table === 'moderation' && (!str_contains(reset($created), 'actor_hash')
            || !str_contains(reset($created), 'mode'))) {
            fwrite(STDERR, $adapter . ' moderation columns are missing' . PHP_EOL);
            exit(1);
        }
        if ($table === 'bookmarks' && (!str_contains(reset($created), 'uid')
            || !str_contains(reset($created), 'tid'))) {
            fwrite(STDERR, $adapter . ' bookmark columns are missing' . PHP_EOL);
            exit(1);
        }
        if ($table === 'remote_actors' && (!str_contains(reset($created), 'actor_hash')
            || !str_contains(reset($created), 'avatar_url') || !str_contains(reset($created), 'profile_url'))) {
            fwrite(STDERR, $adapter . ' remote actor columns are missing' . PHP_EOL);
            exit(1);
        }
        if ($table === 'reply_versions' && (!str_contains(reset($created), 'object_hash')
            || !str_contains(reset($created), 'updated'))) {
            fwrite(STDERR, $adapter . ' reply version columns are missing' . PHP_EOL);
            exit(1);
        }
    }
}

fwrite(STDOUT, "reply-schema: ok\n");
