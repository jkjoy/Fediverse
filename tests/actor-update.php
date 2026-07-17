<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Fediverse_Core
{
    public static function ensureActor($user)
    {
        return array(
            'username' => 'alice',
            'public_key' => 'PUBLIC KEY',
            'created' => 1700000000,
            'modified' => 1700000100
        );
    }

    public static function actorUrl($username)
    {
        return 'https://blog.example/fediverse/author/' . $username;
    }

    public static function personalSettings($uid)
    {
        return array(
            'summary' => 'Updated profile',
            'avatarUrl' => 'https://cdn.example/avatar.jpg',
            'headerUrl' => 'https://cdn.example/header.jpg'
        );
    }

    public static function setting($name, $default = null)
    {
        return $default;
    }

    public static function activityId($prefix)
    {
        return 'https://blog.example/fediverse/activity/' . $prefix . '-test';
    }

    public static function url($path)
    {
        return 'https://blog.example/' . ltrim((string)$path, '/');
    }

    public static function iso8601($timestamp)
    {
        return gmdate('Y-m-d\\TH:i:s\\Z', (int)$timestamp);
    }
}

require dirname(__DIR__) . '/lib/ActivityPub.php';

function checkValue($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$activity = Fediverse_ActivityPub::actorUpdate(array(
    'uid' => 1,
    'name' => 'alice',
    'screenName' => 'Alice',
    'url' => 'https://blog.example/author/alice/'
));

checkValue($activity['type'] === 'Update', 'Actor notification must use Update');
checkValue($activity['object']['type'] === 'Person', 'Update object must be a Person');
checkValue($activity['actor'] === $activity['object']['id'], 'Update actor must match Person id');
checkValue($activity['object']['name'] === 'Alice', 'Update must include the display name');
checkValue($activity['object']['updated'] === '2023-11-14T22:15:00Z', 'Update must include profile timestamp');
checkValue($activity['object']['icon']['url'] === 'https://cdn.example/avatar.jpg', 'Update must include avatar');
checkValue($activity['cc'][0] === $activity['actor'] . '/followers', 'Update must target followers');

fwrite(STDOUT, "actor-update: ok\n");
