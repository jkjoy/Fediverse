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
                    $count = Fediverse_ActivityPub::pruneLogs();
                    $this->notice(_t('已清理 %d 条过期入站活动记录。', $count), 'success');
                    break;
                default:
                    $this->notice(_t('未知的联邦管理操作。'), 'error');
            }
        } catch (Throwable $e) {
            $this->notice(_t('操作失败：%s', $e->getMessage()), 'error');
        }

        $this->response->redirect(Typecho_Common::url(
            'extending.php?panel=Fediverse%2Fmanage.php',
            Typecho_Widget::widget('Widget_Options')->adminUrl
        ));
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
