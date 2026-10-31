<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Fediverse_Comments
{
    public static function enqueue($coid, $operation = 'create', $allowPendingApproval = false)
    {
        $coid = (int)$coid;
        if ($coid <= 0 || !Fediverse_Core::isEnabled()) {
            return 0;
        }
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('local_comments');
        $mapping = $db->fetchRow($db->select()->from($table)->where('coid = ?', $coid)->limit(1));
        if ($operation === 'delete') {
            return $mapping ? self::enqueueDelete($mapping) : 0;
        }
        if ($operation === 'create' && $mapping && (int)$mapping['deleted'] === 0) {
            return 0;
        }
        $effectiveOperation = $operation === 'update' && $mapping && (int)$mapping['deleted'] === 0
            ? 'update'
            : 'create';
        $wasDeleted = $mapping && (int)$mapping['deleted'] === 1;

        $comment = $db->fetchRow($db->select()->from('table.comments')->where('coid = ?', $coid)->limit(1));
        if (!self::isEligible($comment, $allowPendingApproval)) {
            return 0;
        }
        if (!self::isPublicPost((int)$comment['cid'])) {
            return 0;
        }
        $uid = (int)$comment['authorId'];
        $actor = Fediverse_Core::actorByUid($uid);
        if (!$actor) {
            return 0;
        }

        $target = self::replyTarget($comment);
        $now = time();
        $rows = array(
            'uid' => $uid,
            'object_id' => Fediverse_Core::url('fediverse/comment/' . $coid),
            'in_reply_to' => $target['object_id'],
            'to_actor' => $target['actor'] !== '' ? $target['actor'] : null,
            'deleted' => 0,
            'updated' => $now
        );
        if ($mapping) {
            $rows['created'] = (int)$mapping['created'];
            $mapping = array_replace($mapping, $rows);
        } else {
            $rows['coid'] = $coid;
            $rows['created'] = $now;
            $mapping = $rows;
        }

        $object = self::buildObject($comment, $mapping);
        $activityId = (string)$mapping['object_id'] . '#create';
        if ($effectiveOperation === 'update') {
            $activityId = Fediverse_Core::activityId('comment-update-' . $coid);
        } elseif ($wasDeleted) {
            $activityId = Fediverse_Core::activityId('comment-create-' . $coid);
        }
        $activity = array(
            '@context' => Fediverse_ActivityPub::CONTEXT,
            'id' => $activityId,
            'type' => $effectiveOperation === 'update' ? 'Update' : 'Create',
            'actor' => $object['attributedTo'],
            'published' => Fediverse_Core::iso8601($now),
            'to' => $object['to'],
            'cc' => $object['cc'],
            'object' => $object
        );
        $deliveries = self::deliver($uid, $activity, (string)($mapping['to_actor'] ?? ''));
        if (isset($rows['coid'])) {
            $db->query($db->insert($table)->rows($rows));
        } else {
            $db->query($db->update($table)->rows($rows)->where('coid = ?', $coid));
        }
        return $deliveries;
    }

    public static function object($coid)
    {
        $db = Typecho_Db::get();
        $mapping = $db->fetchRow($db->select()->from(Fediverse_Database::table('local_comments'))
            ->where('coid = ?', (int)$coid)->where('deleted = ?', 0)->limit(1));
        if (!$mapping) {
            return null;
        }
        $comment = $db->fetchRow($db->select()->from('table.comments')
            ->where('coid = ?', (int)$coid)->where('status = ?', 'approved')->limit(1));
        return self::isEligible($comment, false) && self::isPublicPost((int)$comment['cid'])
            ? self::buildObject($comment, $mapping) : null;
    }

    public static function findInserted($comment)
    {
        if (!is_array($comment) || empty($comment['cid'])) {
            return 0;
        }
        $db = Typecho_Db::get();
        $query = $db->select('coid')->from('table.comments')
            ->where('cid = ?', (int)$comment['cid'])
            ->where('authorId = ?', (int)($comment['authorId'] ?? 0))
            ->where('created = ?', (int)($comment['created'] ?? 0))
            ->where('parent = ?', (int)($comment['parent'] ?? 0))
            ->where('text = ?', (string)($comment['text'] ?? ''))
            ->order('coid', Typecho_Db::SORT_DESC)->limit(1);
        $row = $db->fetchRow($query);
        return (int)($row['coid'] ?? 0);
    }

    private static function isEligible($comment, $allowPendingApproval)
    {
        return is_array($comment)
            && (string)($comment['type'] ?? '') === 'comment'
            && strcasecmp((string)($comment['agent'] ?? ''), 'ActivityPub') !== 0
            && (int)($comment['authorId'] ?? 0) > 0
            && ($allowPendingApproval || (string)($comment['status'] ?? '') === 'approved')
            && Fediverse_Core::userEnabled((int)$comment['authorId']);
    }

    private static function isPublicPost($cid)
    {
        $db = Typecho_Db::get();
        $post = $db->fetchRow($db->select('cid', 'status', 'password')->from('table.contents')
            ->where('cid = ?', (int)$cid)->where('type = ?', 'post')->limit(1));
        return $post && (string)$post['status'] === 'publish'
            && (string)($post['password'] ?? '') === '';
    }

    private static function replyTarget($comment)
    {
        $parent = (int)($comment['parent'] ?? 0);
        if ($parent > 0) {
            $db = Typecho_Db::get();
            $local = $db->fetchRow($db->select('object_id')->from(Fediverse_Database::table('local_comments'))
                ->where('coid = ?', $parent)->where('deleted = ?', 0)->limit(1));
            if ($local) {
                return array('object_id' => (string)$local['object_id'], 'actor' => '');
            }
            $remote = $db->fetchRow($db->select('object_id', 'actor')
                ->from(Fediverse_Database::table('reply_objects'))
                ->where('comment_id = ?', $parent)->limit(1));
            if ($remote) {
                return array('object_id' => (string)$remote['object_id'], 'actor' => (string)$remote['actor']);
            }
        }
        return array('object_id' => Fediverse_Core::objectUrl((int)$comment['cid']), 'actor' => '');
    }

    private static function buildObject($comment, $mapping)
    {
        $actor = Fediverse_Core::actorByUid((int)$mapping['uid']);
        if (!$actor) {
            return null;
        }
        $actorId = Fediverse_Core::actorUrl($actor['username']);
        $toActor = (string)($mapping['to_actor'] ?? '');
        $to = $toActor !== '' ? array($toActor) : array(Fediverse_ActivityPub::PUBLIC_AUDIENCE);
        $cc = $toActor !== ''
            ? array(Fediverse_ActivityPub::PUBLIC_AUDIENCE, $actorId . '/followers')
            : array($actorId . '/followers');
        $content = nl2br(htmlspecialchars((string)$comment['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        return array(
            'id' => (string)$mapping['object_id'],
            'type' => 'Note',
            'attributedTo' => $actorId,
            'content' => '<p>' . $content . '</p>',
            'mediaType' => 'text/html',
            'url' => (string)$mapping['object_id'],
            'inReplyTo' => (string)$mapping['in_reply_to'],
            'published' => Fediverse_Core::iso8601((int)$comment['created']),
            'updated' => Fediverse_Core::iso8601((int)($mapping['updated'] ?? $comment['created'])),
            'to' => $to,
            'cc' => $cc,
            'sensitive' => false
        );
    }

    private static function enqueueDelete($mapping)
    {
        if ((int)($mapping['deleted'] ?? 0) === 1) {
            return 0;
        }
        $actor = Fediverse_Core::actorByUid((int)$mapping['uid']);
        if (!$actor) {
            return 0;
        }
        $actorId = Fediverse_Core::actorUrl($actor['username']);
        $toActor = (string)($mapping['to_actor'] ?? '');
        $activity = array(
            '@context' => Fediverse_ActivityPub::CONTEXT,
            'id' => Fediverse_Core::activityId('comment-delete-' . (int)$mapping['coid']),
            'type' => 'Delete',
            'actor' => $actorId,
            'to' => $toActor !== '' ? array($toActor) : array(Fediverse_ActivityPub::PUBLIC_AUDIENCE),
            'cc' => array($actorId . '/followers'),
            'object' => array('id' => (string)$mapping['object_id'], 'type' => 'Tombstone')
        );
        $count = self::deliver((int)$mapping['uid'], $activity, $toActor);
        $db = Typecho_Db::get();
        $db->query($db->update(Fediverse_Database::table('local_comments'))->rows(array(
            'deleted' => 1,
            'updated' => time()
        ))->where('coid = ?', (int)$mapping['coid']));
        return $count;
    }

    private static function deliver($uid, $activity, $toActor)
    {
        $inboxes = array();
        if ($toActor !== '') {
            $blocked = Fediverse_Client::moderationMode((int)$uid, $toActor) === 'blocked';
            if (!$blocked || (string)$activity['type'] === 'Delete') {
                $db = Typecho_Db::get();
                $alreadyFollowing = $db->fetchRow($db->select('id')->from(Fediverse_Database::table('followers'))
                    ->where('uid = ?', (int)$uid)->where('actor = ?', $toActor)
                    ->where('state = ?', 'accepted')->limit(1));
                if (!$alreadyFollowing || $blocked) {
                    $remote = Fediverse_Client::resolveActor($toActor);
                    $inbox = trim((string)($remote['inbox'] ?? ''));
                    if ($inbox === '') {
                        throw new RuntimeException('Comment recipient inbox is unavailable');
                    }
                    $inboxes[] = $inbox;
                }
            }
        }
        return Fediverse_Queue::enqueueActivity((int)$uid, $activity, $inboxes);
    }
}
