<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Common
{
    public static function subStr($text, $start, $length, $trim = '') { return substr((string)$text, $start, $length); }
}

class Typecho_Config
{
    public function __construct(private array $values) {}
    public function __get($name) { return $this->values[$name] ?? null; }
}

class Widget_Upload
{
    public static function attachmentHandle($attachment) { return 'https://cdn.example/' . ltrim($attachment->path, '/'); }
}

class Typecho_Router
{
    public static function url($route, $params, $index) { return 'https://blog.example/tag/' . rawurlencode($params['slug']) . '/'; }
}

class FakeMetadataQuery
{
    public string $table = '';
    public function from($table) { $this->table = $table; return $this; }
    public function where($condition, ...$values) { return $this; }
    public function order($column, $direction) { return $this; }
    public function limit($limit) { return $this; }
    public function join($table, $condition) { return $this; }
}

class FakeMetadataDb
{
    public function select(...$columns) { return new FakeMetadataQuery(); }
    public function fetchAll($query)
    {
        if ($query->table === 'table.fields') {
            return array(
                array('name' => 'fediverse_cw', 'type' => 'str', 'str_value' => 'Spoiler warning'),
                array('name' => 'fediverse_sensitive', 'type' => 'int', 'int_value' => 1)
            );
        }
        if ($query->table === 'table.contents') {
            return array(array('text' => json_encode(array(
                'path' => '/photo.jpg',
                'mime' => 'image/jpeg',
                'description' => 'A useful alternative description'
            ))));
        }
        if ($query->table === 'table.metas') {
            return array(array('name' => 'Typecho', 'slug' => 'typecho'));
        }
        return array();
    }
}

class Typecho_Db
{
    public const SORT_ASC = 'ASC';
    public static function get() { return new FakeMetadataDb(); }
}

class FakeMetadataArchive
{
    public string $type = 'post';
    public string $status = 'publish';
    public string $password = '';
    public int $authorId = 1;
    public int $cid = 42;
    public string $title = 'Structured post';
    public string $content = '<p>Hello <a href="https://social.example/users/bob">@bob@social.example</a></p>';
    public string $permalink = 'https://blog.example/post/42';
    public int $created = 1700000000;
    public int $modified = 1700000100;
    public int $commentsNum = 0;
    public function have() { return true; }
}

class Typecho_Widget
{
    public static function widget(...$args) { return new FakeMetadataArchive(); }
}

class Fediverse_Core
{
    public static function userById($uid) { return array('uid' => $uid); }
    public static function userEnabled($uid) { return true; }
    public static function ensureActor($user) { return array('username' => 'alice'); }
    public static function actorUrl($username) { return 'https://blog.example/fediverse/author/' . $username; }
    public static function objectUrl($cid) { return 'https://blog.example/fediverse/post/' . $cid; }
    public static function iso8601($timestamp) { return gmdate('Y-m-d\TH:i:s\Z', $timestamp); }
    public static function options() { return (object)array('index' => 'https://blog.example/'); }
}

require dirname(__DIR__) . '/lib/ActivityPub.php';

function checkMetadata($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$note = Fediverse_ActivityPub::noteForPost(42);
checkMetadata($note['summary'] === 'Spoiler warning' && $note['sensitive'] === true,
    'Explicit content warning fields must produce summary and sensitive');
checkMetadata($note['attachment'][0]['type'] === 'Image'
    && $note['attachment'][0]['mediaType'] === 'image/jpeg'
    && $note['attachment'][0]['name'] === 'A useful alternative description',
    'Typecho attachments must retain media type and alternative text');
checkMetadata($note['tag'][0]['type'] === 'Hashtag' && $note['tag'][0]['name'] === '#Typecho',
    'Typecho tags must become ActivityPub hashtags');
if (class_exists('DOMDocument')) {
    checkMetadata($note['tag'][1]['type'] === 'Mention' && $note['tag'][1]['name'] === '@bob@social.example',
        'Linked Fediverse accounts must become structured mentions');
}

fwrite(STDOUT, "post-metadata: ok\n");
