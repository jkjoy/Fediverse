<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Fediverse_Client
{
    public static function resolveActor($identifier)
    {
        $identifier = trim((string)$identifier);
        if ($identifier === '') {
            throw new InvalidArgumentException('请输入远端账号或 Actor URL');
        }

        if (self::isHttpsUrl($identifier)) {
            $actorUrl = $identifier;
        } else {
            if (!preg_match('/^@?([^@\s]+)@([^@\s]+)$/', $identifier, $matches)) {
                throw new InvalidArgumentException('账号格式应为 @user@example.com');
            }
            $account = $matches[1] . '@' . strtolower($matches[2]);
            $origin = 'https://' . strtolower($matches[2]);
            $originParts = parse_url($origin);
            if (!filter_var($origin, FILTER_VALIDATE_URL) || empty($originParts['host'])
                || !empty($originParts['path']) || isset($originParts['query']) || isset($originParts['fragment'])) {
                throw new InvalidArgumentException('远端账号域名无效');
            }
            try {
                $webfinger = Fediverse_Http::getJson(
                    $origin . '/.well-known/webfinger?resource=' . rawurlencode('acct:' . $account),
                    'application/jrd+json, application/json'
                );
            } catch (RuntimeException $e) {
                throw new RuntimeException('WebFinger 请求失败：' . $e->getMessage(), 0, $e);
            }
            $actorUrl = '';
            foreach ((array)($webfinger['links'] ?? array()) as $link) {
                if (is_array($link) && ($link['rel'] ?? '') === 'self' && self::isHttpsUrl($link['href'] ?? '')) {
                    $actorUrl = (string)$link['href'];
                    if (str_contains(strtolower((string)($link['type'] ?? '')), 'activity')) {
                        break;
                    }
                }
            }
            if ($actorUrl === '') {
                throw new RuntimeException('WebFinger 未返回 ActivityPub Actor');
            }
        }

        try {
            $actor = Fediverse_Http::getJson($actorUrl);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Actor 获取失败：' . $e->getMessage(), 0, $e);
        }
        $id = self::remoteUrl($actor['id'] ?? '', 512);
        $inbox = self::remoteUrl($actor['inbox'] ?? '', 512);
        $shared = self::remoteUrl($actor['endpoints']['sharedInbox'] ?? '', 512);
        if ($id === '' || $inbox === '') {
            throw new RuntimeException('远端 Actor 缺少有效的 id 或 inbox');
        }
        $resolved = array(
            'id' => $id,
            'inbox' => $inbox,
            'shared_inbox' => $shared !== '' ? $shared : null,
            'username' => self::remoteText($actor['preferredUsername'] ?? '', 128),
            'name' => self::remoteText($actor['name'] ?? '', 255)
        );
        self::cacheRemoteActor($actor);
        return $resolved;
    }

    public static function cacheRemoteActor($document)
    {
        if (!is_array($document)) {
            return false;
        }
        $actor = self::remoteUrl($document['id'] ?? '', 512);
        if ($actor === '') {
            return false;
        }
        $profile = self::remoteUrlValue($document['url'] ?? '', 2048);
        $icon = $document['icon'] ?? '';
        $avatar = self::remoteUrlValue(is_array($icon) ? ($icon['url'] ?? $icon) : $icon, 2048);
        $rows = array(
            'actor' => $actor,
            'username' => self::remoteText($document['preferredUsername'] ?? '', 128),
            'name' => self::remoteText($document['name'] ?? '', 255),
            'avatar_url' => $avatar !== '' ? $avatar : null,
            'profile_url' => $profile !== '' ? $profile : $actor,
            'updated' => time()
        );
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('remote_actors');
        $hash = hash('sha256', $actor);
        $existing = $db->fetchRow($db->select('actor_hash')->from($table)
            ->where('actor_hash = ?', $hash)->limit(1));
        if ($existing) {
            $db->query($db->update($table)->rows($rows)->where('actor_hash = ?', $hash));
        } else {
            $rows['actor_hash'] = $hash;
            $db->query($db->insert($table)->rows($rows));
        }
        return true;
    }

    public static function follow($uid, $identifier)
    {
        if (!Fediverse_Core::isEnabled()) {
            throw new RuntimeException('请先启用插件并设置有效的联邦域名');
        }
        Fediverse_Database::install();
        $uid = (int)$uid;
        $user = Fediverse_Core::userById($uid);
        if (!$user || !Fediverse_Core::userEnabled($uid)) {
            throw new InvalidArgumentException('本地作者不存在或已停用');
        }
        $localActor = Fediverse_Core::ensureActor($user);
        $localId = Fediverse_Core::actorUrl($localActor['username']);
        if (!self::isHttpsUrl($localId)) {
            throw new InvalidArgumentException('本站必须启用 HTTPS 后才能关注远端账号');
        }
        $remote = self::resolveActor($identifier);
        if (rtrim($remote['id'], '/') === rtrim($localId, '/')) {
            throw new InvalidArgumentException('不能关注自己的联邦账号');
        }

        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('followings');
        $hash = hash('sha256', $remote['id']);
        $existing = $db->fetchRow($db->select()->from($table)
            ->where('uid = ?', $uid)->where('actor_hash = ?', $hash)->limit(1));
        if ($existing && in_array((string)$existing['state'], array('pending', 'accepted'), true)) {
            return array('created' => false, 'state' => (string)$existing['state'], 'actor' => $remote['id']);
        }

        $followId = Fediverse_Core::activityId('follow');
        $activity = array(
            '@context' => Fediverse_ActivityPub::CONTEXT,
            'id' => $followId,
            'type' => 'Follow',
            'actor' => $localId,
            'object' => $remote['id'],
            'to' => array($remote['id'])
        );
        if ($existing) {
            $db->query($db->delete($table)->where('id = ?', (int)$existing['id']));
        }
        $now = time();
        $db->query($db->insert($table)->rows(array(
            'uid' => $uid,
            'actor_hash' => $hash,
            'actor' => $remote['id'],
            'inbox' => $remote['inbox'],
            'shared_inbox' => $remote['shared_inbox'],
            'follow_id' => $followId,
            'state' => 'pending',
            'created' => $now,
            'updated' => $now
        )));
        try {
            Fediverse_Queue::enqueueDelivery($uid, $remote['inbox'], $activity);
        } catch (Throwable $e) {
            $db->query($db->delete($table)->where('uid = ?', $uid)
                ->where('actor_hash = ?', $hash)->where('follow_id = ?', $followId));
            throw $e;
        }
        return array('created' => true, 'state' => 'pending', 'actor' => $remote['id']);
    }

    public static function unfollow($id)
    {
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('followings');
        $row = $db->fetchRow($db->select()->from($table)->where('id = ?', (int)$id)->limit(1));
        if (!$row) {
            return 0;
        }
        if ((string)$row['state'] !== 'rejected') {
            $actor = Fediverse_Core::actorByUid((int)$row['uid']);
            if (!$actor) {
                throw new RuntimeException('本地作者身份不可用');
            }
            $localId = Fediverse_Core::actorUrl($actor['username']);
            $follow = array(
                'id' => (string)$row['follow_id'],
                'type' => 'Follow',
                'actor' => $localId,
                'object' => (string)$row['actor'],
                'to' => array((string)$row['actor'])
            );
            $undo = array(
                '@context' => Fediverse_ActivityPub::CONTEXT,
                'id' => Fediverse_Core::activityId('undo-follow'),
                'type' => 'Undo',
                'actor' => $localId,
                'object' => $follow,
                'to' => array((string)$row['actor'])
            );
            Fediverse_Queue::enqueueDelivery((int)$row['uid'], (string)$row['inbox'], $undo);
        }
        $db->query($db->delete($table)->where('id = ?', (int)$row['id']));
        return 1;
    }

    public static function handleFollowResponse($activity, $user, $actorId, $state)
    {
        $object = $activity['object'] ?? null;
        $followId = is_array($object) ? (string)($object['id'] ?? '') : (string)$object;
        if ($followId === '') {
            return false;
        }
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('followings');
        $row = $db->fetchRow($db->select()->from($table)
            ->where('uid = ?', (int)$user['uid'])->where('follow_id = ?', $followId)->limit(1));
        if (!$row || rtrim((string)$row['actor'], '/') !== rtrim($actorId, '/')) {
            return false;
        }
        if (is_array($object)) {
            $localActor = is_array($object['actor'] ?? null)
                ? (string)($object['actor']['id'] ?? '')
                : (string)($object['actor'] ?? '');
            $target = is_array($object['object'] ?? null)
                ? (string)($object['object']['id'] ?? '')
                : (string)($object['object'] ?? '');
            $actor = Fediverse_Core::actorByUid((int)$user['uid']);
            $localId = $actor ? Fediverse_Core::actorUrl($actor['username']) : '';
            if (rtrim($localActor, '/') !== rtrim($localId, '/') || rtrim($target, '/') !== rtrim($actorId, '/')) {
                return false;
            }
        }
        $db->query($db->update($table)->rows(array('state' => (string)$state, 'updated' => time()))
            ->where('id = ?', (int)$row['id']));
        return true;
    }

    public static function setModeration($uid, $actorId, $mode)
    {
        $uid = (int)$uid;
        $actorId = trim((string)$actorId);
        $mode = (string)$mode;
        $actorParts = parse_url($actorId);
        if ($uid <= 0 || strlen($actorId) > 512 || !self::isHttpsUrl($actorId)
            || !is_array($actorParts) || isset($actorParts['user']) || isset($actorParts['pass'])) {
            throw new InvalidArgumentException('远端 Actor 地址无效');
        }
        if (!in_array($mode, array('', 'muted', 'blocked'), true)) {
            throw new InvalidArgumentException('不支持的管理状态');
        }
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('moderation');
        $hash = hash('sha256', $actorId);
        $existing = $db->fetchRow($db->select('id')->from($table)
            ->where('uid = ?', $uid)->where('actor_hash = ?', $hash)->limit(1));
        if ($mode === '') {
            if (!$existing) {
                return false;
            }
            $db->query($db->delete($table)->where('id = ?', (int)$existing['id']));
            return true;
        }
        $now = time();
        $rows = array('actor' => $actorId, 'mode' => $mode, 'updated' => $now);
        if ($existing) {
            $db->query($db->update($table)->rows($rows)->where('id = ?', (int)$existing['id']));
        } else {
            $rows += array('uid' => $uid, 'actor_hash' => $hash, 'created' => $now);
            $db->query($db->insert($table)->rows($rows));
        }
        $db->query($db->update(Fediverse_Database::table('timeline'))->rows(array(
            'deleted' => 1,
            'updated' => $now
        ))->where('uid = ?', $uid)->where('actor = ?', $actorId));
        $boosts = $db->fetchAll($db->select('tid')->from(Fediverse_Database::table('timeline_meta'))
            ->where('announced_actor = ?', $actorId));
        $boostIds = array_map('intval', array_column($boosts, 'tid'));
        if ($boostIds) {
            $db->query($db->update(Fediverse_Database::table('timeline'))->rows(array(
                'deleted' => 1,
                'updated' => $now
            ))->where('uid = ?', $uid)->where('tid IN ?', $boostIds));
        }
        Fediverse_Notifications::removeActor($uid, $actorId);
        return true;
    }

    public static function moderationMode($uid, $actorId)
    {
        if ((int)$uid <= 0 || strlen((string)$actorId) > 512 || !self::isHttpsUrl($actorId)) {
            return '';
        }
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('mode')->from(Fediverse_Database::table('moderation'))
            ->where('uid = ?', (int)$uid)->where('actor_hash = ?', hash('sha256', (string)$actorId))->limit(1));
        return in_array((string)($row['mode'] ?? ''), array('muted', 'blocked'), true)
            ? (string)$row['mode'] : '';
    }

    public static function isSuppressed($uid, $actorId)
    {
        return self::moderationMode($uid, $actorId) !== '';
    }

    public static function storeTimeline($activity, $user, $actorId, $announcedBy = '')
    {
        $object = $activity['object'] ?? null;
        if (!is_array($object) || !in_array((string)($object['type'] ?? ''), array('Note', 'Article'), true)) {
            return 0;
        }
        if (self::isSuppressed((int)$user['uid'], (string)$actorId)
            || ($announcedBy !== '' && self::isSuppressed((int)$user['uid'], (string)$announcedBy))) {
            return 0;
        }
        $objectId = (string)($object['id'] ?? '');
        $attributedTo = self::attributedActor($object['attributedTo'] ?? null);
        if (!self::isHttpsUrl($objectId) || !self::isHttpsUrl($attributedTo)
            || rtrim($attributedTo, '/') !== rtrim($actorId, '/')) {
            return 0;
        }
        $title = self::plainText((string)($object['name'] ?? ''));
        $content = self::plainText((string)($object['content'] ?? ''));
        if ($title !== '' && $title !== $content) {
            $content = trim($title . ($content !== '' ? "\n\n" . $content : ''));
            $content = Typecho_Common::subStr($content, 0, 20000, '');
        }
        if ($content === '') {
            return 0;
        }
        $url = self::objectUrl($object, $objectId);
        $published = isset($object['published']) ? (strtotime((string)$object['published']) ?: time()) : time();
        $modified = isset($object['updated']) ? strtotime((string)$object['updated']) : false;
        $modified = $modified !== false ? $modified : $published;
        $hash = hash('sha256', (int)$user['uid'] . "\0" . $objectId);
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('timeline');
        $existing = $db->fetchRow($db->select()->from($table)->where('object_hash = ?', $hash)->limit(1));
        if ($existing && rtrim((string)($existing['actor'] ?? ''), '/') !== rtrim($actorId, '/')) {
            return 0;
        }
        if ($existing && (string)($activity['type'] ?? '') === 'Update') {
            $remoteModified = isset($object['updated']) ? strtotime((string)$object['updated']) : false;
            if ($remoteModified === false || $remoteModified < (int)($existing['updated'] ?? 0)) {
                return (int)$existing['tid'];
            }
            $modified = $remoteModified;
            $visibility = self::timelineVisibility($activity, $object, $actorId);
            if (!in_array($visibility, array('public', 'unlisted'), true)
                && !($visibility === 'followers' && self::isFollowed((int)$user['uid'], $actorId))) {
                $db->query($db->update($table)->rows(array(
                    'updated' => $modified,
                    'deleted' => 1
                ))->where('tid = ?', (int)$existing['tid']));
                return (int)$existing['tid'];
            }
        }
        $existingMeta = $existing ? $db->fetchRow($db->select('announced_actor')
            ->from(Fediverse_Database::table('timeline_meta'))
            ->where('tid = ?', (int)$existing['tid'])->limit(1)) : null;
        if ($existing && $announcedBy !== '') {
            return (int)$existing['tid'];
        }
        $preserveAnnounce = $existing && $announcedBy === '' && (string)($activity['type'] ?? '') === 'Update'
            && (string)($existingMeta['announced_actor'] ?? '') !== '';
        if ($preserveAnnounce) {
            $announcedBy = (string)$existingMeta['announced_actor'];
        }
        $deliveryActor = $announcedBy !== '' ? (string)$announcedBy : (string)$actorId;
        if (!$existing && (!self::isFollowed((int)$user['uid'], $deliveryActor)
            || !self::hasTimelineAudience($activity, $object, $actorId))) {
            return 0;
        }
        $rows = array(
            'activity_id' => $preserveAnnounce ? (string)$existing['activity_id'] : (string)$activity['id'],
            'object_id' => $objectId,
            'actor' => $actorId,
            'object_type' => (string)$object['type'],
            'url' => $url,
            'content' => $content,
            'published' => $published,
            'updated' => $modified,
            'deleted' => 0
        );
        if ($existing) {
            $db->query($db->update($table)->rows($rows)->where('tid = ?', (int)$existing['tid']));
            self::storeTimelineMeta((int)$existing['tid'], $activity, $object, $actorId, $announcedBy);
            return (int)$existing['tid'];
        }
        $rows['uid'] = (int)$user['uid'];
        $rows['object_hash'] = $hash;
        $rows['liked_activity'] = null;
        $rows['announced_activity'] = null;
        $tid = (int)$db->query($db->insert($table)->rows($rows));
        if ($tid > 0) {
            self::storeTimelineMeta($tid, $activity, $object, $actorId, $announcedBy);
        }
        return $tid;
    }

    public static function storeAnnounce($activity, $user, $actorId)
    {
        $object = $activity['object'] ?? null;
        if (is_string($object)) {
            if (!self::isHttpsUrl($object)) {
                return 0;
            }
            try {
                $resolved = Fediverse_Http::getJson($object);
            } catch (Throwable $e) {
                return 0;
            }
            if (rtrim((string)($resolved['id'] ?? ''), '/') !== rtrim($object, '/')) {
                return 0;
            }
            $object = $resolved;
        }
        if (!is_array($object) || !in_array((string)($object['type'] ?? ''), array('Note', 'Article'), true)) {
            return 0;
        }
        $attributedTo = self::attributedActor($object['attributedTo'] ?? null);
        if (!self::isHttpsUrl($attributedTo)) {
            return 0;
        }
        if (!in_array(self::timelineVisibility(array(), $object, $attributedTo), array('public', 'unlisted'), true)) {
            return 0;
        }
        $expanded = $activity;
        $expanded['object'] = $object;
        return self::storeTimeline($expanded, $user, $attributedTo, (string)$actorId);
    }

    public static function undoAnnounce($activityId, $user, $actorId)
    {
        if ((string)$activityId === '') {
            return 0;
        }
        $db = Typecho_Db::get();
        $timeline = $db->fetchRow($db->select('tid')->from(Fediverse_Database::table('timeline'))
            ->where('uid = ?', (int)$user['uid'])->where('activity_id = ?', (string)$activityId)->limit(1));
        if (!$timeline) {
            return 0;
        }
        $meta = $db->fetchRow($db->select('announced_actor')->from(Fediverse_Database::table('timeline_meta'))
            ->where('tid = ?', (int)$timeline['tid'])->limit(1));
        if (!$meta || rtrim((string)$meta['announced_actor'], '/') !== rtrim((string)$actorId, '/')) {
            return 0;
        }
        return (int)$db->query($db->update(Fediverse_Database::table('timeline'))->rows(array(
            'deleted' => 1,
            'updated' => time()
        ))->where('tid = ?', (int)$timeline['tid']));
    }

    public static function deleteTimeline($objectId, $user, $actorId)
    {
        if ((string)$objectId === '') {
            return 0;
        }
        $db = Typecho_Db::get();
        return (int)$db->query($db->update(Fediverse_Database::table('timeline'))->rows(array(
            'deleted' => 1,
            'updated' => time()
        ))->where('uid = ?', (int)$user['uid'])->where('object_id = ?', (string)$objectId)
            ->where('actor = ?', (string)$actorId));
    }

    public static function usersFollowing($actorId, $includeTimeline = false)
    {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll($db->select('uid')->from(Fediverse_Database::table('followings'))
            ->where('actor_hash = ?', hash('sha256', (string)$actorId))->where('state = ?', 'accepted'));
        $users = array();
        foreach ($rows as $row) {
            $user = Fediverse_Core::userById((int)$row['uid']);
            if ($user && Fediverse_Core::userEnabled((int)$row['uid'])) {
                $users[(int)$row['uid']] = $user;
            }
        }
        if ($includeTimeline) {
            $rows = $db->fetchAll($db->select('uid')->from(Fediverse_Database::table('timeline'))
                ->where('actor = ?', (string)$actorId)->group('uid'));
            foreach ($rows as $row) {
                $user = Fediverse_Core::userById((int)$row['uid']);
                if ($user && Fediverse_Core::userEnabled((int)$row['uid'])) {
                    $users[(int)$row['uid']] = $user;
                }
            }
        }
        return array_values($users);
    }

    public static function pruneTimeline($days = null)
    {
        $days = $days === null ? (int)Fediverse_Core::setting('activityRetentionDays', 90) : (int)$days;
        $days = max(7, min(3650, $days));
        $db = Typecho_Db::get();
        $before = time() - ($days * 86400);
        $rows = $db->fetchAll($db->select('tid')->from(Fediverse_Database::table('timeline'))
            ->where('published < ?', $before));
        $tids = array_map('intval', array_column($rows, 'tid'));
        if ($tids) {
            $db->query($db->delete(Fediverse_Database::table('timeline_meta'))->where('tid IN ?', $tids));
            $db->query($db->delete(Fediverse_Database::table('bookmarks'))->where('tid IN ?', $tids));
        }
        return (int)$db->query($db->delete(Fediverse_Database::table('timeline'))
            ->where('published < ?', $before));
    }

    public static function toggleBookmark($tid)
    {
        $db = Typecho_Db::get();
        $timeline = $db->fetchRow($db->select('tid', 'uid')->from(Fediverse_Database::table('timeline'))
            ->where('tid = ?', (int)$tid)->where('deleted = ?', 0)->limit(1));
        if (!$timeline) {
            throw new InvalidArgumentException('时间轴内容不存在');
        }
        $table = Fediverse_Database::table('bookmarks');
        $existing = $db->fetchRow($db->select('tid')->from($table)
            ->where('uid = ?', (int)$timeline['uid'])->where('tid = ?', (int)$timeline['tid'])->limit(1));
        if ($existing) {
            $db->query($db->delete($table)->where('uid = ?', (int)$timeline['uid'])
                ->where('tid = ?', (int)$timeline['tid']));
            return false;
        }
        $db->query($db->insert($table)->rows(array(
            'uid' => (int)$timeline['uid'],
            'tid' => (int)$timeline['tid'],
            'created' => time()
        )));
        return true;
    }

    public static function toggleInteraction($tid, $type, $undo = false)
    {
        if (!in_array($type, array('Like', 'Announce'), true)) {
            throw new InvalidArgumentException('不支持的互动类型');
        }
        $db = Typecho_Db::get();
        $timeline = $db->fetchRow($db->select()->from(Fediverse_Database::table('timeline'))
            ->where('tid = ?', (int)$tid)->where('deleted = ?', 0)->limit(1));
        if (!$timeline) {
            throw new InvalidArgumentException('时间轴内容不存在');
        }
        if ($type === 'Announce' && (string)(self::timelineMeta((int)$timeline['tid'])['visibility'] ?? '') !== 'public') {
            throw new RuntimeException('只能转发公开内容');
        }
        $following = self::deliveryForTimeline($timeline);
        $actor = Fediverse_Core::actorByUid((int)$timeline['uid']);
        if (!$actor) {
            throw new RuntimeException('本地作者身份不可用');
        }
        $localId = Fediverse_Core::actorUrl($actor['username']);
        $field = $type === 'Like' ? 'liked_activity' : 'announced_activity';
        $currentId = (string)($timeline[$field] ?? '');
        if ($undo && $currentId === '') {
            return false;
        }
        if (!$undo && $currentId !== '') {
            return true;
        }

        $activity = self::interactionActivity($type, $currentId ?: Fediverse_Core::activityId(strtolower($type)),
            $localId, (string)$timeline['actor'], (string)$timeline['object_id']);
        if ($undo) {
            $activity = array(
                '@context' => Fediverse_ActivityPub::CONTEXT,
                'id' => Fediverse_Core::activityId('undo-' . strtolower($type)),
                'type' => 'Undo',
                'actor' => $localId,
                'object' => $activity,
                'to' => array((string)$timeline['actor'])
            );
        }
        Fediverse_Queue::enqueueDelivery((int)$timeline['uid'], (string)$following['inbox'], $activity);
        $db->query($db->update(Fediverse_Database::table('timeline'))->rows(array(
            $field => $undo ? null : (string)$activity['id']
        ))->where('tid = ?', (int)$timeline['tid']));
        return !$undo;
    }

    public static function reply($tid, $content)
    {
        $content = trim(self::plainText((string)$content));
        if ($content === '') {
            throw new InvalidArgumentException('回复内容不能为空');
        }
        $content = Typecho_Common::subStr($content, 0, 5000, '');
        $db = Typecho_Db::get();
        $timeline = $db->fetchRow($db->select()->from(Fediverse_Database::table('timeline'))
            ->where('tid = ?', (int)$tid)->where('deleted = ?', 0)->limit(1));
        if (!$timeline) {
            throw new InvalidArgumentException('时间轴内容不存在');
        }
        if ((string)(self::timelineMeta((int)$timeline['tid'])['visibility'] ?? '') !== 'public') {
            throw new RuntimeException('当前只能回复公开内容');
        }
        $following = self::deliveryForTimeline($timeline);
        $token = bin2hex(random_bytes(12));
        $db->query($db->insert(Fediverse_Database::table('replies'))->rows(array(
            'token' => $token,
            'uid' => (int)$timeline['uid'],
            'in_reply_to' => (string)$timeline['object_id'],
            'content' => $content,
            'to_actor' => (string)$timeline['actor'],
            'published' => time(),
            'deleted' => 0
        )));
        $object = self::replyObject($token);
        if (!$object) {
            throw new RuntimeException('本地回复对象创建失败');
        }
        $activity = array(
            '@context' => Fediverse_ActivityPub::CONTEXT,
            'id' => Fediverse_Core::activityId('reply'),
            'type' => 'Create',
            'actor' => $object['attributedTo'],
            'published' => $object['published'],
            'to' => $object['to'],
            'cc' => $object['cc'],
            'object' => $object
        );
        Fediverse_Queue::enqueueDelivery((int)$timeline['uid'], (string)$following['inbox'], $activity);
        return $token;
    }

    public static function replyObject($token)
    {
        if (!preg_match('/^[a-f0-9]{24}$/', (string)$token)) {
            return null;
        }
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select()->from(Fediverse_Database::table('replies'))
            ->where('token = ?', (string)$token)->where('deleted = ?', 0)->limit(1));
        if (!$row) {
            return null;
        }
        $actor = Fediverse_Core::actorByUid((int)$row['uid']);
        if (!$actor) {
            return null;
        }
        $actorId = Fediverse_Core::actorUrl($actor['username']);
        $id = Fediverse_Core::url('fediverse/reply/' . (string)$row['token']);
        $content = nl2br(htmlspecialchars((string)$row['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        return array(
            'id' => $id,
            'type' => 'Note',
            'attributedTo' => $actorId,
            'content' => '<p>' . $content . '</p>',
            'mediaType' => 'text/html',
            'url' => $id,
            'inReplyTo' => (string)$row['in_reply_to'],
            'published' => Fediverse_Core::iso8601((int)$row['published']),
            'to' => array((string)$row['to_actor']),
            'cc' => array(Fediverse_ActivityPub::PUBLIC_AUDIENCE, $actorId . '/followers'),
            'sensitive' => false
        );
    }

    private static function interactionActivity($type, $id, $localId, $remoteActor, $objectId)
    {
        $activity = array(
            '@context' => Fediverse_ActivityPub::CONTEXT,
            'id' => $id,
            'type' => $type,
            'actor' => $localId,
            'object' => $objectId
        );
        if ($type === 'Like') {
            $activity['to'] = array($remoteActor);
        } else {
            $activity['to'] = array(Fediverse_ActivityPub::PUBLIC_AUDIENCE);
            $activity['cc'] = array($remoteActor, $localId . '/followers');
        }
        return $activity;
    }

    private static function storeTimelineMeta($tid, $activity, $object, $actorId, $announcedBy)
    {
        $summary = self::plainText((string)($object['summary'] ?? ''));
        $summary = Typecho_Common::subStr($summary, 0, 500, '');
        $attachments = array();
        $sourceAttachments = $object['attachment'] ?? array();
        if (!is_array($sourceAttachments) || isset($sourceAttachments['type'])) {
            $sourceAttachments = array($sourceAttachments);
        }
        foreach ($sourceAttachments as $attachment) {
            if (count($attachments) >= 8) {
                break;
            }
            if (!is_array($attachment)) {
                continue;
            }
            $url = self::mediaUrl($attachment['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $mediaValue = $attachment['mediaType'] ?? '';
            $mediaType = trim(is_scalar($mediaValue) ? (string)$mediaValue : '');
            if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mediaType)) {
                $mediaType = '';
            }
            $typeValue = $attachment['type'] ?? '';
            $attachmentType = is_scalar($typeValue) ? (string)$typeValue : '';
            $nameValue = $attachment['name'] ?? '';
            $attachments[] = array(
                'type' => in_array($attachmentType,
                    array('Image', 'Video', 'Audio', 'Document'), true)
                    ? $attachmentType : 'Document',
                'mediaType' => substr($mediaType, 0, 100),
                'url' => $url,
                'name' => Typecho_Common::subStr(
                    self::plainText(is_scalar($nameValue) ? (string)$nameValue : ''),
                    0,
                    500,
                    ''
                )
            );
        }

        $tags = array();
        $sourceTags = $object['tag'] ?? array();
        if (!is_array($sourceTags) || isset($sourceTags['type'])) {
            $sourceTags = array($sourceTags);
        }
        foreach ($sourceTags as $tag) {
            if (count($tags) >= 32) {
                break;
            }
            if (!is_array($tag)) {
                continue;
            }
            $typeValue = $tag['type'] ?? '';
            $type = is_scalar($typeValue) ? (string)$typeValue : '';
            if (!in_array($type, array('Hashtag', 'Mention'), true)) {
                continue;
            }
            $nameValue = $tag['name'] ?? '';
            $name = Typecho_Common::subStr(
                self::plainText(is_scalar($nameValue) ? (string)$nameValue : ''),
                0,
                200,
                ''
            );
            $href = self::isHttpsUrl($tag['href'] ?? '') ? (string)$tag['href'] : '';
            if ($name === '') {
                continue;
            }
            $tags[] = array('type' => $type, 'name' => $name, 'href' => $href);
        }

        $rows = array(
            'summary' => $summary,
            'sensitive' => !empty($object['sensitive']) ? 1 : 0,
            'attachments' => Fediverse_Core::json($attachments),
            'tags' => Fediverse_Core::json($tags),
            'poll' => Fediverse_Core::json(self::pollFromObject($object)),
            'visibility' => self::timelineVisibility($activity, $object, $actorId),
            'announced_actor' => self::isHttpsUrl($announcedBy) ? (string)$announcedBy : null
        );
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('timeline_meta');
        $existing = $db->fetchRow($db->select('tid')->from($table)->where('tid = ?', (int)$tid)->limit(1));
        if ($existing) {
            $db->query($db->update($table)->rows($rows)->where('tid = ?', (int)$tid));
            return;
        }
        $rows['tid'] = (int)$tid;
        $db->query($db->insert($table)->rows($rows));
    }

    private static function mediaUrl($value)
    {
        if (is_string($value)) {
            return self::isHttpsUrl($value) && strlen($value) <= 2048 ? $value : '';
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['href'])) {
            return self::mediaUrl($value['href']);
        }
        foreach ($value as $candidate) {
            $url = is_array($candidate)
                ? self::mediaUrl($candidate['href'] ?? ($candidate['url'] ?? ''))
                : self::mediaUrl($candidate);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    private static function attributedActor($value)
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['id'])) {
            return is_string($value['id']) ? trim($value['id']) : '';
        }
        $actors = array();
        foreach ($value as $candidate) {
            $actor = self::attributedActor($candidate);
            if ($actor === '') {
                return '';
            }
            $actors[rtrim($actor, '/')] = $actor;
        }
        return count($actors) === 1 ? (string)reset($actors) : '';
    }

    private static function remoteUrlValue($value, $maxLength)
    {
        if (is_string($value)) {
            return self::remoteUrl($value, $maxLength);
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['href'])) {
            return self::remoteUrlValue($value['href'], $maxLength);
        }
        if (isset($value['url'])) {
            return self::remoteUrlValue($value['url'], $maxLength);
        }
        foreach ($value as $candidate) {
            $url = self::remoteUrlValue($candidate, $maxLength);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    private static function remoteUrl($value, $maxLength)
    {
        if (!is_string($value) || strlen($value) > (int)$maxLength || !self::isHttpsUrl($value)) {
            return '';
        }
        $parts = parse_url($value);
        return !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) ? $value : '';
    }

    private static function remoteText($value, $maxLength)
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(Typecho_Common::subStr($text, 0, (int)$maxLength, ''));
    }

    private static function pollFromObject($object)
    {
        $multiple = isset($object['anyOf']);
        $source = $multiple ? $object['anyOf'] : ($object['oneOf'] ?? null);
        if (!is_array($source)) {
            return array();
        }
        if (isset($source['type'])) {
            $source = array($source);
        }
        $options = array();
        foreach ($source as $option) {
            if (count($options) >= 10) {
                break;
            }
            if (!is_array($option)) {
                continue;
            }
            $nameValue = $option['name'] ?? '';
            $name = Typecho_Common::subStr(
                self::plainText(is_scalar($nameValue) ? (string)$nameValue : ''),
                0,
                200,
                ''
            );
            if ($name === '') {
                continue;
            }
            $replies = is_array($option['replies'] ?? null) ? $option['replies'] : array();
            $countValue = $replies['totalItems'] ?? 0;
            $count = max(0, is_numeric($countValue) ? (int)$countValue : 0);
            $options[] = array('name' => $name, 'votes' => min($count, 2147483647));
        }
        if (count($options) < 2) {
            return array();
        }
        $endValue = $object['endTime'] ?? '';
        $endTime = is_scalar($endValue) && (string)$endValue !== '' ? (strtotime((string)$endValue) ?: 0) : 0;
        $votersValue = $object['votersCount'] ?? 0;
        return array(
            'multiple' => $multiple,
            'options' => $options,
            'votersCount' => max(0, min(is_numeric($votersValue) ? (int)$votersValue : 0, 2147483647)),
            'endTime' => $endTime,
            'closed' => !empty($object['closed']) || ($endTime > 0 && $endTime <= time())
        );
    }

    private static function timelineVisibility($activity, $object, $actorId)
    {
        $objectTo = self::audienceValues($object['to'] ?? array());
        $objectCc = self::audienceValues($object['cc'] ?? array());
        $to = $objectTo || $objectCc ? $objectTo : self::audienceValues($activity['to'] ?? array());
        $cc = $objectTo || $objectCc ? $objectCc : self::audienceValues($activity['cc'] ?? array());
        $public = rtrim(Fediverse_ActivityPub::PUBLIC_AUDIENCE, '/');
        if (in_array($public, $to, true)) {
            return 'public';
        }
        if (in_array($public, $cc, true)) {
            return 'unlisted';
        }
        $followers = rtrim((string)$actorId, '/') . '/followers';
        if (in_array($followers, $to, true) || in_array($followers, $cc, true)) {
            return 'followers';
        }
        return 'unknown';
    }

    private static function audienceValues($value)
    {
        $values = array();
        foreach (is_array($value) ? $value : array($value) as $item) {
            if (is_string($item)) {
                $values[] = rtrim($item, '/');
            }
        }
        return $values;
    }

    private static function followingForActor($uid, $actorId)
    {
        $db = Typecho_Db::get();
        return $db->fetchRow($db->select()->from(Fediverse_Database::table('followings'))
            ->where('uid = ?', (int)$uid)->where('actor_hash = ?', hash('sha256', (string)$actorId))
            ->where('state = ?', 'accepted')->limit(1)) ?: null;
    }

    private static function deliveryForTimeline($timeline)
    {
        $following = self::followingForActor((int)$timeline['uid'], (string)$timeline['actor']);
        if ($following) {
            return $following;
        }
        $meta = self::timelineMeta((int)$timeline['tid']);
        $announcedBy = (string)($meta['announced_actor'] ?? '');
        if ($announcedBy === '' || !self::isFollowed((int)$timeline['uid'], $announcedBy)) {
            throw new RuntimeException('远端账号已不在关注列表');
        }
        $remote = self::resolveActor((string)$timeline['actor']);
        return array('inbox' => (string)$remote['inbox']);
    }

    private static function timelineMeta($tid)
    {
        $db = Typecho_Db::get();
        return $db->fetchRow($db->select()->from(Fediverse_Database::table('timeline_meta'))
            ->where('tid = ?', (int)$tid)->limit(1)) ?: array();
    }

    private static function isFollowed($uid, $actorId)
    {
        return self::followingForActor($uid, $actorId) !== null;
    }

    private static function objectUrl($object, $fallback)
    {
        $url = $object['url'] ?? '';
        if (is_array($url)) {
            if (isset($url['href'])) {
                $url = $url['href'];
            } else {
                $url = '';
                foreach ($object['url'] as $candidate) {
                    if (is_array($candidate) && self::isHttpsUrl($candidate['href'] ?? '')) {
                        $url = $candidate['href'];
                        break;
                    }
                }
            }
        }
        return self::isHttpsUrl($url) ? (string)$url : (string)$fallback;
    }

    private static function hasTimelineAudience($activity, $object, $actorId)
    {
        $audiences = array();
        foreach (array($activity, $object) as $source) {
            foreach (array('to', 'cc') as $field) {
                $values = $source[$field] ?? array();
                foreach (is_array($values) ? $values : array($values) as $value) {
                    if (is_string($value)) {
                        $audiences[] = rtrim($value, '/');
                    }
                }
            }
        }
        return in_array(rtrim(Fediverse_ActivityPub::PUBLIC_AUDIENCE, '/'), $audiences, true)
            || in_array(rtrim((string)$actorId, '/') . '/followers', $audiences, true);
    }

    private static function plainText($html)
    {
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/blockquote)>/i', "\n", (string)$html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n?|\n{3,}/", "\n", $text);
        return trim(Typecho_Common::subStr($text, 0, 20000, ''));
    }

    private static function isHttpsUrl($url)
    {
        return is_string($url) && str_starts_with(strtolower($url), 'https://')
            && filter_var($url, FILTER_VALIDATE_URL);
    }
}
