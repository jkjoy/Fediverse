<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Fediverse_Notifications
{
    public static function add($uid, $type, $actor, $activityId, $objectId = '', $resourceId = 0)
    {
        $uid = (int)$uid;
        $activityId = (string)$activityId;
        if ($uid <= 0 || $activityId === '') {
            return false;
        }
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('notifications');
        $hash = hash('sha256', $activityId);
        if ($db->fetchRow($db->select('nid')->from($table)
            ->where('uid = ?', $uid)->where('activity_hash = ?', $hash)->limit(1))) {
            return false;
        }
        $db->query($db->insert($table)->rows(array(
            'uid' => $uid,
            'type' => substr((string)$type, 0, 32),
            'actor' => (string)$actor,
            'activity_hash' => $hash,
            'activity_id' => $activityId,
            'object_id' => (string)$objectId !== '' ? (string)$objectId : null,
            'resource_id' => max(0, (int)$resourceId),
            'read_at' => null,
            'created' => time()
        )));
        return true;
    }

    public static function removeActivity($uid, $actorId, $activityId)
    {
        if ((int)$uid <= 0 || (string)$actorId === '' || (string)$activityId === '') {
            return 0;
        }
        $db = Typecho_Db::get();
        return (int)$db->query($db->delete(Fediverse_Database::table('notifications'))
            ->where('uid = ?', (int)$uid)->where('actor = ?', (string)$actorId)
            ->where('activity_hash = ?', hash('sha256', (string)$activityId)));
    }

    public static function removeObject($uid, $actorId, $objectId)
    {
        if ((int)$uid <= 0 || (string)$actorId === '' || (string)$objectId === '') {
            return 0;
        }
        $db = Typecho_Db::get();
        return (int)$db->query($db->delete(Fediverse_Database::table('notifications'))
            ->where('uid = ?', (int)$uid)->where('actor = ?', (string)$actorId)
            ->where('object_id = ?', (string)$objectId));
    }

    public static function removeActor($uid, $actorId)
    {
        if ((int)$uid <= 0 || (string)$actorId === '') {
            return 0;
        }
        $db = Typecho_Db::get();
        return (int)$db->query($db->delete(Fediverse_Database::table('notifications'))
            ->where('uid = ?', (int)$uid)->where('actor = ?', (string)$actorId));
    }

    public static function markAllRead()
    {
        $db = Typecho_Db::get();
        return (int)$db->query($db->update(Fediverse_Database::table('notifications'))
            ->rows(array('read_at' => time()))->where('read_at IS NULL'));
    }

    public static function prune($before)
    {
        $db = Typecho_Db::get();
        return (int)$db->query($db->delete(Fediverse_Database::table('notifications'))
            ->where('read_at IS NOT NULL')->where('created < ?', (int)$before));
    }
}
