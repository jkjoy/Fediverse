<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Common
{
    public static function subStr($text, $start, $length, $trim = '')
    {
        return substr((string)$text, (int)$start, (int)$length);
    }
}

class FakeQuery
{
    public array $where = array();
    public array $rows = array();

    public function from($table)
    {
        return $this;
    }

    public function where($condition, ...$values)
    {
        $this->where[] = array($condition, $values);
        return $this;
    }

    public function rows($rows)
    {
        $this->rows = $rows;
        return $this;
    }
}

class FakeDb
{
    public array $counts = array();
    public array $inserted = array();

    public function select(...$fields)
    {
        return new FakeQuery();
    }

    public function insert($table)
    {
        return new FakeQuery();
    }

    public function fetchRow($query)
    {
        return array('num' => array_shift($this->counts));
    }

    public function query($query)
    {
        $this->inserted[] = $query->rows;
        return 1;
    }
}

class Typecho_Db
{
    public static FakeDb $db;

    public static function get()
    {
        return self::$db;
    }
}

class Fediverse_Database
{
    public static function table($name)
    {
        return 'fediverse_' . $name;
    }
}

class Fediverse_Core
{
    public static array $settings = array();

    public static function setting($name, $default = null)
    {
        return self::$settings[$name] ?? $default;
    }
}

class FakeContent
{
    public function have()
    {
        return true;
    }
}

class Typecho_Widget_Exception extends Exception
{
}

class Typecho_Widget
{
    public static function widget(...$args)
    {
        return new FakeContent();
    }
}

class FakePlugin
{
    public static bool $reject = false;

    public function filter($component, $comment, $content)
    {
        if (self::$reject) {
            throw new Typecho_Widget_Exception('rejected');
        }
        $comment['cid'] = 999;
        $comment['type'] = 'pingback';
        $comment['agent'] = 'Other';
        $comment['text'] = 'filtered';
        $comment['status'] = 'spam';
        return $comment;
    }
}

class Typecho_Plugin
{
    public static function factory($handle)
    {
        return new FakePlugin();
    }
}

require dirname(__DIR__) . '/lib/ActivityPub.php';

function assertInbound($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$reflection = new ReflectionClass(Fediverse_ActivityPub::class);
assertInbound($reflection->getConstant('REMOTE_COMMENT_STATUS') === 'waiting', 'Remote replies must wait for moderation');

$filter = $reflection->getMethod('filterRemoteComment');
$filtered = $filter->invoke(null, array('cid' => 42, 'text' => 'original', 'status' => 'waiting'), 42);
assertInbound($filtered['cid'] === 42, 'Comment filter must not change the target post');
assertInbound($filtered['type'] === 'comment', 'Comment filter must not change the comment type');
assertInbound($filtered['agent'] === 'ActivityPub', 'Comment filter must preserve the ActivityPub source');
assertInbound($filtered['text'] === 'filtered' && $filtered['status'] === 'spam', 'Comment filter output must be applied');

FakePlugin::$reject = true;
try {
    $filter->invoke(null, array('cid' => 42, 'text' => 'blocked', 'status' => 'waiting'), 42);
    assertInbound(false, 'Rejected comments must not be inserted');
} catch (InvalidArgumentException $e) {
    assertInbound(str_contains($e->getMessage(), 'rejected'), 'Comment filter rejection must be reported');
}
FakePlugin::$reject = false;

Typecho_Db::$db = new FakeDb();
Typecho_Db::$db->counts = array(29, 99, 1999, 9999);
$enforce = $reflection->getMethod('enforceInboundLimits');
$enforce->invoke(null, 'https://Social.Example./users/alice');
assertInbound(Typecho_Db::$db->counts === array(), 'All enabled inbound limits must be checked');

Typecho_Db::$db->counts = array(30);
try {
    $enforce->invoke(null, 'https://social.example/users/alice');
    assertInbound(false, 'Actor rate limit must reject the next activity');
} catch (Fediverse_InboundLimitException $e) {
    assertInbound(str_contains($e->getMessage(), 'Actor'), 'Actor limit must report its scope');
}

Fediverse_Core::$settings = array(
    'inboundActorRateLimit' => 0,
    'inboundDomainRateLimit' => 0,
    'inboundActorStorageLimit' => 0,
    'inboundDomainStorageLimit' => 0
);
Typecho_Db::$db->counts = array();
$enforce->invoke(null, 'https://social.example/users/alice');
assertInbound(Typecho_Db::$db->counts === array(), 'Disabled inbound limits must not query counters');

$sourceDomain = $reflection->getMethod('sourceDomain');
assertInbound(
    $sourceDomain->invoke(null, 'https://Social.Example./users/alice') === 'social.example',
    'Source domains must be normalized'
);

fwrite(STDOUT, "inbound-controls: ok\n");
