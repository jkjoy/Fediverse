<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Fediverse_ActivityPub
{
    public const CONTEXT = 'https://www.w3.org/ns/activitystreams';
    public const PUBLIC_AUDIENCE = 'https://www.w3.org/ns/activitystreams#Public';

    public static function actor($user)
    {
        $actor = Fediverse_Core::ensureActor($user);
        $username = $actor['username'];
        $id = Fediverse_Core::actorUrl($username);
        $personal = Fediverse_Core::personalSettings((int)$user['uid']);
        $summary = trim((string)($personal['summary'] ?? ''));
        if ($summary === '') {
            $summary = (string)Fediverse_Core::setting('summary', '来自 Typecho 博客的作者账号');
        }
        $url = trim((string)($user['url'] ?? ''));
        if ($url === '') {
            $url = Fediverse_Core::url('author/' . rawurlencode((string)$user['name']) . '/');
        }
        return array(
            '@context' => array(self::CONTEXT, 'https://w3id.org/security/v1'),
            'id' => $id,
            'type' => 'Person',
            'preferredUsername' => $username,
            'name' => (string)($user['screenName'] ?: $user['name']),
            'summary' => '<p>' . htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
            'url' => $url,
            'inbox' => $id . '/inbox',
            'outbox' => $id . '/outbox',
            'followers' => $id . '/followers',
            'following' => $id . '/following',
            'endpoints' => array('sharedInbox' => Fediverse_Core::url('fediverse/inbox')),
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'indexable' => true,
            'publicKey' => array(
                'id' => $id . '#main-key',
                'owner' => $id,
                'publicKeyPem' => $actor['public_key']
            )
        );
    }

    public static function webfinger($resource)
    {
        if (!preg_match('/^acct:([^@]+)@(.+)$/i', trim((string)$resource), $matches)) {
            throw new InvalidArgumentException('Invalid WebFinger resource');
        }
        if (strtolower($matches[2]) !== Fediverse_Core::domain()) {
            throw new InvalidArgumentException('Unknown WebFinger domain');
        }
        $user = Fediverse_Core::userByUsername($matches[1]);
        if (!$user) {
            throw new InvalidArgumentException('Unknown WebFinger account');
        }
        $actor = Fediverse_Core::ensureActor($user);
        $id = Fediverse_Core::actorUrl($actor['username']);
        return array(
            'subject' => 'acct:' . $actor['username'] . '@' . Fediverse_Core::domain(),
            'aliases' => array($id),
            'links' => array(
                array('rel' => 'self', 'type' => 'application/activity+json', 'href' => $id),
                array('rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => (string)($user['url'] ?: Fediverse_Core::options()->siteUrl))
            )
        );
    }

    public static function noteForPost($cid)
    {
        $widget = Typecho_Widget::widget(
            'Widget_Archive@fediverse_' . (int)$cid,
            'type=single',
            array('cid' => (int)$cid),
            false
        );
        if (!$widget || !$widget->have() || $widget->type !== 'post' || $widget->status !== 'publish' || !empty($widget->password)) {
            return null;
        }
        $user = Fediverse_Core::userById((int)$widget->authorId);
        if (!$user || !Fediverse_Core::userEnabled((int)$widget->authorId)) {
            return null;
        }
        $actor = Fediverse_Core::ensureActor($user);
        $actorUrl = Fediverse_Core::actorUrl($actor['username']);
        return array(
            'id' => Fediverse_Core::objectUrl((int)$widget->cid),
            'type' => 'Article',
            'attributedTo' => $actorUrl,
            'name' => (string)$widget->title,
            'content' => (string)$widget->content,
            'mediaType' => 'text/html',
            'url' => (string)$widget->permalink,
            'published' => Fediverse_Core::iso8601((int)$widget->created),
            'updated' => Fediverse_Core::iso8601((int)$widget->modified),
            'to' => array(self::PUBLIC_AUDIENCE),
            'cc' => array($actorUrl . '/followers'),
            'replies' => array(
                'id' => Fediverse_Core::objectUrl((int)$widget->cid) . '/replies',
                'type' => 'Collection',
                'totalItems' => (int)$widget->commentsNum
            ),
            'sensitive' => false
        );
    }

    public static function outbox($user)
    {
        $db = Typecho_Db::get();
        $actor = Fediverse_Core::ensureActor($user);
        $actorUrl = Fediverse_Core::actorUrl($actor['username']);
        $countRow = $db->fetchRow($db->select(array('COUNT(cid)' => 'num'))->from('table.contents')
            ->where('authorId = ?', (int)$user['uid'])->where('type = ?', 'post')->where('status = ?', 'publish')
            ->where("password IS NULL OR password = ''"));
        $rows = $db->fetchAll($db->select('cid')->from('table.contents')
            ->where('authorId = ?', (int)$user['uid'])->where('type = ?', 'post')->where('status = ?', 'publish')
            ->where("password IS NULL OR password = ''")->order('created', Typecho_Db::SORT_DESC)->limit(20));
        $items = array();
        foreach ($rows as $row) {
            $note = self::noteForPost((int)$row['cid']);
            if ($note) {
                $items[] = array(
                    'id' => $note['id'] . '#create',
                    'type' => 'Create',
                    'actor' => $actorUrl,
                    'published' => $note['published'],
                    'to' => $note['to'],
                    'cc' => $note['cc'],
                    'object' => $note
                );
            }
        }
        return array(
            '@context' => self::CONTEXT,
            'id' => $actorUrl . '/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => (int)($countRow['num'] ?? 0),
            'orderedItems' => $items
        );
    }

    public static function followers($user)
    {
        $db = Typecho_Db::get();
        $actor = Fediverse_Core::ensureActor($user);
        $rows = $db->fetchAll($db->select('actor')->from(Fediverse_Database::table('followers'))
            ->where('uid = ?', (int)$user['uid'])->where('state = ?', 'accepted'));
        return array(
            '@context' => self::CONTEXT,
            'id' => Fediverse_Core::actorUrl($actor['username']) . '/followers',
            'type' => 'Collection',
            'totalItems' => count($rows),
            'items' => array_values(array_column($rows, 'actor'))
        );
    }

    public static function following($user)
    {
        $db = Typecho_Db::get();
        $actor = Fediverse_Core::ensureActor($user);
        $rows = $db->fetchAll($db->select('actor')->from(Fediverse_Database::table('followings'))
            ->where('uid = ?', (int)$user['uid'])->where('state = ?', 'accepted'));
        return array(
            '@context' => self::CONTEXT,
            'id' => Fediverse_Core::actorUrl($actor['username']) . '/following',
            'type' => 'Collection',
            'totalItems' => count($rows),
            'items' => array_values(array_column($rows, 'actor'))
        );
    }

    public static function receive($activity, $rawBody, $request, $targetUsername = null)
    {
        if (!is_array($activity) || empty($activity['id']) || empty($activity['type'])) {
            throw new InvalidArgumentException('Activity must contain id and type');
        }
        $actorId = is_array($activity['actor'] ?? null)
            ? (string)($activity['actor']['id'] ?? '')
            : (string)($activity['actor'] ?? '');
        if (!self::isHttpsId($activity['id']) || !self::isHttpsId($actorId)) {
            throw new InvalidArgumentException('Activity and actor IDs must use HTTPS');
        }
        Fediverse_Http::verifyIncoming($request, $rawBody, $actorId);
        if (self::activityExists($activity['id'])) {
            return array('duplicate' => true);
        }

        $type = (string)$activity['type'];
        $user = $targetUsername !== null ? Fediverse_Core::userByUsername($targetUsername) : self::targetUser($activity);
        $timelineUsers = in_array($type, array('Create', 'Update', 'Delete'), true)
            ? Fediverse_Client::usersFollowing($actorId, in_array($type, array('Update', 'Delete'), true))
            : array();
        if (!$user && $timelineUsers) {
            $user = $timelineUsers[0];
        }
        if (!$user || !Fediverse_Core::userEnabled((int)$user['uid'])) {
            throw new InvalidArgumentException('Local inbox target was not found');
        }
        $recipientUsers = array((int)$user['uid'] => $user);
        foreach ($timelineUsers as $timelineUser) {
            $recipientUsers[(int)$timelineUser['uid']] = $timelineUser;
        }
        $status = 'accepted';
        $objectId = self::objectId($activity['object'] ?? null);
        switch ($type) {
            case 'Follow':
                self::follow($activity, $user, $actorId);
                break;
            case 'Accept':
                $status = Fediverse_Client::handleFollowResponse($activity, $user, $actorId, 'accepted')
                    ? 'accepted'
                    : 'ignored';
                break;
            case 'Reject':
                $status = Fediverse_Client::handleFollowResponse($activity, $user, $actorId, 'rejected')
                    ? 'rejected'
                    : 'ignored';
                break;
            case 'Undo':
                self::undo($activity, $user, $actorId);
                break;
            case 'Create':
                $commentId = 0;
                $timelineId = 0;
                foreach ($recipientUsers as $recipientUser) {
                    $createdComment = self::createReply($activity, $recipientUser, $actorId);
                    if ($createdComment) {
                        $commentId = $createdComment;
                    } else {
                        $timelineId = Fediverse_Client::storeTimeline($activity, $recipientUser, $actorId) ?: $timelineId;
                    }
                }
                $status = $commentId ? 'comment:' . $commentId : ($timelineId ? 'timeline' : 'ignored');
                break;
            case 'Update':
                $commentId = 0;
                $timelineId = 0;
                foreach ($recipientUsers as $recipientUser) {
                    $updatedComment = self::updateReply($activity, $recipientUser, $actorId);
                    if ($updatedComment) {
                        $commentId = $updatedComment;
                    } else {
                        $timelineId = Fediverse_Client::storeTimeline($activity, $recipientUser, $actorId) ?: $timelineId;
                    }
                }
                $status = $commentId ? 'comment:' . $commentId : ($timelineId ? 'timeline' : 'ignored');
                break;
            case 'Delete':
                self::deleteReply($objectId, $actorId);
                $deleted = 0;
                foreach ($recipientUsers as $recipientUser) {
                    $deleted += Fediverse_Client::deleteTimeline($objectId, $recipientUser, $actorId);
                }
                $status = $deleted ? 'timeline' : 'accepted';
                break;
            case 'Like':
            case 'Announce':
                if (!self::isLocalObjectForUser($objectId, $user)) {
                    $status = 'ignored';
                }
                break;
            default:
                $status = 'ignored';
        }
        self::recordActivity($activity, $actorId, $objectId, $status);
        return array('duplicate' => false, 'status' => $status);
    }

    public static function pruneLogs($days = null)
    {
        $days = $days === null ? (int)Fediverse_Core::setting('activityRetentionDays', 90) : (int)$days;
        $days = max(7, min(3650, $days));
        $db = Typecho_Db::get();
        return (int)$db->query($db->delete(Fediverse_Database::table('activities'))
            ->where('created < ?', time() - ($days * 86400)));
    }

    private static function follow($activity, $user, $actorId)
    {
        $localActor = Fediverse_Core::ensureActor($user);
        $localId = Fediverse_Core::actorUrl($localActor['username']);
        if (rtrim(self::objectId($activity['object'] ?? null), '/') !== rtrim($localId, '/')) {
            throw new InvalidArgumentException('Follow target does not match inbox actor');
        }
        $remote = Fediverse_Http::getJson($actorId);
        if (rtrim((string)($remote['id'] ?? ''), '/') !== rtrim($actorId, '/')) {
            throw new InvalidArgumentException('Follower document ID does not match activity actor');
        }
        $inbox = (string)($remote['inbox'] ?? '');
        $shared = (string)($remote['endpoints']['sharedInbox'] ?? '');
        if (!self::isHttpsId($inbox)) {
            throw new InvalidArgumentException('Follower inbox is missing or invalid');
        }
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('followers');
        $db->query($db->delete($table)->where('uid = ?', (int)$user['uid'])->where('actor = ?', $actorId));
        $db->query($db->insert($table)->rows(array(
            'uid' => (int)$user['uid'], 'actor' => $actorId, 'inbox' => $inbox,
            'shared_inbox' => self::isHttpsId($shared) ? $shared : null,
            'state' => 'accepted', 'created' => time()
        )));
        $accept = array(
            '@context' => self::CONTEXT,
            'id' => Fediverse_Core::activityId('accept'),
            'type' => 'Accept',
            'actor' => $localId,
            'object' => $activity,
            'to' => array($actorId)
        );
        Fediverse_Queue::enqueueDelivery((int)$user['uid'], $shared ?: $inbox, $accept);
    }

    private static function undo($activity, $user, $actorId)
    {
        $object = $activity['object'] ?? null;
        $type = is_array($object) ? (string)($object['type'] ?? '') : '';
        $activityId = self::objectId($object);
        $db = Typecho_Db::get();
        $original = $activityId !== '' ? $db->fetchRow($db->select('type', 'object_id')->from(Fediverse_Database::table('activities'))
            ->where('activity_id = ?', $activityId)->where('actor = ?', $actorId)->limit(1)) : null;
        if ($type === '' && $original) {
            $type = (string)$original['type'];
        }
        if ($type === 'Follow') {
            $db->query($db->delete(Fediverse_Database::table('followers'))
                ->where('uid = ?', (int)$user['uid'])->where('actor = ?', $actorId));
        }
        if ($type === 'Create' && $original) {
            self::deleteReply((string)$original['object_id'], $actorId);
        } elseif ($activityId !== '' && in_array($type, array('Follow', 'Like', 'Announce'), true)) {
            $db->query($db->update(Fediverse_Database::table('activities'))->rows(array('status' => 'undone'))
                ->where('activity_id = ?', $activityId)->where('actor = ?', $actorId));
        }
    }

    private static function createReply($activity, $user, $actorId)
    {
        if ((string)Fediverse_Core::setting('acceptReplies', '1') !== '1') {
            return 0;
        }
        $object = $activity['object'] ?? null;
        if (!is_array($object) || !in_array((string)($object['type'] ?? ''), array('Note', 'Article'), true)) {
            return 0;
        }
        $objectId = (string)($object['id'] ?? '');
        $replyTo = (string)($object['inReplyTo'] ?? '');
        if (!self::isHttpsId($objectId) || $replyTo === '') {
            return 0;
        }
        $attributedTo = is_array($object['attributedTo'] ?? null)
            ? (string)($object['attributedTo']['id'] ?? '')
            : (string)($object['attributedTo'] ?? '');
        if ($attributedTo !== '' && rtrim($attributedTo, '/') !== rtrim($actorId, '/')) {
            throw new InvalidArgumentException('Reply attribution does not match activity actor');
        }
        $existingCommentId = self::commentIdForObject($objectId, $actorId);
        if ($existingCommentId) {
            return $existingCommentId;
        }
        $cid = self::cidForObject($replyTo);
        if (!$cid) {
            return 0;
        }
        $db = Typecho_Db::get();
        $post = $db->fetchRow($db->select('cid', 'authorId', 'allowComment')->from('table.contents')
            ->where('cid = ?', $cid)->where('status = ?', 'publish')->limit(1));
        if (!$post || (int)$post['authorId'] !== (int)$user['uid'] || !(int)$post['allowComment']) {
            return 0;
        }
        $name = self::replyAuthorName($object, $actorId);
        $text = self::plainText((string)($object['content'] ?? ''));
        if ($text === '') {
            return 0;
        }
        $comments = Typecho_Widget::widget('Widget_Abstract_Comments@fediverse_insert');
        return (int)$comments->insert(array(
            'cid' => $cid,
            'created' => isset($object['published']) ? (strtotime((string)$object['published']) ?: time()) : time(),
            'author' => Typecho_Common::subStr($name, 0, 150, ''),
            'authorId' => 0,
            'ownerId' => (int)$user['uid'],
            'mail' => '',
            'url' => $actorId,
            'ip' => '0.0.0.0',
            'agent' => 'ActivityPub',
            'text' => $text,
            'type' => 'comment',
            'status' => 'waiting',
            'parent' => 0
        ));
    }

    private static function updateReply($activity, $user, $actorId)
    {
        if ((string)Fediverse_Core::setting('acceptReplies', '1') !== '1') {
            return 0;
        }
        $object = $activity['object'] ?? null;
        if (!is_array($object) || !in_array((string)($object['type'] ?? ''), array('Note', 'Article'), true)) {
            return 0;
        }
        $objectId = (string)($object['id'] ?? '');
        $attributedTo = is_array($object['attributedTo'] ?? null)
            ? (string)($object['attributedTo']['id'] ?? '')
            : (string)($object['attributedTo'] ?? '');
        if (!self::isHttpsId($objectId) || ($attributedTo !== '' && rtrim($attributedTo, '/') !== rtrim($actorId, '/'))) {
            return 0;
        }
        $commentId = self::commentIdForObject($objectId, $actorId);
        if (!$commentId) {
            return 0;
        }
        $text = self::plainText((string)($object['content'] ?? ''));
        if ($text === '') {
            return 0;
        }
        $comments = Typecho_Widget::widget('Widget_Abstract_Comments@fediverse_update');
        $comments->update(array(
            'author' => self::replyAuthorName($object, $actorId),
            'url' => $actorId,
            'text' => $text,
            'status' => 'waiting'
        ), Typecho_Db::get()->sql()->where('coid = ?', $commentId));
        return $commentId;
    }

    private static function deleteReply($objectId, $actorId)
    {
        if ($objectId === '') {
            return;
        }
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('status')->from(Fediverse_Database::table('activities'))
            ->where('object_id = ?', $objectId)->where('actor = ?', $actorId)->limit(1));
        if ($row && preg_match('/^comment:(\d+)$/', (string)$row['status'], $matches)) {
            $comments = Typecho_Widget::widget('Widget_Abstract_Comments@fediverse_delete');
            $comments->delete($db->sql()->where('coid = ?', (int)$matches[1]));
            $db->query($db->update(Fediverse_Database::table('activities'))->rows(array('status' => 'deleted'))
                ->where('object_id = ?', $objectId)->where('actor = ?', $actorId)
                ->where('status LIKE ?', 'comment:%'));
        }
    }

    private static function commentIdForObject($objectId, $actorId)
    {
        if ($objectId === '') {
            return 0;
        }
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('status')->from(Fediverse_Database::table('activities'))
            ->where('object_id = ?', $objectId)->where('actor = ?', $actorId)
            ->where('status LIKE ?', 'comment:%')->order('aid', Typecho_Db::SORT_DESC)->limit(1));
        return $row && preg_match('/^comment:(\d+)$/', (string)$row['status'], $matches)
            ? (int)$matches[1]
            : 0;
    }

    private static function replyAuthorName($object, $actorId)
    {
        $attributedTo = $object['attributedTo'] ?? null;
        $name = is_array($attributedTo)
            ? trim((string)($attributedTo['name'] ?? $attributedTo['preferredUsername'] ?? ''))
            : '';
        if ($name === '') {
            $name = trim((string)($object['name'] ?? ''));
        }
        if ($name === '') {
            $path = trim((string)parse_url($actorId, PHP_URL_PATH), '/');
            $username = $path !== '' ? rawurldecode((string)basename($path)) : '';
            $host = (string)parse_url($actorId, PHP_URL_HOST);
            $name = $username !== '' && $host !== '' ? '@' . $username . '@' . $host : ($host ?: 'Fediverse');
        }
        return Typecho_Common::subStr($name, 0, 150, '');
    }

    private static function isLocalObjectForUser($objectId, $user)
    {
        $cid = self::cidForObject($objectId);
        if (!$cid) {
            return false;
        }
        $db = Typecho_Db::get();
        $post = $db->fetchRow($db->select('authorId')->from('table.contents')
            ->where('cid = ?', $cid)->where('type = ?', 'post')->where('status = ?', 'publish')->limit(1));
        return $post && (int)$post['authorId'] === (int)$user['uid'];
    }

    private static function targetUser($activity)
    {
        $targets = array();
        foreach (array('to', 'cc') as $field) {
            $values = $activity[$field] ?? array();
            foreach (is_array($values) ? $values : array($values) as $value) {
                if (is_string($value)) {
                    $targets[] = $value;
                }
            }
        }
        $activityObject = $activity['object'] ?? null;
        $object = self::objectId($activityObject);
        if ($object !== '') {
            $targets[] = $object;
        }
        if (is_array($activityObject)) {
            $embeddedActor = is_array($activityObject['actor'] ?? null)
                ? (string)($activityObject['actor']['id'] ?? '')
                : (string)($activityObject['actor'] ?? '');
            if ($embeddedActor !== '') {
                $targets[] = $embeddedActor;
            }
            $nestedObject = self::objectId($activityObject['object'] ?? null);
            if ($nestedObject !== '') {
                $targets[] = $nestedObject;
            }
            foreach (array('to', 'cc') as $field) {
                $values = $activityObject[$field] ?? array();
                foreach (is_array($values) ? $values : array($values) as $value) {
                    if (is_string($value)) {
                        $targets[] = $value;
                    }
                }
            }
        }
        foreach ($targets as $target) {
            $actorPrefix = rtrim(Fediverse_Core::baseUrl(), '/') . '/fediverse/author/';
            if (str_starts_with($target, $actorPrefix)
                && preg_match('/^([A-Za-z0-9_-]+)(?:\/|$)/', substr($target, strlen($actorPrefix)), $matches)) {
                $user = Fediverse_Core::userByUsername($matches[1]);
                if ($user) {
                    return $user;
                }
            }
            $cid = self::cidForObject($target);
            if ($cid) {
                $db = Typecho_Db::get();
                $post = $db->fetchRow($db->select('authorId')->from('table.contents')->where('cid = ?', $cid)->limit(1));
                if ($post) {
                    return Fediverse_Core::userById((int)$post['authorId']);
                }
            }
        }
        $cid = self::cidForObject(is_array($activityObject) ? (string)($activityObject['inReplyTo'] ?? '') : '');
        if ($cid) {
            $db = Typecho_Db::get();
            $post = $db->fetchRow($db->select('authorId')->from('table.contents')->where('cid = ?', $cid)->limit(1));
            return $post ? Fediverse_Core::userById((int)$post['authorId']) : null;
        }
        return null;
    }

    private static function cidForObject($id)
    {
        $id = (string)$id;
        $prefix = rtrim(Fediverse_Core::baseUrl(), '/') . '/fediverse/post/';
        if (str_starts_with($id, $prefix) && preg_match('/^(\d+)(?:\/|$)/', substr($id, strlen($prefix)), $matches)) {
            return (int)$matches[1];
        }
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('cid')->from(Fediverse_Database::table('posts'))
            ->where('object_id = ?', $id)->limit(1));
        return $row ? (int)$row['cid'] : 0;
    }

    private static function activityExists($id)
    {
        $db = Typecho_Db::get();
        return (bool)$db->fetchRow($db->select('aid')->from(Fediverse_Database::table('activities'))
            ->where('activity_hash = ?', hash('sha256', (string)$id))->limit(1));
    }

    private static function recordActivity($activity, $actor, $objectId, $status)
    {
        $db = Typecho_Db::get();
        $db->query($db->insert(Fediverse_Database::table('activities'))->rows(array(
            'activity_hash' => hash('sha256', (string)$activity['id']),
            'activity_id' => (string)$activity['id'],
            'type' => substr((string)$activity['type'], 0, 32),
            'actor' => $actor,
            'object_id' => $objectId ?: null,
            'payload' => Fediverse_Core::json($activity),
            'status' => substr((string)$status, 0, 16),
            'created' => time()
        )));
    }

    private static function objectId($object)
    {
        return is_array($object) ? (string)($object['id'] ?? '') : (is_string($object) ? $object : '');
    }

    private static function isHttpsId($id)
    {
        return is_string($id) && str_starts_with(strtolower($id), 'https://') && filter_var($id, FILTER_VALIDATE_URL);
    }

    private static function plainText($html)
    {
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/blockquote)>/i', "\n", (string)$html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n?|\n{3,}/", "\n", $text);
        return trim(Typecho_Common::subStr($text, 0, 10000, ''));
    }
}
