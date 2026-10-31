<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Common
{
    public static function subStr($text, $start, $length, $trim = '')
    {
        return substr((string)$text, (int)$start, (int)$length);
    }
}

class FakeOrderingQuery
{
    public string $operation = 'select';
    public string $table = '';
    public array $values = array();
    public array $rows = array();
    public int $limitValue = 0;
    public bool $hasPredecessorJoin = false;
    public function from($table) { $this->table = $table; return $this; }
    public function join($table, $condition, $operation) { $this->hasPredecessorJoin = true; return $this; }
    public function where($condition, ...$values) { $this->values[$condition] = $values; return $this; }
    public function order($column, $direction) { return $this; }
    public function limit($limit) { $this->limitValue = (int)$limit; return $this; }
    public function rows($rows) { $this->rows = $rows; return $this; }
}

class FakeOrderingDb
{
    public array $queue = array();
    public array $blockedClaims = array();
    public $beforeClaim = null;
    public int $selections = 0;
    public function select(...$columns) { return new FakeOrderingQuery(); }
    public function update($table) { $query = new FakeOrderingQuery(); $query->operation = 'update'; $query->table = $table; return $query; }
    public function delete($table) { $query = new FakeOrderingQuery(); $query->operation = 'delete'; $query->table = $table; return $query; }

    public function fetchAll($query)
    {
        $this->selections++;
        if ($this->selections > 30) {
            throw new RuntimeException('Queue selection failed to advance');
        }
        $now = (int)($query->values['delivery.available <= ?'][0] ?? $query->values['available <= ?'][0] ?? time());
        $attempts = (int)($query->values['delivery.attempts < ?'][0] ?? $query->values['attempts < ?'][0] ?? 8);
        $cursor = (int)($query->values['delivery.qid > ?'][0] ?? 0);
        $rows = array_filter($this->queue, function ($row) use ($query, $now, $attempts, $cursor) {
            if ((int)$row['available'] > $now || (int)$row['attempts'] >= $attempts || (int)$row['qid'] <= $cursor) {
                return false;
            }
            if ($query->hasPredecessorJoin) {
                foreach ($this->queue as $earlier) {
                    if ((int)$earlier['uid'] === (int)$row['uid'] && $earlier['inbox'] === $row['inbox']
                        && (int)$earlier['qid'] < (int)$row['qid'] && (int)$earlier['attempts'] < 8) {
                        return false;
                    }
                }
            }
            return true;
        });
        usort($rows, static fn($a, $b) => (int)$a['qid'] <=> (int)$b['qid']);
        return array_values(array_slice($rows, 0, $query->limitValue));
    }

    public function fetchRow($query)
    {
        $uid = (int)($query->values['uid = ?'][0] ?? 0);
        $inbox = (string)($query->values['inbox = ?'][0] ?? '');
        $qid = (int)($query->values['qid < ?'][0] ?? 0);
        $attempts = (int)($query->values['attempts < ?'][0] ?? 8);
        foreach ($this->queue as $row) {
            if ((int)$row['uid'] === $uid && (string)$row['inbox'] === $inbox
                && (int)$row['qid'] < $qid && (int)$row['attempts'] < $attempts) {
                return array('qid' => (int)$row['qid']);
            }
        }
        return null;
    }

    public function query($query)
    {
        $qid = (int)($query->values['qid = ?'][0] ?? 0);
        if ($query->operation === 'update') {
            if (isset($query->values['available <= ?'])) {
                if ($this->beforeClaim) {
                    $callback = $this->beforeClaim;
                    $this->beforeClaim = null;
                    $callback($this, $qid);
                }
                if (isset($this->blockedClaims[$qid])) {
                    return 0;
                }
            }
            foreach ($this->queue as &$row) {
                if ((int)$row['qid'] !== $qid) {
                    continue;
                }
                if (isset($query->values['available <= ?'])
                    && (int)$row['available'] > (int)$query->values['available <= ?'][0]) {
                    return 0;
                }
                foreach (array('available', 'attempts') as $column) {
                    if (isset($query->values[$column . ' = ?'])
                        && (int)$row[$column] !== (int)$query->values[$column . ' = ?'][0]) {
                        return 0;
                    }
                }
                $row = array_replace($row, $query->rows);
                return 1;
            }
            unset($row);
            return 0;
        }
        if ($query->operation === 'delete') {
            $before = count($this->queue);
            $this->queue = array_values(array_filter($this->queue,
                static fn($row) => (int)$row['qid'] !== $qid));
            return $before - count($this->queue);
        }
        return 0;
    }
}

class Typecho_Db
{
    public const SORT_ASC = 'ASC';
    public const LEFT_JOIN = 'LEFT';
    public static FakeOrderingDb $db;
    public static function get() { return self::$db; }
}

class Fediverse_Database
{
    public static function table($name) { return 'fediverse_' . $name; }
}

class Fediverse_Core
{
    public static function isEnabled() { return true; }
    public static function setting($name, $default = null) { return $default; }
    public static function actorByUid($uid) { return array('username' => 'alice', 'private_key' => 'key'); }
}

class Fediverse_Http
{
    public static array $sent = array();
    public static array $failures = array();
    public static function signedPost($inbox, $activity, $actor)
    {
        if (isset(self::$failures[$activity['id']])) {
            throw new RuntimeException('Remote inbox unavailable');
        }
        self::$sent[] = array('inbox' => $inbox, 'activity' => $activity);
    }
}

require dirname(__DIR__) . '/lib/Queue.php';

function checkOrdering($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$now = time();
Typecho_Db::$db = new FakeOrderingDb();
Typecho_Db::$db->queue = array(
    array(
        'qid' => 1, 'uid' => 7, 'inbox' => 'https://remote.example/inbox',
        'activity' => json_encode(array('id' => 'https://blog.example/activities/create', 'type' => 'Create')),
        'attempts' => 1, 'available' => $now + 60
    ),
    array(
        'qid' => 2, 'uid' => 7, 'inbox' => 'https://remote.example/inbox',
        'activity' => json_encode(array('id' => 'https://blog.example/activities/update', 'type' => 'Update')),
        'attempts' => 0, 'available' => $now
    ),
    array(
        'qid' => 3, 'uid' => 7, 'inbox' => 'https://other.example/inbox',
        'activity' => json_encode(array('id' => 'https://blog.example/activities/other', 'type' => 'Create')),
        'attempts' => 0, 'available' => $now
    )
);

$result = Fediverse_Queue::run(1);
checkOrdering($result === array('processed' => 1, 'sent' => 1, 'failed' => 0),
    'Only an unblocked destination may be processed');
checkOrdering(count(Fediverse_Http::$sent) === 1
    && Fediverse_Http::$sent[0]['inbox'] === 'https://other.example/inbox',
    'A later delivery must not overtake an earlier task for the same local actor and inbox');
checkOrdering(count(Typecho_Db::$db->queue) === 2,
    'The backoff task and its dependent delivery must remain queued');

function orderingTask($qid, $inbox = 'https://remote.example/inbox', $attempts = 0, $available = null)
{
    return array(
        'qid' => $qid, 'uid' => 7, 'inbox' => $inbox,
        'activity' => json_encode(array('id' => 'https://blog.example/activities/' . $qid, 'type' => 'Create')),
        'attempts' => $attempts, 'available' => $available ?? time() - 10
    );
}

function resetOrdering($rows)
{
    Typecho_Db::$db = new FakeOrderingDb();
    Typecho_Db::$db->queue = $rows;
    Fediverse_Http::$sent = array();
    Fediverse_Http::$failures = array();
}

function sentOrderingIds()
{
    return array_column(array_column(Fediverse_Http::$sent, 'activity'), 'id');
}

resetOrdering(array(orderingTask(1), orderingTask(2), orderingTask(3), orderingTask(4)));
$result = Fediverse_Queue::run(3);
checkOrdering($result === array('processed' => 3, 'sent' => 3, 'failed' => 0)
    && sentOrderingIds() === array(
        'https://blog.example/activities/1', 'https://blog.example/activities/2', 'https://blog.example/activities/3'
    ) && count(Typecho_Db::$db->queue) === 1,
    'A batch must fill its budget with consecutive ordered deliveries to the same destination');

resetOrdering(array(orderingTask(1), orderingTask(2), orderingTask(3), orderingTask(4, 'https://other.example/inbox')));
$result = Fediverse_Queue::run(3);
checkOrdering($result['sent'] === 3 && sentOrderingIds() === array(
    'https://blog.example/activities/1', 'https://blog.example/activities/4', 'https://blog.example/activities/2'
), 'A later destination in the first page must not hide newly unblocked deliveries below the scan cursor');

resetOrdering(array(orderingTask(1), orderingTask(2), orderingTask(3, 'https://other.example/inbox')));
Fediverse_Http::$failures['https://blog.example/activities/1'] = true;
$result = Fediverse_Queue::run(3);
checkOrdering($result === array('processed' => 2, 'sent' => 1, 'failed' => 1)
    && sentOrderingIds() === array('https://blog.example/activities/3')
    && (int)Typecho_Db::$db->queue[0]['attempts'] === 1,
    'A failed predecessor must hold back dependents while unrelated destinations continue');

resetOrdering(array(orderingTask(1, 'https://remote.example/inbox', 8, time() + 300), orderingTask(2)));
$result = Fediverse_Queue::run(1);
checkOrdering($result['sent'] === 1 && sentOrderingIds() === array('https://blog.example/activities/2'),
    'A terminal task must not hold back later deliveries to its destination');

$otherAuthor = orderingTask(2);
$otherAuthor['uid'] = 8;
resetOrdering(array(orderingTask(1, 'https://remote.example/inbox', 1, time() + 300), $otherAuthor));
$result = Fediverse_Queue::run(1);
checkOrdering($result['sent'] === 1 && sentOrderingIds() === array('https://blog.example/activities/2'),
    'A backoff task must not block another local author using the same shared inbox');

resetOrdering(array(orderingTask(1), orderingTask(2, 'https://other.example/inbox')));
Typecho_Db::$db->blockedClaims[1] = true;
$result = Fediverse_Queue::run(1);
checkOrdering($result['sent'] === 1 && sentOrderingIds() === array('https://blog.example/activities/2'),
    'A lost claim must advance the cursor and allow other destinations to make progress');

resetOrdering(array(orderingTask(1)));
Typecho_Db::$db->blockedClaims[1] = true;
$result = Fediverse_Queue::run(3);
checkOrdering($result === array('processed' => 0, 'sent' => 0, 'failed' => 0)
    && Typecho_Db::$db->selections === 2,
    'A run with only lost claims must stop after a finite scan');

resetOrdering(array(orderingTask(1), orderingTask(2, 'https://other.example/inbox')));
Typecho_Db::$db->beforeClaim = static function ($db, $qid) {
    $db->queue[0]['attempts'] = 8;
    $db->queue[0]['available'] = time() - 1;
};
$result = Fediverse_Queue::run(1);
checkOrdering($result['sent'] === 1 && sentOrderingIds() === array('https://blog.example/activities/2')
    && (int)Typecho_Db::$db->queue[0]['attempts'] === 8,
    'A stale claim must preserve a newer terminal retry state and continue to another destination');

fwrite(STDOUT, "queue-ordering: ok\n");
