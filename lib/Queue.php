<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Fediverse_Queue
{
    public static function enqueuePost($cid, $operation)
    {
        $cid = (int)$cid;
        $forceCreate = $operation === 'resend';
        $db = Typecho_Db::get();
        $postTable = Fediverse_Database::table('posts');
        $tracked = $db->fetchRow($db->select()->from($postTable)->where('cid = ?', $cid)->limit(1));

        if ($operation === 'delete') {
            $row = $db->fetchRow($db->select('authorId')->from('table.contents')->where('cid = ?', $cid)->limit(1));
            $uid = (int)($tracked['uid'] ?? $row['authorId'] ?? 0);
            $actor = $uid ? Fediverse_Core::actorByUid($uid) : null;
            if (!$actor) {
                return 0;
            }
            $objectId = (string)($tracked['object_id'] ?? Fediverse_Core::objectUrl($cid));
            $activity = array(
                '@context' => Fediverse_ActivityPub::CONTEXT,
                'id' => Fediverse_Core::activityId('delete-' . $cid),
                'type' => 'Delete',
                'actor' => Fediverse_Core::actorUrl($actor['username']),
                'to' => array(Fediverse_ActivityPub::PUBLIC_AUDIENCE),
                'object' => array('id' => $objectId, 'type' => 'Tombstone')
            );
            $deliveries = self::fanOut($uid, $activity);
            $db->query($db->delete($postTable)->where('cid = ?', $cid));
            return $deliveries;
        }

        $note = Fediverse_ActivityPub::noteForPost($cid);
        if (!$note) {
            return 0;
        }
        $row = $db->fetchRow($db->select('authorId')->from('table.contents')->where('cid = ?', $cid)->limit(1));
        $uid = (int)$row['authorId'];
        $actor = Fediverse_Core::actorByUid($uid);
        if (!$actor) {
            return 0;
        }
        $hash = hash('sha256', Fediverse_Core::json($note));
        if (!$forceCreate && $tracked && hash_equals((string)$tracked['content_hash'], $hash)) {
            return 0;
        }
        $type = $forceCreate ? 'Create' : ($tracked ? 'Update' : 'Create');
        $activity = array(
            '@context' => Fediverse_ActivityPub::CONTEXT,
            'id' => Fediverse_Core::activityId(strtolower($type) . '-' . $cid),
            'type' => $type,
            'actor' => Fediverse_Core::actorUrl($actor['username']),
            'published' => Fediverse_Core::iso8601(time()),
            'to' => $note['to'],
            'cc' => $note['cc'],
            'object' => $note
        );
        $deliveries = self::fanOut($uid, $activity);
        if ($tracked) {
            $db->query($db->update($postTable)->rows(array(
                'object_id' => $note['id'], 'content_hash' => $hash, 'updated' => time()
            ))->where('cid = ?', $cid));
        } else {
            $db->query($db->insert($postTable)->rows(array(
                'cid' => $cid, 'uid' => $uid, 'object_id' => $note['id'], 'content_hash' => $hash,
                'published' => time(), 'updated' => time()
            )));
        }
        return $deliveries;
    }

    public static function enqueueActorUpdate($uid)
    {
        $uid = (int)$uid;
        $user = Fediverse_Core::userById($uid);
        if (!$user || !Fediverse_Core::userEnabled($uid)) {
            return 0;
        }
        return self::fanOut($uid, Fediverse_ActivityPub::actorUpdate($user));
    }

    public static function broadcastActorUpdates()
    {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll($db->select('uid')->from(Fediverse_Database::table('actors'))
            ->order('uid', Typecho_Db::SORT_ASC));
        $result = array('authors' => 0, 'deliveries' => 0);
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            if (!Fediverse_Core::userEnabled($uid)) {
                continue;
            }
            $result['authors']++;
            $result['deliveries'] += self::enqueueActorUpdate($uid);
        }
        return $result;
    }

    public static function resendRecentPosts($limit = 20)
    {
        $limit = max(1, min(100, (int)$limit));
        $db = Typecho_Db::get();
        $rows = $db->fetchAll($db->select('cid')->from('table.contents')
            ->where('type = ?', 'post')->where('status = ?', 'publish')
            ->where("password IS NULL OR password = ''")
            ->order('created', Typecho_Db::SORT_DESC)->limit($limit));
        $result = array('posts' => 0, 'deliveries' => 0);
        foreach ($rows as $row) {
            $result['posts']++;
            $result['deliveries'] += self::enqueuePost((int)$row['cid'], 'resend');
        }
        return $result;
    }

    public static function enqueueDelivery($uid, $inbox, $activity)
    {
        $db = Typecho_Db::get();
        $now = time();
        $db->query($db->insert(Fediverse_Database::table('queue'))->rows(array(
            'uid' => (int)$uid,
            'inbox' => (string)$inbox,
            'activity' => Fediverse_Core::json($activity),
            'attempts' => 0,
            'available' => $now,
            'last_error' => null,
            'created' => $now,
            'updated' => $now
        )));
    }

    public static function run($limit = null)
    {
        $limit = $limit === null ? (int)Fediverse_Core::setting('queueBatch', 20) : (int)$limit;
        $limit = max(1, min(100, $limit));
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('queue');
        $rows = $db->fetchAll($db->select()->from($table)
            ->where('available <= ?', time())->where('attempts < ?', 8)
            ->order('qid', Typecho_Db::SORT_ASC)->limit($limit));
        $result = array('processed' => 0, 'sent' => 0, 'failed' => 0);
        foreach ($rows as $row) {
            $claimed = $db->query($db->update($table)->rows(array(
                'available' => time() + 300,
                'updated' => time()
            ))->where('qid = ?', (int)$row['qid'])->where('available <= ?', time()));
            if (!$claimed) {
                continue;
            }
            $result['processed']++;
            try {
                $actor = Fediverse_Core::actorByUid((int)$row['uid']);
                $activity = json_decode((string)$row['activity'], true, 32);
                if (!$actor || !is_array($activity)) {
                    throw new RuntimeException('Queue actor or activity is unavailable');
                }
                Fediverse_Http::signedPost((string)$row['inbox'], $activity, $actor);
                $db->query($db->delete($table)->where('qid = ?', (int)$row['qid']));
                $result['sent']++;
            } catch (Throwable $e) {
                $attempts = (int)$row['attempts'] + 1;
                if ($attempts >= 8) {
                    $db->query($db->update($table)->rows(array(
                        'attempts' => 8,
                        'available' => 2147483647,
                        'last_error' => Typecho_Common::subStr($e->getMessage(), 0, 500, ''),
                        'updated' => time()
                    ))->where('qid = ?', (int)$row['qid']));
                } else {
                    $delay = min(21600, 60 * (2 ** ($attempts - 1)));
                    $db->query($db->update($table)->rows(array(
                        'attempts' => $attempts,
                        'available' => time() + $delay,
                        'last_error' => Typecho_Common::subStr($e->getMessage(), 0, 500, ''),
                        'updated' => time()
                    ))->where('qid = ?', (int)$row['qid']));
                }
                $result['failed']++;
            }
        }
        return $result;
    }

    public static function retry($ids)
    {
        $ids = self::normalizeIds($ids);
        if (!$ids) {
            return 0;
        }
        $db = Typecho_Db::get();
        return (int)$db->query($db->update(Fediverse_Database::table('queue'))->rows(array(
            'attempts' => 0,
            'available' => time(),
            'last_error' => null,
            'updated' => time()
        ))->where('qid IN ?', $ids));
    }

    public static function delete($ids)
    {
        $ids = self::normalizeIds($ids);
        if (!$ids) {
            return 0;
        }
        $db = Typecho_Db::get();
        return (int)$db->query($db->delete(Fediverse_Database::table('queue'))->where('qid IN ?', $ids));
    }

    private static function fanOut($uid, $activity)
    {
        $db = Typecho_Db::get();
        $followers = $db->fetchAll($db->select('inbox', 'shared_inbox')
            ->from(Fediverse_Database::table('followers'))
            ->where('uid = ?', (int)$uid)->where('state = ?', 'accepted'));
        $inboxes = array();
        foreach ($followers as $follower) {
            $inbox = trim((string)($follower['shared_inbox'] ?: $follower['inbox']));
            if ($inbox !== '') {
                $inboxes[$inbox] = true;
            }
        }
        foreach (array_keys($inboxes) as $inbox) {
            self::enqueueDelivery((int)$uid, $inbox, $activity);
        }
        return count($inboxes);
    }

    private static function normalizeIds($ids)
    {
        if (!is_array($ids)) {
            $ids = array($ids);
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    }
}
