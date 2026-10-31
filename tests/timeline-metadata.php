<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Common
{
    public static function subStr($text, $start, $length, $trim = '') { return substr((string)$text, $start, $length); }
}

class FakeTimelineQuery
{
    public string $table = '';
    public array $rows = array();
    public function from($table) { $this->table = $table; return $this; }
    public function where($condition, ...$values) { return $this; }
    public function limit($limit) { return $this; }
    public function rows($rows) { $this->rows = $rows; return $this; }
}

class FakeTimelineDb
{
    public array $inserted = array();
    public ?array $timelineRow = null;
    public ?array $metaRow = null;
    public function select(...$columns) { return new FakeTimelineQuery(); }
    public function insert($table) { $query = new FakeTimelineQuery(); $query->table = $table; return $query; }
    public function update($table) { $query = new FakeTimelineQuery(); $query->table = $table; return $query; }
    public function fetchRow($query)
    {
        if ($query->table === 'fediverse_timeline') {
            return $this->timelineRow;
        }
        if ($query->table === 'fediverse_timeline_meta') {
            return $this->metaRow;
        }
        if ($query->table === 'fediverse_followings') {
            return array('state' => 'accepted', 'inbox' => 'https://booster.example/inbox');
        }
        return null;
    }
    public function query($query)
    {
        $this->inserted[$query->table][] = $query->rows;
        return $query->table === 'fediverse_timeline' ? 7 : 1;
    }
}

class Typecho_Db
{
    public static FakeTimelineDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static function json($value) { return json_encode($value, JSON_UNESCAPED_SLASHES); }
}

class Fediverse_ActivityPub
{
    public const CONTEXT = 'https://www.w3.org/ns/activitystreams';
    public const PUBLIC_AUDIENCE = 'https://www.w3.org/ns/activitystreams#Public';
}

require dirname(__DIR__) . '/lib/Client.php';

function checkTimelineMetadata($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

Typecho_Db::$db = new FakeTimelineDb();
$activity = array(
    'id' => 'https://booster.example/activities/1',
    'type' => 'Announce',
    'actor' => 'https://booster.example/users/alice',
    'to' => array(Fediverse_ActivityPub::PUBLIC_AUDIENCE),
    'object' => array(
        'id' => 'https://author.example/notes/10',
        'type' => 'Note',
        'attributedTo' => 'https://author.example/users/bob',
        'content' => '<p>Expanded remote post</p>',
        'published' => '2026-01-01T00:00:00Z',
        'updated' => '2026-02-01T00:00:00Z',
        'summary' => 'Remote warning',
        'sensitive' => true,
        'to' => array(Fediverse_ActivityPub::PUBLIC_AUDIENCE),
        'attachment' => array(
            array('type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://media.example/photo.jpg', 'name' => 'Alt text'),
            array('type' => 'Image', 'url' => 'http://media.example/unsafe.jpg')
        ),
        'tag' => array(array('type' => 'Hashtag', 'name' => '#ActivityPub', 'href' => 'https://tags.example/activitypub')),
        'oneOf' => array(
            array('type' => 'Note', 'name' => 'First choice', 'replies' => array('totalItems' => 3)),
            array('type' => 'Note', 'name' => 'Second choice', 'replies' => array('totalItems' => 2))
        ),
        'votersCount' => 5,
        'endTime' => '2099-01-01T00:00:00Z'
    )
);

$tid = Fediverse_Client::storeAnnounce($activity, array('uid' => 3), 'https://booster.example/users/alice');
checkTimelineMetadata($tid === 7, 'A followed actor announcement must be stored');
$timeline = Typecho_Db::$db->inserted['fediverse_timeline'][0];
checkTimelineMetadata($timeline['actor'] === 'https://author.example/users/bob'
    && $timeline['activity_id'] === 'https://booster.example/activities/1',
    'Timeline must keep the original author and the announcement activity');
$meta = Typecho_Db::$db->inserted['fediverse_timeline_meta'][0];
$attachments = json_decode($meta['attachments'], true);
$poll = json_decode($meta['poll'], true);
checkTimelineMetadata($meta['announced_actor'] === 'https://booster.example/users/alice'
    && $meta['summary'] === 'Remote warning' && $meta['sensitive'] === 1,
    'Announcement metadata must preserve booster and content warning');
checkTimelineMetadata(count($attachments) === 1 && $attachments[0]['name'] === 'Alt text',
    'Only safe HTTPS media metadata may be stored');
checkTimelineMetadata($meta['visibility'] === 'public' && count($poll['options']) === 2
    && $poll['options'][0]['votes'] === 3 && $poll['closed'] === false,
    'Timeline metadata must preserve visibility and bounded poll results');

$restricted = $activity;
$restricted['object']['to'] = array('https://author.example/users/bob/followers');
Typecho_Db::$db = new FakeTimelineDb();
checkTimelineMetadata(
    Fediverse_Client::storeAnnounce($restricted, array('uid' => 3), 'https://booster.example/users/alice') === 0,
    'Announcements must not expand follower-only objects'
);

$direct = new FakeTimelineDb();
$direct->timelineRow = array(
    'tid' => 9,
    'actor' => 'https://author.example/users/bob',
    'updated' => strtotime('2026-01-01T00:00:00Z')
);
$direct->metaRow = array('announced_actor' => null);
Typecho_Db::$db = $direct;
checkTimelineMetadata(
    Fediverse_Client::storeAnnounce($activity, array('uid' => 3), 'https://booster.example/users/alice') === 9
        && $direct->inserted === array(),
    'An announcement must not overwrite an existing directly received object'
);

$boosted = new FakeTimelineDb();
$boosted->timelineRow = array(
    'tid' => 11,
    'activity_id' => 'https://booster.example/activities/original',
    'actor' => 'https://author.example/users/bob',
    'updated' => strtotime('2026-01-01T00:00:00Z')
);
$boosted->metaRow = array('tid' => 11, 'announced_actor' => 'https://booster.example/users/alice');
Typecho_Db::$db = $boosted;
$update = $activity;
$update['id'] = 'https://author.example/activities/update';
$update['type'] = 'Update';
checkTimelineMetadata(
    Fediverse_Client::storeTimeline($update, array('uid' => 3), 'https://author.example/users/bob') === 11
        && $boosted->inserted['fediverse_timeline'][0]['activity_id']
            === 'https://booster.example/activities/original'
        && $boosted->inserted['fediverse_timeline_meta'][0]['announced_actor']
            === 'https://booster.example/users/alice',
    'An author update must preserve the announcement context and undo identity'
);

$stale = new FakeTimelineDb();
$stale->timelineRow = array(
    'tid' => 12,
    'activity_id' => 'https://author.example/activities/current',
    'actor' => 'https://author.example/users/bob',
    'updated' => strtotime('2026-03-01T00:00:00Z')
);
$stale->metaRow = array('tid' => 12, 'announced_actor' => null);
Typecho_Db::$db = $stale;
$olderUpdate = $update;
$olderUpdate['object']['updated'] = '2026-02-01T00:00:00Z';
checkTimelineMetadata(
    Fediverse_Client::storeTimeline($olderUpdate, array('uid' => 3), 'https://author.example/users/bob') === 12
        && $stale->inserted === array(),
    'An older Update must not roll timeline content back'
);

$missingVersion = $olderUpdate;
unset($missingVersion['object']['updated']);
checkTimelineMetadata(
    Fediverse_Client::storeTimeline($missingVersion, array('uid' => 3), 'https://author.example/users/bob') === 12
        && $stale->inserted === array(),
    'An Update without a valid version must not overwrite timeline content'
);

$narrowed = new FakeTimelineDb();
$narrowed->timelineRow = array(
    'tid' => 14,
    'activity_id' => 'https://author.example/activities/current',
    'actor' => 'https://author.example/users/bob',
    'updated' => strtotime('2026-03-01T00:00:00Z')
);
$narrowed->metaRow = array('tid' => 14, 'announced_actor' => null);
Typecho_Db::$db = $narrowed;
$privateUpdate = $update;
$privateUpdate['object']['updated'] = '2026-04-01T00:00:00Z';
$privateUpdate['object']['to'] = array('https://elsewhere.example/users/carol');
checkTimelineMetadata(
    Fediverse_Client::storeTimeline($privateUpdate, array('uid' => 3), 'https://author.example/users/bob') === 14
        && $narrowed->inserted['fediverse_timeline'][0]['deleted'] === 1
        && !isset($narrowed->inserted['fediverse_timeline'][0]['content']),
    'An Update that removes the local audience must hide cached content without storing its new body'
);

$firstBoost = new FakeTimelineDb();
$firstBoost->timelineRow = array(
    'tid' => 15,
    'activity_id' => 'https://booster.example/activities/original',
    'actor' => 'https://author.example/users/bob',
    'updated' => strtotime('2026-02-01T00:00:00Z')
);
$firstBoost->metaRow = array('tid' => 15, 'announced_actor' => 'https://booster.example/users/alice');
Typecho_Db::$db = $firstBoost;
checkTimelineMetadata(
    Fediverse_Client::storeAnnounce($activity, array('uid' => 3), 'https://second.example/users/eve') === 15
        && $firstBoost->inserted === array(),
    'A later announcement must not replace the first stored announcement and its Undo identity'
);

$collision = new FakeTimelineDb();
$collision->timelineRow = array(
    'tid' => 13,
    'actor' => 'https://victim.example/users/alice',
    'updated' => strtotime('2026-01-01T00:00:00Z')
);
Typecho_Db::$db = $collision;
$foreign = $update;
$foreign['object']['attributedTo'] = 'https://attacker.example/users/eve';
checkTimelineMetadata(
    Fediverse_Client::storeTimeline($foreign, array('uid' => 3), 'https://attacker.example/users/eve') === 0
        && $collision->inserted === array(),
    'A different actor must not reuse an object ID to overwrite timeline content'
);

Typecho_Db::$db = new FakeTimelineDb();
$unattributed = $activity;
unset($unattributed['object']['attributedTo']);
checkTimelineMetadata(
    Fediverse_Client::storeTimeline($unattributed, array('uid' => 3), 'https://author.example/users/bob') === 0,
    'Timeline objects must declare their author explicitly'
);

fwrite(STDOUT, "timeline-metadata: ok\n");
