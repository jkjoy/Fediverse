<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

class Fediverse_Action extends Typecho_Widget
{
    public function webfinger()
    {
        try {
            $data = Fediverse_ActivityPub::webfinger($this->request->get('resource', ''));
            $this->respond($data, 'application/jrd+json');
        } catch (InvalidArgumentException $e) {
            $this->error(404, $e->getMessage());
        }
    }

    public function actor()
    {
        $user = Fediverse_Core::userByUsername($this->request->get('username', ''));
        if (!$user || !Fediverse_Core::isEnabled()) {
            $this->error(404, 'Actor not found');
        }
        $this->respond(Fediverse_ActivityPub::actor($user), 'application/activity+json');
    }

    public function outbox()
    {
        $user = Fediverse_Core::userByUsername($this->request->get('username', ''));
        if (!$user || !Fediverse_Core::isEnabled()) {
            $this->error(404, 'Actor not found');
        }
        $this->respond(Fediverse_ActivityPub::outbox($user), 'application/activity+json');
    }

    public function followers()
    {
        $user = Fediverse_Core::userByUsername($this->request->get('username', ''));
        if (!$user || !Fediverse_Core::isEnabled()) {
            $this->error(404, 'Actor not found');
        }
        $this->respond(Fediverse_ActivityPub::followers($user), 'application/activity+json');
    }

    public function following()
    {
        $user = Fediverse_Core::userByUsername($this->request->get('username', ''));
        if (!$user || !Fediverse_Core::isEnabled()) {
            $this->error(404, 'Actor not found');
        }
        $this->respond(Fediverse_ActivityPub::following($user), 'application/activity+json');
    }

    public function object()
    {
        $note = Fediverse_ActivityPub::noteForPost((int)$this->request->get('cid', 0));
        if (!$note || !Fediverse_Core::isEnabled()) {
            $this->error(404, 'Object not found');
        }
        $note = array('@context' => Fediverse_ActivityPub::CONTEXT) + $note;
        $this->respond($note, 'application/activity+json');
    }

    public function reply()
    {
        $note = Fediverse_Client::replyObject((string)$this->request->get('token', ''));
        if (!$note || !Fediverse_Core::isEnabled()) {
            $this->error(404, 'Reply not found');
        }
        $note = array('@context' => Fediverse_ActivityPub::CONTEXT) + $note;
        $this->respond($note, 'application/activity+json');
    }

    public function inbox()
    {
        $this->receive($this->request->get('username', ''));
    }

    public function sharedInbox()
    {
        $this->receive(null);
    }

    public function nodeinfoDiscovery()
    {
        $this->respond(array(
            'links' => array(array(
                'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                'href' => Fediverse_Core::url('fediverse/nodeinfo/2.1')
            ))
        ), 'application/json');
    }

    public function nodeinfo()
    {
        $db = Typecho_Db::get();
        $users = $db->fetchRow($db->select(array('COUNT(uid)' => 'num'))->from('table.users'));
        $posts = $db->fetchRow($db->select(array('COUNT(cid)' => 'num'))->from('table.contents')
            ->where('type = ?', 'post')->where('status = ?', 'publish'));
        $comments = $db->fetchRow($db->select(array('COUNT(coid)' => 'num'))->from('table.comments')
            ->where('type = ?', 'comment')->where('status = ?', 'approved'));
        $this->respond(array(
            'version' => '2.1',
            'software' => array('name' => 'typecho', 'version' => '1.3.0', 'repository' => 'https://github.com/typecho/typecho'),
            'protocols' => array('activitypub'),
            'services' => array('inbound' => array(), 'outbound' => array()),
            'openRegistrations' => false,
            'usage' => array(
                'users' => array('total' => (int)($users['num'] ?? 0)),
                'localPosts' => (int)($posts['num'] ?? 0),
                'localComments' => (int)($comments['num'] ?? 0)
            ),
            'metadata' => array('nodeName' => (string)Fediverse_Core::options()->title)
        ), 'application/json');
    }

    public function cron()
    {
        $configured = (string)Fediverse_Core::setting('cronToken', '');
        $provided = (string)$this->request->get('token', '');
        if ($configured === '' || !hash_equals($configured, $provided)) {
            $this->error(404, 'Not found');
        }
        $result = Fediverse_Queue::run();
        $result['prunedActivities'] = Fediverse_ActivityPub::pruneLogs();
        $result['prunedTimeline'] = Fediverse_Client::pruneTimeline();
        $this->respond($result, 'application/json');
    }

    private function receive($username)
    {
        if (!Fediverse_Core::isEnabled()) {
            $this->error(404, 'Inbox is disabled');
        }
        if (!$this->request->isPost()) {
            $this->error(405, 'POST required');
        }
        $length = (int)$this->request->getHeader('Content-Length', '0');
        if ($length > 1048576) {
            $this->error(413, 'Activity is too large');
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '' || strlen($raw) > 1048576) {
            $this->error(400, 'Activity body is empty or too large');
        }
        $activity = json_decode($raw, true, 32);
        if (!is_array($activity)) {
            $this->error(400, 'Activity body is not valid JSON');
        }
        try {
            $result = Fediverse_ActivityPub::receive($activity, $raw, $this->request, $username);
            $this->response->setStatus(202);
            $this->respond($result, 'application/json');
        } catch (InvalidArgumentException $e) {
            $this->error(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->error(401, $e->getMessage());
        } catch (Throwable $e) {
            $this->error(500, 'Inbox processing failed');
        }
    }

    private function respond($data, $contentType)
    {
        $json = Fediverse_Core::json($data);
        $this->response->setHeader('Cache-Control', 'no-store');
        $this->response->throwContent($json === false ? '{}' : $json, $contentType);
    }

    private function error($status, $message)
    {
        $this->response->setStatus((int)$status);
        $this->respond(array('error' => (string)$message), 'application/json');
    }
}
