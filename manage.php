<?php

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

if (class_exists('Fediverse_Plugin')) {
    Fediverse_Plugin::upgrade();
} else {
    Fediverse_Database::install();
}

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
$followingsTable = Fediverse_Database::table('followings');
$timelineTable = Fediverse_Database::table('timeline');

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
    'following' => $count($followingsTable, 'state = ?', 'accepted'),
    'queued' => $count($queueTable),
    'failed' => $count($queueTable, 'attempts >= ?', 8),
    'inbound' => $count($activitiesTable)
);

$views = array('overview', 'queue', 'followers', 'following', 'timeline', 'activities');
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
$actionUrl = static function ($query, $returnView = null) use ($security, $view) {
    $returnView = $returnView === null ? $view : (string)$returnView;
    return $security->getIndex('/action/fediverse-admin?' . ltrim((string)$query, '?') . '&view=' . rawurlencode($returnView));
};
$activityTypeLabel = static function ($type) {
    $labels = array(
        'Follow' => _t('关注'),
        'Accept' => _t('接受关注'),
        'Reject' => _t('拒绝关注'),
        'Create' => _t('回复'),
        'Update' => _t('编辑回复'),
        'Delete' => _t('删除回复'),
        'Like' => _t('点赞'),
        'Announce' => _t('转发'),
        'Undo' => _t('撤销')
    );
    return $labels[(string)$type] ?? (string)$type;
};
$activityStatus = static function ($status) {
    $status = (string)$status;
    if (preg_match('/^comment:(\d+)$/', $status, $matches)) {
        return array(_t('联邦评论 #%d', (int)$matches[1]), '', (int)$matches[1]);
    }
    $labels = array(
        'accepted' => array(_t('已接收'), '', 0),
        'ignored' => array(_t('已忽略'), 'warn', 0),
        'undone' => array(_t('已撤销'), 'muted', 0),
        'deleted' => array(_t('已删除'), 'muted', 0),
        'rejected' => array(_t('已拒绝'), 'error', 0),
        'timeline' => array(_t('已进入时间轴'), '', 0)
    );
    return $labels[$status] ?? array($status, 'warn', 0);
};
$actorLabel = static function ($actor) {
    $actor = (string)$actor;
    $host = (string)parse_url($actor, PHP_URL_HOST);
    $path = trim((string)parse_url($actor, PHP_URL_PATH), '/');
    $username = $path !== '' ? rawurldecode((string)basename($path)) : '';
    return $username !== '' && $host !== '' ? '@' . $username . '@' . $host : ($host ?: $actor);
};

include 'header.php';
include 'menu.php';
?>

<style>
html{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.fed-status{display:grid;grid-template-columns:repeat(6,minmax(100px,1fr));margin:16px 0 18px;border-top:1px solid #d9d9d6;border-bottom:1px solid #d9d9d6;background:#fafafa}
.fed-stat{padding:13px 14px;border-right:1px solid #e5e5e2}
.fed-stat:last-child{border-right:0}
.fed-stat strong{display:block;font-size:22px;line-height:1.2;color:#262626;font-variant-numeric:tabular-nums}
.fed-stat span{display:block;margin-top:4px;color:#777;font-size:12px}
.fed-stat.is-error strong{color:#b42318}
.fed-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 16px}
.fed-actions .btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;box-sizing:border-box;transition-property:scale;transition-duration:150ms;transition-timing-function:ease-out}
.fed-actions .btn:active{scale:.96}
.fed-action.is-busy{pointer-events:none;opacity:.65}
.fed-actions .fed-spacer{flex:1}
.fed-endpoints{margin:12px 0 20px;padding:10px 0;border-top:1px solid #eee;border-bottom:1px solid #eee}
.fed-endpoint{display:grid;grid-template-columns:120px minmax(0,1fr);gap:12px;padding:5px 0;line-height:1.6;text-wrap:pretty}
.fed-endpoint code,.fed-break{word-break:break-all}
.fed-value{display:flex;align-items:center;gap:8px;min-width:0}
.fed-value code{min-width:0}
.fed-copy{flex:0 0 auto;min-height:40px;padding:0 9px;border:1px solid #d5d5d2;border-radius:4px;background:#fff;color:#555;cursor:pointer}
.fed-copy:hover{border-color:#b8b8b4;color:#222}
.fed-state{display:inline-block;padding:1px 6px;border-radius:4px;background:#edf7ed;color:#286b32;font-size:12px}
.fed-state.warn{background:#fff6df;color:#8a5a00}
.fed-state.error{background:#fff0ee;color:#a33226}
.fed-state.muted{background:#f0f0ee;color:#666}
.fed-muted{color:#888}
.fed-follow-form{display:grid;grid-template-columns:minmax(160px,220px) minmax(260px,1fr) auto;gap:10px;align-items:end;margin:16px 0 20px;padding:14px 0;border-top:1px solid #eee;border-bottom:1px solid #eee}
.fed-field label{display:block;margin-bottom:5px;color:#555;font-size:12px;font-weight:600}
.fed-field input,.fed-field select{width:100%;min-height:40px;box-sizing:border-box}
.fed-follow-form .btn{min-height:40px;box-sizing:border-box}
.fed-feed{border-top:1px solid #e5e5e2}
.fed-feed-item{padding:17px 0;border-bottom:1px solid #e5e5e2}
.fed-feed-head{display:flex;align-items:flex-start;gap:12px}
.fed-feed-identity{min-width:0;flex:1}
.fed-feed-identity strong,.fed-feed-identity a{overflow-wrap:anywhere}
.fed-feed-time{flex:0 0 auto;color:#888;font-size:12px;font-variant-numeric:tabular-nums}
.fed-feed-content{margin:10px 0;color:#333;font-size:14px;line-height:1.75;white-space:normal;overflow-wrap:anywhere}
.fed-feed-actions{display:flex;align-items:center;gap:14px;min-height:40px;flex-wrap:wrap}
.fed-feed-actions>a,.fed-reply summary{display:inline-flex;align-items:center;min-height:40px;color:#467b96;cursor:pointer}
.fed-feed-actions .is-active{color:#8a3f64;font-weight:600}
.fed-reply[open]{order:2;flex:1 0 100%}
.fed-reply summary{list-style:none}
.fed-reply summary::-webkit-details-marker{display:none}
.fed-reply form{max-width:520px;margin:4px 0 8px}
.fed-reply textarea{display:block;width:100%;min-height:84px;padding:8px;box-sizing:border-box;resize:vertical}
.fed-reply .btn{min-height:40px;margin-top:8px}
.fed-empty{padding:34px 0;text-align:center;color:#777}
.fed-empty strong{display:block;margin-bottom:5px;color:#444;font-size:15px}
.fed-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.fed-table-actions{white-space:nowrap}
.fed-table-actions a{display:inline-flex;align-items:center;min-height:40px}
.fed-table-actions a+a{margin-left:8px}
.fed-tabs{margin-bottom:0;overflow-x:auto;white-space:nowrap}
.fed-tabs a{display:inline-flex;align-items:center;min-height:40px;box-sizing:border-box}
.fed-status,.typecho-list-table{font-variant-numeric:tabular-nums}
.typecho-table-wrap{overflow-x:auto}
.fed-actions a:focus-visible,.fed-tabs a:focus-visible,.fed-table-actions a:focus-visible,.fed-copy:focus-visible,.fed-feed-actions a:focus-visible,.fed-reply summary:focus-visible{outline:2px solid #467b96;outline-offset:2px}
.fed-live{position:fixed;right:20px;bottom:20px;z-index:1000;padding:9px 12px;border-radius:4px;background:#262626;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.18)}
.fed-live[hidden]{display:none}
@media(max-width:760px){.fed-status{grid-template-columns:repeat(2,minmax(0,1fr))}.fed-stat{border-bottom:1px solid #e5e5e2}.fed-endpoint{grid-template-columns:1fr;gap:0}.typecho-list-table{min-width:760px}.fed-follow-form{grid-template-columns:1fr;align-items:stretch}.fed-feed-head{flex-direction:column;gap:3px}.fed-feed-time{order:2}}
</style>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="col-group typecho-page-main">
            <div class="col-mb-12 typecho-list" role="main">
                <div class="fed-status" aria-label="联邦状态统计">
                    <div class="fed-stat"><strong><?php echo $stats['authors']; ?></strong><span><?php _e('已生成身份'); ?></span></div>
                    <div class="fed-stat"><strong><?php echo $stats['followers']; ?></strong><span><?php _e('关注者'); ?></span></div>
                    <div class="fed-stat"><strong><?php echo $stats['following']; ?></strong><span><?php _e('正在关注'); ?></span></div>
                    <div class="fed-stat"><strong><?php echo $stats['queued']; ?></strong><span><?php _e('投递任务'); ?></span></div>
                    <div class="fed-stat<?php if ($stats['failed'] > 0): ?> is-error<?php endif; ?>"><strong><?php echo $stats['failed']; ?></strong><span><?php _e('失败任务'); ?></span></div>
                    <div class="fed-stat"><strong><?php echo $stats['inbound']; ?></strong><span><?php _e('入站活动'); ?></span></div>
                </div>

                <div class="fed-actions">
                    <a class="btn btn-s primary fed-action" data-busy="<?php _e('正在处理…'); ?>" href="<?php echo $e($actionUrl('do=run-queue', 'queue')); ?>"><?php _e('立即处理队列'); ?></a>
                    <a class="btn btn-s fed-action" data-busy="<?php _e('正在生成…'); ?>" href="<?php echo $e($actionUrl('do=provision-actors', 'overview')); ?>"><?php _e('生成作者身份'); ?></a>
                    <a class="btn btn-s fed-action" data-busy="<?php _e('正在同步…'); ?>" href="<?php echo $e($actionUrl('do=broadcast-profiles', 'queue')); ?>"><?php _e('同步作者资料'); ?></a>
                    <a class="btn btn-s fed-action" data-busy="<?php _e('正在补发…'); ?>" data-confirm="<?php _e('确认向现有关注者重新投递最近 20 篇公开文章吗？'); ?>" href="<?php echo $e($actionUrl('do=resend-recent-posts', 'queue')); ?>"><?php _e('补发最近文章'); ?></a>
                    <a class="btn btn-s fed-action operate-delete" data-busy="<?php _e('正在清理…'); ?>" data-confirm="<?php _e('确认清理超过保留天数的入站活动和时间轴内容吗？'); ?>" href="<?php echo $e($actionUrl('do=prune-activities', 'activities')); ?>"><?php _e('清理过期内容'); ?></a>
                    <span class="fed-spacer"></span>
                    <a href="<?php echo $e(Typecho_Common::url('options-plugin.php?config=Fediverse', $options->adminUrl)); ?>"><?php _e('插件设置'); ?></a>
                </div>

                <ul class="typecho-option-tabs fed-tabs">
                    <li<?php if ($view === 'overview'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('overview')); ?>"><?php _e('作者账号'); ?></a></li>
                    <li<?php if ($view === 'queue'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('queue')); ?>"><?php _e('投递队列'); ?></a></li>
                    <li<?php if ($view === 'followers'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('followers')); ?>"><?php _e('关注者'); ?></a></li>
                    <li<?php if ($view === 'following'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('following')); ?>"><?php _e('正在关注'); ?></a></li>
                    <li<?php if ($view === 'timeline'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('timeline')); ?>"><?php _e('时间轴'); ?></a></li>
                    <li<?php if ($view === 'activities'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('activities')); ?>"><?php _e('入站活动'); ?></a></li>
                </ul>

                <?php if ($view === 'overview'): ?>
                    <?php
                    $httpsReady = strtolower((string)parse_url((string)$options->siteUrl, PHP_URL_SCHEME)) === 'https';
                    $cronToken = (string)Fediverse_Core::setting('cronToken', '');
                    ?>
                    <div class="fed-endpoints">
                        <div class="fed-endpoint"><strong><?php _e('HTTPS'); ?></strong><span><span class="fed-state <?php echo $httpsReady ? '' : 'error'; ?>"><?php echo $httpsReady ? _t('正常') : _t('未启用'); ?></span></span></div>
                        <?php $sharedInbox = Fediverse_Core::url('fediverse/inbox'); ?>
                        <?php $webfingerUrl = Fediverse_Core::origin() . '/.well-known/webfinger'; ?>
                        <?php $cronUrl = $cronToken !== '' ? Fediverse_Core::url('fediverse/cron/' . $cronToken) : ''; ?>
                        <div class="fed-endpoint"><strong><?php _e('共享收件箱'); ?></strong><span class="fed-value"><code><?php echo $e($sharedInbox); ?></code><button class="fed-copy" type="button" data-copy="<?php echo $e($sharedInbox); ?>"><?php _e('复制'); ?></button></span></div>
                        <div class="fed-endpoint"><strong><?php _e('WebFinger'); ?></strong><span class="fed-value"><code><?php echo $e($webfingerUrl); ?></code><button class="fed-copy" type="button" data-copy="<?php echo $e($webfingerUrl); ?>"><?php _e('复制'); ?></button></span></div>
                        <div class="fed-endpoint"><strong><?php _e('Cron'); ?></strong><span class="fed-value"><code><?php echo $cronUrl !== '' ? $e($cronUrl) : _t('尚未保存令牌'); ?></code><?php if ($cronUrl !== ''): ?><button class="fed-copy" type="button" data-copy="<?php echo $e($cronUrl); ?>"><?php _e('复制'); ?></button><?php endif; ?></span></div>
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
                                    <?php $handle = '@' . $username . '@' . Fediverse_Core::domain(); ?>
                                    <td class="fed-break"><span class="fed-value"><code><?php echo $e($handle); ?></code><button class="fed-copy" type="button" data-copy="<?php echo $e($handle); ?>"><?php _e('复制'); ?></button></span></td>
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
                                    <td class="fed-table-actions"><a class="fed-action" data-busy="<?php _e('处理中…'); ?>" href="<?php echo $e($actionUrl('do=retry-queue&qid=' . (int)$row['qid'], 'queue')); ?>"><?php _e('重试'); ?></a><a class="fed-action operate-delete" data-busy="<?php _e('删除中…'); ?>" data-confirm="<?php _e('确认删除此投递任务吗？'); ?>" href="<?php echo $e($actionUrl('do=delete-queue&qid=' . (int)$row['qid'], 'queue')); ?>"><?php _e('删除'); ?></a></td>
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
                                    <td><a class="fed-action operate-delete" data-busy="<?php _e('移除中…'); ?>" data-confirm="<?php _e('确认移除此关注者吗？'); ?>" href="<?php echo $e($actionUrl('do=remove-follower&id=' . (int)$row['id'], 'followers')); ?>"><?php _e('移除'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?><tr><td colspan="5"><?php _e('暂无关注者'); ?></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($view === 'following'): ?>
                    <?php $rows = $db->fetchAll($db->select()->from($followingsTable)->order('id', Typecho_Db::SORT_DESC)->limit(100)); ?>
                    <form class="fed-follow-form" method="post" action="<?php echo $e($actionUrl('do=follow-remote', 'following')); ?>">
                        <div class="fed-field">
                            <label for="fed-follow-uid"><?php _e('使用作者身份'); ?></label>
                            <select id="fed-follow-uid" name="uid" required>
                                <?php foreach ($users as $localUser): ?>
                                    <?php if (Fediverse_Core::userEnabled((int)$localUser['uid'])): ?>
                                        <option value="<?php echo (int)$localUser['uid']; ?>"><?php echo $e($localUser['screenName'] ?: $localUser['name']); ?> · @<?php echo $e(Fediverse_Core::usernameForUser($localUser)); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fed-field">
                            <label for="fed-follow-account"><?php _e('远端联邦账号'); ?></label>
                            <input id="fed-follow-account" name="account" type="text" maxlength="512" placeholder="@user@example.com" autocomplete="off" spellcheck="false" required>
                        </div>
                        <button class="btn btn-s primary fed-action" data-busy="<?php _e('正在查找…'); ?>" type="submit"><?php _e('关注'); ?></button>
                    </form>
                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup><col width="20%"><col width="42%"><col width="16%"><col width="14%"><col></colgroup>
                            <thead><tr><th><?php _e('本地作者'); ?></th><th><?php _e('远端账号'); ?></th><th><?php _e('状态'); ?></th><th><?php _e('更新时间'); ?></th><th><?php _e('操作'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $owner = $userMap[(int)$row['uid']] ?? null; ?>
                                <tr>
                                    <td><?php echo $owner ? $e($owner['screenName'] ?: $owner['name']) : ('UID ' . (int)$row['uid']); ?></td>
                                    <td class="fed-break"><strong><?php echo $e($actorLabel($row['actor'])); ?></strong><br><a href="<?php echo $e($row['actor']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($row['actor']); ?></a></td>
                                    <td><span class="fed-state <?php echo $row['state'] === 'accepted' ? '' : ($row['state'] === 'rejected' ? 'error' : 'warn'); ?>"><?php echo $row['state'] === 'accepted' ? _t('已关注') : ($row['state'] === 'rejected' ? _t('已拒绝') : _t('等待确认')); ?></span></td>
                                    <td><?php echo $e($formatTime($row['updated'])); ?></td>
                                    <td><a class="fed-action operate-delete" data-busy="<?php _e('取消中…'); ?>" data-confirm="<?php _e('确认取消关注此远端账号吗？'); ?>" href="<?php echo $e($actionUrl('do=unfollow-remote&id=' . (int)$row['id'], 'following')); ?>"><?php _e('取消关注'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?><tr><td colspan="5"><?php _e('尚未关注远端账号，可在上方输入完整联邦地址。'); ?></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($view === 'timeline'): ?>
                    <?php $rows = $db->fetchAll($db->select()->from($timelineTable)->where('deleted = ?', 0)->order('published', Typecho_Db::SORT_DESC)->limit(50)); ?>
                    <?php if ($rows): ?>
                        <div class="fed-feed" aria-label="<?php _e('联邦时间轴'); ?>">
                            <?php foreach ($rows as $row): ?>
                                <?php $owner = $userMap[(int)$row['uid']] ?? null; ?>
                                <article class="fed-feed-item">
                                    <header class="fed-feed-head">
                                        <div class="fed-feed-identity">
                                            <strong><a href="<?php echo $e($row['actor']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($actorLabel($row['actor'])); ?></a></strong>
                                            <div class="fed-muted"><?php echo $owner ? $e(_t('投递给 %s', $owner['screenName'] ?: $owner['name'])) : ('UID ' . (int)$row['uid']); ?> · <?php echo $e($row['object_type']); ?></div>
                                        </div>
                                        <time class="fed-feed-time" datetime="<?php echo $e(Fediverse_Core::iso8601((int)$row['published'])); ?>"><?php echo $e($formatTime($row['published'])); ?></time>
                                    </header>
                                    <div class="fed-feed-content"><?php echo nl2br($e($row['content']), false); ?></div>
                                    <div class="fed-feed-actions">
                                        <a class="fed-action<?php if ($row['liked_activity']): ?> is-active<?php endif; ?>" data-busy="<?php _e('处理中…'); ?>" href="<?php echo $e($actionUrl('do=' . ($row['liked_activity'] ? 'timeline-unlike' : 'timeline-like') . '&tid=' . (int)$row['tid'], 'timeline')); ?>"><?php echo $row['liked_activity'] ? _t('取消点赞') : _t('点赞'); ?></a>
                                        <a class="fed-action<?php if ($row['announced_activity']): ?> is-active<?php endif; ?>" data-busy="<?php _e('处理中…'); ?>" href="<?php echo $e($actionUrl('do=' . ($row['announced_activity'] ? 'timeline-unannounce' : 'timeline-announce') . '&tid=' . (int)$row['tid'], 'timeline')); ?>"><?php echo $row['announced_activity'] ? _t('取消转发') : _t('转发'); ?></a>
                                        <details class="fed-reply">
                                            <summary><?php _e('回复'); ?></summary>
                                            <form method="post" action="<?php echo $e($actionUrl('do=timeline-reply', 'timeline')); ?>">
                                                <input type="hidden" name="tid" value="<?php echo (int)$row['tid']; ?>">
                                                <label class="fed-sr-only" for="fed-reply-<?php echo (int)$row['tid']; ?>"><?php _e('回复内容'); ?></label>
                                                <textarea id="fed-reply-<?php echo (int)$row['tid']; ?>" name="content" maxlength="5000" required></textarea>
                                                <button class="btn btn-s primary fed-action" data-busy="<?php _e('发送中…'); ?>" type="submit"><?php _e('发送回复'); ?></button>
                                            </form>
                                        </details>
                                        <a href="<?php echo $e($row['url']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php _e('查看原文'); ?></a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="fed-empty"><strong><?php _e('时间轴还是空的'); ?></strong><span><?php _e('关注远端账号并等待对方接受后，新内容会由远端投递到这里。'); ?></span><br><a href="<?php echo $e($panelUrl('following')); ?>"><?php _e('添加关注'); ?></a></div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php $rows = $db->fetchAll($db->select()->from($activitiesTable)->order('aid', Typecho_Db::SORT_DESC)->limit(100)); ?>
                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup><col width="8%"><col width="12%"><col width="30%"><col width="30%"><col width="10%"><col></colgroup>
                            <thead><tr><th>ID</th><th><?php _e('类型'); ?></th><th><?php _e('Actor'); ?></th><th><?php _e('对象'); ?></th><th><?php _e('处理'); ?></th><th><?php _e('时间'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $status = $activityStatus($row['status']); ?>
                                <tr>
                                    <td><?php echo (int)$row['aid']; ?></td>
                                    <td><strong><?php echo $e($activityTypeLabel($row['type'])); ?></strong><br><span class="fed-muted"><?php echo $e($row['type']); ?></span></td>
                                    <td class="fed-break"><a href="<?php echo $e($row['actor']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($row['actor']); ?></a></td>
                                    <td class="fed-break"><?php if ($row['object_id'] && filter_var($row['object_id'], FILTER_VALIDATE_URL)): ?><a href="<?php echo $e($row['object_id']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($row['object_id']); ?></a><?php else: ?><?php echo $row['object_id'] ? $e($row['object_id']) : '-'; ?><?php endif; ?></td>
                                    <td><span class="fed-state <?php echo $e($status[1]); ?>"><?php echo $e($status[0]); ?></span><?php if ($status[2]): ?><br><a href="<?php echo $e(Typecho_Common::url('manage-comments.php?status=waiting', $options->adminUrl)); ?>"><?php _e('前往审核'); ?></a><?php endif; ?></td>
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

<div class="fed-live" role="status" aria-live="polite" hidden></div>

<?php
include 'copyright.php';
include 'common-js.php';
?>
<script>
(function(){
    var actions=document.querySelectorAll('.fed-action');
    for(var i=0;i<actions.length;i++){
        actions[i].addEventListener('click',function(event){
            var prompt=this.getAttribute('data-confirm');
            if(prompt&&!window.confirm(prompt)){event.preventDefault();return;}
            if(this.classList.contains('is-busy')){event.preventDefault();return;}
            this.classList.add('is-busy');
            this.setAttribute('aria-disabled','true');
            var busy=this.getAttribute('data-busy');
            if(busy){this.textContent=busy;}
        });
    }
    var live=document.querySelector('.fed-live');
    var copies=document.querySelectorAll('.fed-copy');
    function copied(){
        live.textContent='<?php _e('已复制'); ?>';live.hidden=false;
        window.setTimeout(function(){live.hidden=true;},1600);
    }
    function legacyCopy(value){
        var input=document.createElement('textarea');input.value=value;input.setAttribute('readonly','');input.style.position='fixed';input.style.opacity='0';document.body.appendChild(input);input.select();
        if(document.execCommand('copy')){copied();}document.body.removeChild(input);
    }
    for(var j=0;j<copies.length;j++){
        copies[j].addEventListener('click',function(){
            var value=this.getAttribute('data-copy')||'';
            if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(value).then(copied,function(){legacyCopy(value);});return;}
            legacyCopy(value);
        });
    }
})();
</script>
<?php include 'footer.php'; ?>
