<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Common
{
    public static function subStr($text, $start, $length, $trim = '')
    {
        return function_exists('mb_substr')
            ? mb_substr((string)$text, (int)$start, (int)$length)
            : substr((string)$text, (int)$start, (int)$length);
    }
}

class Fediverse_Client
{
    public static $profile = array();
    public static $calls = 0;

    public static function resolveActor($actorId)
    {
        self::$calls++;
        if (self::$profile instanceof Throwable) {
            throw self::$profile;
        }
        return self::$profile;
    }
}

require dirname(__DIR__) . '/lib/ActivityPub.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$method = new ReflectionMethod(Fediverse_ActivityPub::class, 'replyAuthorName');
$actorId = 'https://social.example/users/alice';
$invoke = static function ($activity, $object) use ($method, $actorId) {
    return $method->invoke(null, $activity, $object, $actorId);
};

Fediverse_Client::$calls = 0;
assertSameValue(
    'Alice & Bob',
    $invoke(
        array('actor' => array('id' => $actorId, 'name' => '<b>Alice &amp; Bob</b>')),
        array('name' => 'This is the note title', 'attributedTo' => $actorId)
    ),
    'Embedded actor nickname should win and be plain text'
);
assertSameValue(0, Fediverse_Client::$calls, 'Embedded nickname should avoid another Actor request');

Fediverse_Client::$profile = array('name' => 'Remote nickname', 'username' => 'alice');
assertSameValue(
    'Remote nickname',
    $invoke(array('actor' => $actorId), array('name' => 'Wrong article title', 'attributedTo' => $actorId)),
    'Resolved Actor nickname should be used'
);

Fediverse_Client::$profile = new RuntimeException('profile unavailable');
assertSameValue(
    'alice',
    $invoke(
        array('actor' => array('id' => $actorId, 'preferredUsername' => 'alice')),
        array('name' => 'Wrong note title', 'attributedTo' => $actorId)
    ),
    'Preferred username should be used when profile resolution fails'
);

$reflection = new ReflectionClass(Fediverse_ActivityPub::class);
assertSameValue(
    'approved',
    $reflection->getConstant('REMOTE_COMMENT_STATUS'),
    'Remote replies should be approved immediately'
);

fwrite(STDOUT, "reply-author-name: ok\n");
