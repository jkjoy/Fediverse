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
            $webfinger = Fediverse_Http::getJson(
                $origin . '/.well-known/webfinger?resource=' . rawurlencode('acct:' . $account)
            );
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

        $actor = Fediverse_Http::getJson($actorUrl);
        $id = (string)($actor['id'] ?? '');
        $inbox = (string)($actor['inbox'] ?? '');
        $shared = (string)($actor['endpoints']['sharedInbox'] ?? '');
        if (!self::isHttpsUrl($id) || !self::isHttpsUrl($inbox)) {
            throw new RuntimeException('远端 Actor 缺少有效的 id 或 inbox');
        }
        return array(
            'id' => $id,
            'inbox' => $inbox,
            'shared_inbox' => self::isHttpsUrl($shared) ? $shared : null,
            'username' => (string)($actor['preferredUsername'] ?? ''),
            'name' => (string)($actor['name'] ?? '')
        );
    }

    public static function follow($uid, $identifier)
    {
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
        Fediverse_Queue::enqueueDelivery($uid, $remote['inbox'], $activity);
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

    public static function storeTimeline($activity, $user, $actorId)
    {
        $object = $activity['object'] ?? null;
        if (!is_array($object) || !in_array((string)($object['type'] ?? ''), array('Note', 'Article'), true)) {
            return 0;
        }
        $objectId = (string)($object['id'] ?? '');
        $attributedTo = is_array($object['attributedTo'] ?? null)
            ? (string)($object['attributedTo']['id'] ?? '')
            : (string)($object['attributedTo'] ?? '');
        if (!self::isHttpsUrl($objectId) || ($attributedTo !== '' && rtrim($attributedTo, '/') !== rtrim($actorId, '/'))) {
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
        $modified = isset($object['updated']) ? (strtotime((string)$object['updated']) ?: time()) : time();
        $hash = hash('sha256', (int)$user['uid'] . "\0" . $objectId);
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('timeline');
        $existing = $db->fetchRow($db->select()->from($table)->where('object_hash = ?', $hash)->limit(1));
        if (!$existing && (!self::isFollowed((int)$user['uid'], $actorId)
            || !self::hasTimelineAudience($activity, $object, $actorId))) {
            return 0;
        }
        $rows = array(
            'activity_id' => (string)$activity['id'],
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
            return (int)$existing['tid'];
        }
        $rows['uid'] = (int)$user['uid'];
        $rows['object_hash'] = $hash;
        $rows['liked_activity'] = null;
        $rows['announced_activity'] = null;
        return (int)$db->query($db->insert($table)->rows($rows));
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
        return (int)$db->query($db->delete(Fediverse_Database::table('timeline'))
            ->where('published < ?', time() - ($days * 86400)));
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
        $following = self::followingForActor((int)$timeline['uid'], (string)$timeline['actor']);
        if (!$following) {
            throw new RuntimeException('远端账号已不在关注列表');
        }
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
        $following = self::followingForActor((int)$timeline['uid'], (string)$timeline['actor']);
        if (!$following) {
            throw new RuntimeException('远端账号已不在关注列表');
        }
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

    private static function followingForActor($uid, $actorId)
    {
        $db = Typecho_Db::get();
        return $db->fetchRow($db->select()->from(Fediverse_Database::table('followings'))
            ->where('uid = ?', (int)$uid)->where('actor_hash = ?', hash('sha256', (string)$actorId))
            ->where('state = ?', 'accepted')->limit(1)) ?: null;
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
