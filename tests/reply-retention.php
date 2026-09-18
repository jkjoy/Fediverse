<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class FakeReplyQuery
{
    public string $table = '';
    public array $conditions = array();
    public array $values = array();
    public array $rows = array();

    public function from($table) { $this->table = $table; return $this; }
    public function where($condition, ...$values)
    {
        $this->conditions[] = $condition;
        $this->values[$condition] = $values;
        return $this;
    }
    public function order($column, $direction) { return $this; }
    public function limit($count) { return $this; }
    public function rows($rows) { $this->rows = $rows; return $this; }
}

class FakeReplyDb
{
    public array $activities = array();
    public array $replies = array();

    public function select(...$fields) { return new FakeReplyQuery(); }
    public function insert($table) { $query = new FakeReplyQuery(); $query->table = 'insert:' . $table; return $query; }
    public function update($table) { $query = new FakeReplyQuery(); $query->table = 'update:' . $table; return $query; }
    public function delete($table) { $query = new FakeReplyQuery(); $query->table = 'delete:' . $table; return $query; }
    public function sql() { return new FakeReplyQuery(); }

    public function fetchRow($query)
    {
        if ($query->table === 'fediverse_reply_objects') {
            return $this->replies[$query->values['object_hash = ?'][0]] ?? null;
        }
        foreach (array_reverse($this->activities) as $row) {
            if ($row['object_id'] === ($query->values['object_id = ?'][0] ?? null)
                && $row['actor'] === ($query->values['actor = ?'][0] ?? null)
                && in_array($row['type'], array('Create', 'Update'), true)
                && ($row['status'] === 'deleted' || str_starts_with($row['status'], 'comment:'))) {
                return $row;
            }
        }
        return null;
    }

    public function fetchAll($query)
    {
        return array_values(array_filter($this->activities, static fn($row) =>
            $row['aid'] > $query->values['aid > ?'][0]
            && $row['created'] < $query->values['created < ?'][0]
            && in_array($row['type'], array('Create', 'Update'), true)
            && ($row['status'] === 'deleted' || str_starts_with($row['status'], 'comment:'))));
    }

    public function query($query)
    {
        if ($query->table === 'insert:fediverse_reply_objects') {
            $this->replies[$query->rows['object_hash']] = $query->rows;
            return 1;
        }
        if ($query->table === 'update:fediverse_reply_objects') {
            $this->replies[$query->values['object_hash = ?'][0]]['comment_id'] = $query->rows['comment_id'];
            return 1;
        }
        if ($query->table === 'delete:fediverse_activities') {
            $before = count($this->activities);
            $this->activities = array_values(array_filter($this->activities, static fn($row) =>
                $row['created'] >= $query->values['created < ?'][0]
                || (in_array($row['type'], array('Like', 'Announce'), true)
                    && in_array($row['status'], array('accepted', 'undone'), true))));
            return $before - count($this->activities);
        }
        return 0;
    }
}

class Typecho_Db
{
    public const SORT_ASC = 'ASC';
    public const SORT_DESC = 'DESC';
    public static FakeReplyDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static function setting($name, $default = null) { return $default; }
}

class FakeCommentWidget
{
    public static array $deleted = array();
    public function delete($query) { self::$deleted[] = $query->values['coid = ?'][0]; }
}

class Typecho_Widget
{
    public static function widget($name) { return new FakeCommentWidget(); }
}

require dirname(__DIR__) . '/lib/ActivityPub.php';

function checkReply($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$actor = 'https://social.example/users/alice';
$old = time() - 100 * 86400;
Typecho_Db::$db = new FakeReplyDb();
foreach (array(
    array('Create', 'comment:42', 'https://social.example/notes/one'),
    array('Create', 'deleted', 'https://social.example/notes/two'),
    array('Like', 'accepted', 'https://blog.example/fediverse/post/1'),
    array('Like', 'undone', 'https://blog.example/fediverse/post/2'),
    array('Create', 'ignored', 'https://social.example/notes/other'),
    array('Update', 'comment:77', 'https://social.example/notes/three')
) as $index => $item) {
    Typecho_Db::$db->activities[] = array(
        'aid' => $index + 1, 'type' => $item[0], 'status' => $item[1],
        'object_id' => $item[2], 'actor' => $actor, 'created' => $old
    );
}

checkReply(Fediverse_ActivityPub::pruneLogs(90) === 4, 'Only non-stateful old activities may be pruned');
$record = new ReflectionMethod(Fediverse_ActivityPub::class, 'replyRecord');
checkReply($record->invoke(null, 'https://social.example/notes/one', $actor)['comment_id'] === 42,
    'Pruned replies must retain their local comment ID');
checkReply($record->invoke(null, 'https://social.example/notes/two', $actor)['comment_id'] === 0,
    'Deleted replies must retain their tombstone');
checkReply($record->invoke(null, 'https://social.example/notes/three', $actor)['comment_id'] === 77,
    'A retained Update must recover an original Create already pruned by an older version');
checkReply(count(Typecho_Db::$db->activities) === 2, 'Current and undone interactions must be retained');

$delete = new ReflectionMethod(Fediverse_ActivityPub::class, 'deleteReply');
$delete->invoke(null, 'https://social.example/notes/one', $actor);
checkReply(FakeCommentWidget::$deleted === array(42), 'Remote deletion must still delete a pruned reply');
checkReply($record->invoke(null, 'https://social.example/notes/one', $actor)['comment_id'] === 0,
    'Deleting a pruned reply must leave a tombstone');

$create = new ReflectionMethod(Fediverse_ActivityPub::class, 'createReply');
$activity = array('object' => array(
    'id' => 'https://social.example/notes/two', 'type' => 'Note',
    'inReplyTo' => 'https://blog.example/fediverse/post/1', 'content' => 'Duplicate'
));
checkReply($create->invoke(null, $activity, array('uid' => 1), $actor, new stdClass()) === 0,
    'A deleted remote reply must not be recreated after pruning');

fwrite(STDOUT, "reply-retention: ok\n");
