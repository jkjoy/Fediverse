<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

class Fediverse_AdminAction extends Typecho_Widget implements Widget_Interface_Do
{
    public function action()
    {
        Fediverse_Core::helperCall('security')->protect();
        Typecho_Widget::widget('Widget_User')->pass('administrator');
        Fediverse_Database::install();

        $do = (string)$this->request->get('do', '');
        try {
            switch ($do) {
                case 'run-queue':
                    $result = Fediverse_Queue::run();
                    $this->notice(
                        _t(
                            '队列处理完成：扫描 %d，成功 %d，失败 %d。',
                            (int)$result['processed'],
                            (int)$result['sent'],
                            (int)$result['failed']
                        ),
                        $result['failed'] > 0 ? 'notice' : 'success'
                    );
                    break;
                case 'retry-queue':
                    $count = Fediverse_Queue::retry($this->ids('qid'));
                    $this->notice(_t('已将 %d 个投递任务放回队列。', $count), 'success');
                    break;
                case 'delete-queue':
                    $count = Fediverse_Queue::delete($this->ids('qid'));
                    $this->notice(_t('已删除 %d 个投递任务。', $count), 'success');
                    break;
                case 'remove-follower':
                    $count = $this->removeFollowers($this->ids('id'));
                    $this->notice(_t('已移除 %d 个关注者。', $count), 'success');
                    break;
                case 'provision-actors':
                    $count = $this->provisionActors();
                    $this->notice(_t('作者联邦身份检查完成，新生成 %d 个身份。', $count), 'success');
                    break;
                case 'prune-activities':
                    $activities = Fediverse_ActivityPub::pruneLogs();
                    $timeline = Fediverse_Client::pruneTimeline();
                    $this->notice(_t('已清理 %d 条入站活动和 %d 条时间轴内容。', $activities, $timeline), 'success');
                    break;
                case 'follow-remote':
                    $result = Fediverse_Client::follow(
                        (int)$this->request->get('uid', 0),
                        (string)$this->request->get('account', '')
                    );
                    if ($result['created']) {
                        $this->notice(_t('关注请求已加入队列，等待远端确认。'), 'success');
                    } else {
                        $this->notice(
                            $result['state'] === 'accepted' ? _t('已经关注此账号。') : _t('关注请求仍在等待远端确认。'),
                            'notice'
                        );
                    }
                    break;
                case 'unfollow-remote':
                    $count = Fediverse_Client::unfollow((int)$this->request->get('id', 0));
                    $this->notice(_t('已取消 %d 个远端关注。', $count), 'success');
                    break;
                case 'timeline-like':
                case 'timeline-unlike':
                    $active = Fediverse_Client::toggleInteraction(
                        (int)$this->request->get('tid', 0),
                        'Like',
                        $do === 'timeline-unlike'
                    );
                    $this->notice($active ? _t('点赞已加入投递队列。') : _t('取消点赞已加入投递队列。'), 'success');
                    break;
                case 'timeline-announce':
                case 'timeline-unannounce':
                    $active = Fediverse_Client::toggleInteraction(
                        (int)$this->request->get('tid', 0),
                        'Announce',
                        $do === 'timeline-unannounce'
                    );
                    $this->notice($active ? _t('转发已加入投递队列。') : _t('取消转发已加入投递队列。'), 'success');
                    break;
                case 'timeline-reply':
                    Fediverse_Client::reply(
                        (int)$this->request->get('tid', 0),
                        (string)$this->request->get('content', '')
                    );
                    $this->notice(_t('回复已加入投递队列。'), 'success');
                    break;
                default:
                    $this->notice(_t('未知的联邦管理操作。'), 'error');
            }
        } catch (Throwable $e) {
            $this->notice(_t('操作失败：%s', $e->getMessage()), 'error');
        }

        $views = array('overview', 'queue', 'followers', 'following', 'timeline', 'activities');
        $view = (string)$this->request->get('view', 'overview');
        $query = 'extending.php?panel=Fediverse%2Fmanage.php';
        if (in_array($view, $views, true) && $view !== 'overview') {
            $query .= '&view=' . rawurlencode($view);
        }
        $this->response->redirect(Typecho_Common::url($query, Typecho_Widget::widget('Widget_Options')->adminUrl));
    }

    private function ids($name)
    {
        $ids = $this->request->filter('int')->getArray($name);
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    }

    private function removeFollowers($ids)
    {
        if (!$ids) {
            return 0;
        }
        $db = Typecho_Db::get();
        return (int)$db->query($db->delete(Fediverse_Database::table('followers'))->where('id IN ?', $ids));
    }

    private function provisionActors()
    {
        $db = Typecho_Db::get();
        $users = $db->fetchAll($db->select()->from('table.users'));
        $created = 0;
        foreach ($users as $user) {
            if (!Fediverse_Core::userEnabled((int)$user['uid'])) {
                continue;
            }
            $existing = $db->fetchRow($db->select('id')->from(Fediverse_Database::table('actors'))
                ->where('uid = ?', (int)$user['uid'])->limit(1));
            if (!$existing) {
                Fediverse_Core::ensureActor($user);
                $created++;
            }
        }
        return $created;
    }

    private function notice($message, $type)
    {
        Typecho_Widget::widget('Widget_Notice')->set((string)$message, (string)$type);
    }
}
