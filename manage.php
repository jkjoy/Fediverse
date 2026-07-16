<?php

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$db = Typecho_Db::get();
$e = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$formatTime = static function ($timestamp) {
    $timestamp = (int)$timestamp;
    return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-';
};
$count = static function ($table, $where = null, $value = null) use ($db) {
    $query = $db->select(array('COUNT(*)' => 'num'))->from($table);
    if ($where !== null) {
        $query->where($where, $value);
    }
    $row = $db->fetchRow($query);
    return (int)($row['num'] ?? 0);
};

$actorsTable = Fediverse_Database::table('actors');
$followersTable = Fediverse_Database::table('followers');
$queueTable = Fediverse_Database::table('queue');
$activitiesTable = Fediverse_Database::table('activities');

$users = $db->fetchAll($db->select('uid', 'name', 'screenName')->from('table.users')->order('uid', Typecho_Db::SORT_ASC));
$actors = $db->fetchAll($db->select()->from($actorsTable));
$actorMap = array();
foreach ($actors as $actor) {
    $actorMap[(int)$actor['uid']] = $actor;
}
$userMap = array();
foreach ($users as $localUser) {
    $userMap[(int)$localUser['uid']] = $localUser;
}

$stats = array(
    'authors' => count($actors),
    'followers' => $count($followersTable, 'state = ?', 'accepted'),
    'queued' => $count($queueTable),
    'failed' => $count($queueTable, 'attempts >= ?', 8),
    'inbound' => $count($activitiesTable)
);

$views = array('overview', 'queue', 'followers', 'activities');
$view = (string)$request->get('view', 'overview');
if (!in_array($view, $views, true)) {
    $view = 'overview';
}
$panelUrl = static function ($target = 'overview') use ($options) {
    $query = 'extending.php?panel=Fediverse%2Fmanage.php';
    if ($target !== 'overview') {
        $query .= '&view=' . rawurlencode($target);
    }
    return Typecho_Common::url($query, $options->adminUrl);
};
$actionUrl = static function ($query) use ($security) {
    return $security->getIndex('/action/fediverse-admin?' . ltrim((string)$query, '?'));
};

include 'header.php';
include 'menu.php';
?>

<style>
html{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.fed-status{display:grid;grid-template-columns:repeat(5,minmax(110px,1fr));margin:16px 0 18px;border-top:1px solid #d9d9d6;border-bottom:1px solid #d9d9d6;background:#fafafa}
.fed-stat{padding:13px 14px;border-right:1px solid #e5e5e2}
.fed-stat:last-child{border-right:0}
.fed-stat strong{display:block;font-size:22px;line-height:1.2;color:#262626;font-variant-numeric:tabular-nums}
.fed-stat span{display:block;margin-top:4px;color:#777;font-size:12px}
.fed-stat.is-error strong{color:#b42318}
.fed-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 16px}
.fed-actions .btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;box-sizing:border-box;transition-property:scale;transition-duration:150ms;transition-timing-function:ease-out}
.fed-actions .btn:active{scale:.96}
.fed-actions .fed-spacer{flex:1}
.fed-endpoints{margin:12px 0 20px;padding:10px 0;border-top:1px solid #eee;border-bottom:1px solid #eee}
.fed-endpoint{display:grid;grid-template-columns:120px minmax(0,1fr);gap:12px;padding:5px 0;line-height:1.6;text-wrap:pretty}
.fed-endpoint code,.fed-break{word-break:break-all}
.fed-state{display:inline-block;padding:1px 6px;border-radius:4px;background:#edf7ed;color:#286b32;font-size:12px}
.fed-state.warn{background:#fff6df;color:#8a5a00}
.fed-state.error{background:#fff0ee;color:#a33226}
.fed-muted{color:#888}
.fed-table-actions{white-space:nowrap}
.fed-table-actions a{display:inline-flex;align-items:center;min-height:40px}
.fed-table-actions a+a{margin-left:8px}
.fed-tabs{margin-bottom:0}
.fed-tabs a{display:inline-flex;align-items:center;min-height:40px;box-sizing:border-box}
.fed-status,.typecho-list-table{font-variant-numeric:tabular-nums}
.typecho-table-wrap{overflow-x:auto}
.fed-actions a:focus-visible,.fed-tabs a:focus-visible,.fed-table-actions a:focus-visible{outline:2px solid #467b96;outline-offset:2px}
@media(max-width:760px){.fed-status{grid-template-columns:repeat(2,minmax(0,1fr))}.fed-stat{border-bottom:1px solid #e5e5e2}.fed-endpoint{grid-template-columns:1fr;gap:0}.typecho-list-table{min-width:760px}}
</style>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="col-group typecho-page-main">
            <div class="col-mb-12 typecho-list" role="main">
                <div class="fed-status" aria-label="联邦状态统计">
                    <div class="fed-stat"><strong><?php echo $stats['authors']; ?></strong><span><?php _e('已生成身份'); ?></span></div>
                    <div class="fed-stat"><strong><?php echo $stats['followers']; ?></strong><span><?php _e('关注者'); ?></span></div>
                    <div class="fed-stat"><strong><?php echo $stats['queued']; ?></strong><span><?php _e('投递任务'); ?></span></div>
                    <div class="fed-stat<?php if ($stats['failed'] > 0): ?> is-error<?php endif; ?>"><strong><?php echo $stats['failed']; ?></strong><span><?php _e('失败任务'); ?></span></div>
                    <div class="fed-stat"><strong><?php echo $stats['inbound']; ?></strong><span><?php _e('入站活动'); ?></span></div>
                </div>

                <div class="fed-actions">
                    <a class="btn btn-s primary" href="<?php echo $e($actionUrl('do=run-queue')); ?>"><?php _e('立即处理队列'); ?></a>
                    <a class="btn btn-s" href="<?php echo $e($actionUrl('do=provision-actors')); ?>"><?php _e('生成作者身份'); ?></a>
                    <a class="btn btn-s" href="<?php echo $e($actionUrl('do=prune-activities')); ?>"><?php _e('清理过期活动'); ?></a>
                    <span class="fed-spacer"></span>
                    <a href="<?php echo $e(Typecho_Common::url('options-plugin.php?config=Fediverse', $options->adminUrl)); ?>"><?php _e('插件设置'); ?></a>
                </div>

                <ul class="typecho-option-tabs fed-tabs">
                    <li<?php if ($view === 'overview'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('overview')); ?>"><?php _e('作者账号'); ?></a></li>
                    <li<?php if ($view === 'queue'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('queue')); ?>"><?php _e('投递队列'); ?></a></li>
                    <li<?php if ($view === 'followers'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('followers')); ?>"><?php _e('关注者'); ?></a></li>
                    <li<?php if ($view === 'activities'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('activities')); ?>"><?php _e('入站活动'); ?></a></li>
                </ul>

                <?php if ($view === 'overview'): ?>
                    <?php
                    $httpsReady = strtolower((string)parse_url((string)$options->siteUrl, PHP_URL_SCHEME)) === 'https';
                    $cronToken = (string)Fediverse_Core::setting('cronToken', '');
                    ?>
                    <div class="fed-endpoints">
                        <div class="fed-endpoint"><strong><?php _e('HTTPS'); ?></strong><span><span class="fed-state <?php echo $httpsReady ? '' : 'error'; ?>"><?php echo $httpsReady ? _t('正常') : _t('未启用'); ?></span></span></div>
                        <div class="fed-endpoint"><strong><?php _e('共享收件箱'); ?></strong><code><?php echo $e(Fediverse_Core::url('fediverse/inbox')); ?></code></div>
                        <div class="fed-endpoint"><strong><?php _e('WebFinger'); ?></strong><code><?php echo $e(Fediverse_Core::origin() . '/.well-known/webfinger'); ?></code></div>
                        <div class="fed-endpoint"><strong><?php _e('Cron'); ?></strong><code><?php echo $cronToken !== '' ? $e(Fediverse_Core::url('fediverse/cron/' . $cronToken)) : _t('尚未保存令牌'); ?></code></div>
                    </div>

                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup><col width="24%"><col width="34%"><col width="14%"><col width="18%"><col></colgroup>
                            <thead><tr><th><?php _e('作者'); ?></th><th><?php _e('联邦地址'); ?></th><th><?php _e('状态'); ?></th><th><?php _e('密钥生成时间'); ?></th><th><?php _e('Actor'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($users as $localUser): ?>
                                <?php
                                $uid = (int)$localUser['uid'];
                                $actor = $actorMap[$uid] ?? null;
                                $username = $actor ? $actor['username'] : Fediverse_Core::usernameForUser($localUser);
                                $enabled = Fediverse_Core::userEnabled($uid);
                                $actorUrl = Fediverse_Core::actorUrl($username);
                                ?>
                                <tr>
                                    <td><strong><?php echo $e($localUser['screenName'] ?: $localUser['name']); ?></strong><br><span class="fed-muted">UID <?php echo $uid; ?></span></td>
                                    <td class="fed-break"><code>@<?php echo $e($username); ?>@<?php echo $e(Fediverse_Core::domain()); ?></code></td>
                                    <td><span class="fed-state <?php echo !$enabled ? 'error' : ($actor ? '' : 'warn'); ?>"><?php echo !$enabled ? _t('作者已停用') : ($actor ? _t('可发现') : _t('待生成')); ?></span></td>
                                    <td><?php echo $actor ? $e($formatTime($actor['created'])) : '-'; ?></td>
                                    <td><?php if ($actor): ?><a href="<?php echo $e($actorUrl); ?>" target="_blank" rel="noopener noreferrer"><?php _e('查看'); ?></a><?php else: ?>-<?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$users): ?><tr><td colspan="5"><?php _e('没有作者账号'); ?></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($view === 'queue'): ?>
                    <?php $rows = $db->fetchAll($db->select()->from($queueTable)->order('qid', Typecho_Db::SORT_DESC)->limit(100)); ?>
                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup><col width="6%"><col width="12%"><col width="34%"><col width="10%"><col width="18%"><col></colgroup>
                            <thead><tr><th>ID</th><th><?php _e('活动'); ?></th><th><?php _e('收件箱'); ?></th><th><?php _e('尝试'); ?></th><th><?php _e('错误'); ?></th><th><?php _e('操作'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $activity = json_decode((string)$row['activity'], true); $failed = (int)$row['attempts'] >= 8; ?>
                                <tr>
                                    <td><?php echo (int)$row['qid']; ?></td>
                                    <td><strong><?php echo $e($activity['type'] ?? '-'); ?></strong><br><span class="fed-muted"><?php echo $e($formatTime($row['created'])); ?></span></td>
                                    <td class="fed-break"><a href="<?php echo $e($row['inbox']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($row['inbox']); ?></a></td>
                                    <td><span class="fed-state <?php echo $failed ? 'error' : ((int)$row['attempts'] > 0 ? 'warn' : ''); ?>"><?php echo $failed ? _t('已停止') : (int)$row['attempts']; ?></span></td>
                                    <td class="fed-break"><?php echo $row['last_error'] ? $e($row['last_error']) : '-'; ?></td>
                                    <td class="fed-table-actions"><a href="<?php echo $e($actionUrl('do=retry-queue&qid=' . (int)$row['qid'])); ?>"><?php _e('重试'); ?></a><a class="operate-delete" lang="<?php _e('确认删除此投递任务吗？'); ?>" href="<?php echo $e($actionUrl('do=delete-queue&qid=' . (int)$row['qid'])); ?>"><?php _e('删除'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?><tr><td colspan="6"><?php _e('投递队列为空'); ?></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($view === 'followers'): ?>
                    <?php $rows = $db->fetchAll($db->select()->from($followersTable)->order('id', Typecho_Db::SORT_DESC)->limit(100)); ?>
                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup><col width="18%"><col width="36%"><col width="32%"><col width="10%"><col></colgroup>
                            <thead><tr><th><?php _e('本地作者'); ?></th><th><?php _e('远端 Actor'); ?></th><th><?php _e('投递地址'); ?></th><th><?php _e('关注时间'); ?></th><th><?php _e('操作'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $owner = $userMap[(int)$row['uid']] ?? null; ?>
                                <tr>
                                    <td><?php echo $owner ? $e($owner['screenName'] ?: $owner['name']) : ('UID ' . (int)$row['uid']); ?></td>
                                    <td class="fed-break"><a href="<?php echo $e($row['actor']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($row['actor']); ?></a></td>
                                    <td class="fed-break"><?php echo $e($row['shared_inbox'] ?: $row['inbox']); ?></td>
                                    <td><?php echo $e($formatTime($row['created'])); ?></td>
                                    <td><a class="operate-delete" lang="<?php _e('确认移除此关注者吗？'); ?>" href="<?php echo $e($actionUrl('do=remove-follower&id=' . (int)$row['id'])); ?>"><?php _e('移除'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?><tr><td colspan="5"><?php _e('暂无关注者'); ?></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <?php $rows = $db->fetchAll($db->select()->from($activitiesTable)->order('aid', Typecho_Db::SORT_DESC)->limit(100)); ?>
                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup><col width="8%"><col width="12%"><col width="30%"><col width="30%"><col width="10%"><col></colgroup>
                            <thead><tr><th>ID</th><th><?php _e('类型'); ?></th><th><?php _e('Actor'); ?></th><th><?php _e('对象'); ?></th><th><?php _e('处理'); ?></th><th><?php _e('时间'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo (int)$row['aid']; ?></td>
                                    <td><strong><?php echo $e($row['type']); ?></strong></td>
                                    <td class="fed-break"><a href="<?php echo $e($row['actor']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($row['actor']); ?></a></td>
                                    <td class="fed-break"><?php echo $row['object_id'] ? $e($row['object_id']) : '-'; ?></td>
                                    <td><span class="fed-state <?php echo $row['status'] === 'ignored' ? 'warn' : ''; ?>"><?php echo $e($row['status']); ?></span></td>
                                    <td><?php echo $e($formatTime($row['created'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?><tr><td colspan="6"><?php _e('暂无入站活动'); ?></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
include 'copyright.php';
include 'common-js.php';
?>
<script>
(function(){
    var links=document.querySelectorAll('.operate-delete');
    for(var i=0;i<links.length;i++){
        links[i].addEventListener('click',function(event){
            if(!window.confirm(this.getAttribute('lang')||'')){event.preventDefault();}
        });
    }
})();
</script>
<?php include 'footer.php'; ?>
