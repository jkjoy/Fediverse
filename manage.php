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
        str_contains((string)$where, '?') ? $query->where($where, $value) : $query->where($where);
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
$timelineMetaTable = Fediverse_Database::table('timeline_meta');
$moderationTable = Fediverse_Database::table('moderation');
$notificationsTable = Fediverse_Database::table('notifications');
$bookmarksTable = Fediverse_Database::table('bookmarks');
$remoteActorsTable = Fediverse_Database::table('remote_actors');

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
$moderationRows = $db->fetchAll($db->select()->from($moderationTable)
    ->order('updated', Typecho_Db::SORT_DESC)->limit(500));
$moderationMap = array();
foreach ($moderationRows as $moderation) {
    $moderationMap[(int)$moderation['uid'] . ':' . (string)$moderation['actor_hash']] = (string)$moderation['mode'];
}

$stats = array(
    'authors' => count($actors),
    'followers' => $count($followersTable, 'state = ?', 'accepted'),
    'following' => $count($followingsTable, 'state = ?', 'accepted'),
    'queued' => $count($queueTable),
    'failed' => $count($queueTable, 'attempts >= ?', 8),
    'inbound' => $count($activitiesTable),
    'unread' => $count($notificationsTable, 'read_at IS NULL')
);

$views = array('overview', 'queue', 'notifications', 'followers', 'following', 'timeline', 'activities');
$view = isset($fediverseView) ? (string)$fediverseView : (string)$request->get('view', 'overview');
if (!in_array($view, $views, true)) {
    $view = 'overview';
}
$isSocialView = in_array($view, array('notifications', 'followers', 'following', 'timeline'), true);
$timelinePage = max(1, (int)$request->get('page', 1));
$timelineUid = max(0, (int)$request->get('uid', 0));
$timelineSearch = trim(Typecho_Common::subStr((string)$request->get('q', ''), 0, 100, ''));
$timelineBookmarked = (int)$request->get('bookmarked', 0) === 1;
$panelUrl = static function ($target = 'overview') use ($options) {
    $socialPanels = array(
        'notifications' => 'Fediverse%2Fnotifications.php',
        'timeline' => 'Fediverse%2Ftimeline.php',
        'followers' => 'Fediverse%2Ffollowers.php',
        'following' => 'Fediverse%2Ffollowing.php'
    );
    if (isset($socialPanels[$target])) {
        return Typecho_Common::url('extending.php?panel=' . $socialPanels[$target], $options->adminUrl);
    }
    $query = 'extending.php?panel=Fediverse%2Fmanage.php';
    if ($target !== 'overview') {
        $query .= '&view=' . rawurlencode($target);
    }
    return Typecho_Common::url($query, $options->adminUrl);
};
$actionUrl = static function ($query, $returnView = null) use (
    $security,
    $view,
    $timelinePage,
    $timelineUid,
    $timelineSearch,
    $timelineBookmarked
) {
    $returnView = $returnView === null ? $view : (string)$returnView;
    $query = ltrim((string)$query, '?') . '&view=' . rawurlencode($returnView);
    if ($returnView === 'timeline') {
        if ($timelinePage > 1) {
            $query .= '&page=' . $timelinePage;
        }
        if ($timelineUid > 0) {
            $query .= '&uid=' . $timelineUid;
        }
        if ($timelineSearch !== '') {
            $query .= '&q=' . rawurlencode($timelineSearch);
        }
        if ($timelineBookmarked) {
            $query .= '&bookmarked=1';
        }
    }
    return $security->getIndex('/action/fediverse-admin?' . $query);
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
$remoteActorMap = array();
$actorLabel = static function ($actor) use (&$remoteActorMap) {
    $actor = (string)$actor;
    $cached = $remoteActorMap[hash('sha256', $actor)] ?? null;
    $host = (string)parse_url($actor, PHP_URL_HOST);
    $path = trim((string)parse_url($actor, PHP_URL_PATH), '/');
    $username = (string)($cached['username'] ?? '');
    if ($username === '') {
        $username = $path !== '' ? rawurldecode((string)basename($path)) : '';
    }
    $account = $username !== '' && $host !== '' ? '@' . $username . '@' . $host : ($host ?: $actor);
    $name = trim((string)($cached['name'] ?? ''));
    return $name !== '' && $name !== $account ? $name . ' (' . $account . ')' : $account;
};
$moderationMode = static function ($uid, $actor) use ($moderationMap) {
    return $moderationMap[(int)$uid . ':' . hash('sha256', (string)$actor)] ?? '';
};
$visibilityLabel = static function ($visibility) {
    $labels = array(
        'public' => _t('公开'),
        'unlisted' => _t('不列出'),
        'followers' => _t('仅关注者'),
        'unknown' => _t('受限')
    );
    return $labels[(string)$visibility] ?? $labels['unknown'];
};
$notificationLabel = static function ($type) {
    $labels = array(
        'Follow' => _t('开始关注你'),
        'Accept' => _t('接受了你的关注请求'),
        'Reject' => _t('拒绝了你的关注请求'),
        'Reply' => _t('回复了你的文章或评论'),
        'Like' => _t('赞了你的文章'),
        'Announce' => _t('转发了你的文章')
    );
    return $labels[(string)$type] ?? (string)$type;
};

include 'header.php';
include 'menu.php';
?>

<style>
html{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.fed-status{display:grid;grid-template-columns:repeat(7,minmax(100px,1fr));margin:16px 0 18px;border-top:1px solid #d9d9d6;border-bottom:1px solid #d9d9d6;background:#fafafa}
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
.fed-cron-help{margin:4px 0 20px;border-bottom:1px solid #eee}
.fed-cron-help>summary{display:flex;align-items:center;min-height:44px;color:#467b96;font-weight:600;cursor:pointer;list-style:none}
.fed-cron-help>summary::-webkit-details-marker{display:none}
.fed-cron-help>summary::after{content:"";width:7px;height:7px;margin-left:10px;border-right:1.5px solid currentColor;border-bottom:1.5px solid currentColor;transform:rotate(45deg);transition:transform 160ms ease-out}
.fed-cron-help[open]>summary::after{transform:rotate(225deg)}
.fed-cron-help__body{padding:2px 0 14px;line-height:1.7}
.fed-cron-help__body h3{margin:16px 0 5px;font-size:14px}
.fed-cron-help__body p{margin:5px 0;color:#555}
.fed-cron-help__body ol{margin:6px 0;padding-left:22px}
.fed-cron-command{display:flex;align-items:center;gap:8px;max-width:100%;margin:8px 0}
.fed-cron-command code{display:block;min-width:0;padding:8px 10px;border:1px solid #e5e5e2;background:#fafafa;word-break:break-all}
.fed-state{display:inline-block;padding:1px 6px;border-radius:4px;background:#edf7ed;color:#286b32;font-size:12px}
.fed-state.warn{background:#fff6df;color:#8a5a00}
.fed-state.error{background:#fff0ee;color:#a33226}
.fed-state.muted{background:#f0f0ee;color:#666}
.fed-muted{color:#888}
.fed-follow-form{display:grid;grid-template-columns:minmax(160px,220px) minmax(260px,1fr) auto;gap:10px;align-items:end;margin:16px 0 20px;padding:14px 0;border-top:1px solid #eee;border-bottom:1px solid #eee}
.fed-field label{display:block;margin-bottom:5px;color:#555;font-size:12px;font-weight:600}
.fed-field input,.fed-field select{width:100%;min-height:40px;box-sizing:border-box}
.fed-follow-form .btn{min-height:40px;box-sizing:border-box}
.fed-timeline-filter{display:grid;grid-template-columns:minmax(150px,220px) minmax(220px,1fr) auto auto auto;gap:10px;align-items:end;margin:14px 0 18px;padding:12px 0;border-top:1px solid #eee;border-bottom:1px solid #eee}
.fed-timeline-filter .btn{min-height:40px;box-sizing:border-box}
.fed-timeline-filter>a,.fed-filter-check{display:inline-flex;align-items:center;min-height:40px;box-sizing:border-box}
.fed-filter-check{white-space:nowrap;color:#555}
.fed-pagination{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;padding:16px 0;color:#777;font-size:12px}
.fed-pagination a{display:inline-flex;align-items:center;min-height:40px}
.fed-pagination a:last-child{justify-self:end}
.fed-pagination strong{font-weight:400;text-align:center}
.fed-feed{border-top:1px solid #e5e5e2}
.fed-feed-item{padding:17px 0;border-bottom:1px solid #e5e5e2}
.fed-feed-head{display:flex;align-items:flex-start;gap:12px}
.fed-feed-identity{min-width:0;flex:1}
.fed-feed-identity strong,.fed-feed-identity a{overflow-wrap:anywhere}
.fed-feed-time{flex:0 0 auto;color:#888;font-size:12px;font-variant-numeric:tabular-nums}
.fed-feed-content{margin:10px 0;color:#333;font-size:14px;line-height:1.75;white-space:normal;overflow-wrap:anywhere}
.fed-feed-context{margin:10px 0 0;padding:8px 10px;border-left:3px solid #d8d8d4;background:#f7f7f5;color:#666;line-height:1.55;overflow-wrap:anywhere}
.fed-feed-context strong{display:block;margin-bottom:2px;color:#444;font-size:12px}
.fed-feed-boost{margin:0 0 8px;color:#666;font-size:12px;overflow-wrap:anywhere}
.fed-feed-warning{margin:10px 0 0;border-left:3px solid #c79a38;background:#fff8e8}
.fed-feed-warning summary{padding:8px 10px;color:#654b16;font-weight:600;cursor:pointer}
.fed-feed-warning .fed-feed-content{margin:0;padding:2px 10px 10px}
.fed-feed-media{display:grid;gap:6px;margin:10px 0;padding:0;list-style:none}
.fed-feed-media a{overflow-wrap:anywhere}
.fed-feed-tags{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0;color:#666;font-size:12px}
.fed-feed-poll{margin:12px 0;padding:10px 0;border-top:1px solid #e5e5e2;border-bottom:1px solid #e5e5e2}
.fed-poll-option{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;margin:7px 0;font-size:13px}
.fed-poll-track{grid-column:1/-1;height:4px;background:#e6e6e2;overflow:hidden}
.fed-poll-track span{display:block;height:100%;background:#467b96}
.fed-poll-meta{margin-top:8px;color:#777;font-size:12px}
.fed-moderation{display:inline-flex;gap:10px;align-items:center;flex-wrap:wrap}
.fed-notification-unread td:first-child{border-left:3px solid #467b96}
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
.fed-actions a:focus-visible,.fed-tabs a:focus-visible,.fed-table-actions a:focus-visible,.fed-copy:focus-visible,.fed-feed-actions a:focus-visible,.fed-reply summary:focus-visible,.fed-cron-help>summary:focus-visible,.fed-pagination a:focus-visible{outline:2px solid #467b96;outline-offset:2px}
.fed-live{position:fixed;right:20px;bottom:20px;z-index:1000;padding:9px 12px;border-radius:4px;background:#262626;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.18)}
.fed-live[hidden]{display:none}
@media(max-width:760px){.fed-status{grid-template-columns:repeat(2,minmax(0,1fr))}.fed-stat{border-bottom:1px solid #e5e5e2}.fed-endpoint{grid-template-columns:1fr;gap:0}.typecho-list-table{min-width:760px}.fed-follow-form,.fed-timeline-filter{grid-template-columns:1fr;align-items:stretch}.fed-feed-head{flex-direction:column;gap:3px}.fed-feed-time{order:2}.fed-pagination{grid-template-columns:auto 1fr auto}.fed-pagination strong{font-size:11px}}
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
                    <div class="fed-stat"><strong><?php echo $stats['unread']; ?></strong><span><?php _e('未读通知'); ?></span></div>
                </div>

                <?php if (!$isSocialView): ?>
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
                    <li<?php if ($view === 'activities'): ?> class="current"<?php endif; ?>><a href="<?php echo $e($panelUrl('activities')); ?>"><?php _e('入站活动'); ?></a></li>
                </ul>
                <?php endif; ?>

                <?php if ($view === 'overview'): ?>
                    <?php
                    $httpsReady = strtolower((string)parse_url((string)$options->siteUrl, PHP_URL_SCHEME)) === 'https';
                    $domainReady = Fediverse_Core::hasDomain();
                    $autoDelivery = (string)Fediverse_Core::setting('autoDelivery', '1') === '1';
                    $autoDeliveryBatch = max(1, min(10, (int)Fediverse_Core::setting('autoDeliveryBatch', 1)));
                    $cronToken = (string)Fediverse_Core::setting('cronToken', '');
                    ?>
                    <div class="fed-endpoints">
                        <div class="fed-endpoint"><strong><?php _e('HTTPS'); ?></strong><span><span class="fed-state <?php echo $httpsReady ? '' : 'error'; ?>"><?php echo $httpsReady ? _t('正常') : _t('未启用'); ?></span></span></div>
                        <div class="fed-endpoint"><strong><?php _e('联邦域名'); ?></strong><span><span class="fed-state <?php echo $domainReady ? '' : 'error'; ?>"><?php echo $domainReady ? $e(Fediverse_Core::domain()) : _t('未设置，请先打开插件设置'); ?></span></span></div>
                        <div class="fed-endpoint"><strong><?php _e('自动投递'); ?></strong><span><span class="fed-state <?php echo $autoDelivery ? '' : 'warn'; ?>"><?php echo $autoDelivery ? $e(_t('已开启，每次最多 %d 条', $autoDeliveryBatch)) : _t('已关闭，依赖 Cron 或手动处理'); ?></span></span></div>
                        <?php $sharedInbox = $domainReady ? Fediverse_Core::url('fediverse/inbox') : ''; ?>
                        <?php $webfingerUrl = $domainReady ? Fediverse_Core::origin() . '/.well-known/webfinger' : ''; ?>
                        <?php $cronUrl = $domainReady && $cronToken !== '' ? Fediverse_Core::url('fediverse/cron/' . $cronToken) : ''; ?>
                        <div class="fed-endpoint"><strong><?php _e('共享收件箱'); ?></strong><span class="fed-value"><code><?php echo $sharedInbox !== '' ? $e($sharedInbox) : _t('配置联邦域名后生成'); ?></code><?php if ($sharedInbox !== ''): ?><button class="fed-copy" type="button" data-copy="<?php echo $e($sharedInbox); ?>"><?php _e('复制'); ?></button><?php endif; ?></span></div>
                        <div class="fed-endpoint"><strong><?php _e('WebFinger'); ?></strong><span class="fed-value"><code><?php echo $webfingerUrl !== '' ? $e($webfingerUrl) : _t('配置联邦域名后生成'); ?></code><?php if ($webfingerUrl !== ''): ?><button class="fed-copy" type="button" data-copy="<?php echo $e($webfingerUrl); ?>"><?php _e('复制'); ?></button><?php endif; ?></span></div>
                        <div class="fed-endpoint"><strong><?php _e('Cron（可选）'); ?></strong><span class="fed-value"><code><?php echo $cronUrl !== '' ? $e($cronUrl) : (!$domainReady ? _t('请先设置联邦域名') : ($autoDelivery ? _t('自动投递已开启，可不配置') : _t('尚未保存令牌'))); ?></code><?php if ($cronUrl !== ''): ?><button class="fed-copy" type="button" data-copy="<?php echo $e($cronUrl); ?>"><?php _e('复制'); ?></button><?php endif; ?></span></div>
                    </div>

                    <details class="fed-cron-help">
                        <summary><?php _e('如何设置定时任务'); ?></summary>
                        <div class="fed-cron-help__body">
                            <?php if ($cronUrl === ''): ?>
                                <p><?php echo !$domainReady ? _t('请先打开插件设置并保存联邦域名，然后返回此页查看完整任务地址和命令。') : ($autoDelivery ? _t('自动投递已开启，日常交互无需 Cron。低流量站点如需无人访问时自动重试和清理，可在插件设置中保存 Cron 令牌。') : _t('自动投递已关闭，请先在插件设置中保存 Cron 令牌，然后返回此页配置任务。')); ?></p>
                            <?php else: ?>
                                <?php $cronCommand = 'curl -fsS --max-time 50 "' . str_replace(array('\\', '"', '$', '`'), array('\\\\', '\\"', '\\$', '\\`'), $cronUrl) . '" >/dev/null 2>&1'; ?>
                                <p><?php echo $autoDelivery ? _t('自动投递已开启，Cron 是可选保障：它会在网站没有新交互时继续重试，并清理过期数据。建议每分钟访问一次。') : _t('自动投递已关闭，必须由 Cron 或后台手动处理队列。建议每分钟访问一次 Cron 地址。'); ?></p>

                                <h3><?php _e('宝塔面板'); ?></h3>
                                <ol>
                                    <li><?php _e('进入“计划任务”，任务类型选择“Shell 脚本”。'); ?></li>
                                    <li><?php _e('执行周期选择“N 分钟”，分钟填写 1。'); ?></li>
                                    <li><?php _e('粘贴下方命令，保存后手动执行一次。'); ?></li>
                                </ol>
                                <div class="fed-cron-command"><code><?php echo $e($cronCommand); ?></code><button class="fed-copy" type="button" data-copy="<?php echo $e($cronCommand); ?>"><?php _e('复制命令'); ?></button></div>

                                <h3><?php _e('支持 URL 定时访问的主机面板'); ?></h3>
                                <p><?php _e('任务类型选择“访问 URL”或“HTTP 请求”，周期设为每 1 分钟，请求方法使用 GET，并填写下方地址。'); ?></p>
                                <div class="fed-cron-command"><code><?php echo $e($cronUrl); ?></code><button class="fed-copy" type="button" data-copy="<?php echo $e($cronUrl); ?>"><?php _e('复制地址'); ?></button></div>

                                <h3><?php _e('Linux Crontab'); ?></h3>
                                <?php $cronLine = '* * * * * ' . $cronCommand; ?>
                                <p><?php _e('运行 crontab -e，将下方整行加入任务列表。'); ?></p>
                                <div class="fed-cron-command"><code><?php echo $e($cronLine); ?></code><button class="fed-copy" type="button" data-copy="<?php echo $e($cronLine); ?>"><?php _e('复制配置'); ?></button></div>

                                <h3><?php _e('验证'); ?></h3>
                                <p><?php _e('手动访问 Cron 地址后应返回 JSON，其中 processed 是本次处理数，sent 是成功数，failed 是失败数。再到“投递队列”检查失败任务和错误信息。'); ?></p>
                                <p class="fed-muted"><?php _e('Cron 地址包含私密令牌，请勿公开、截图分享或提交到公开仓库。若令牌泄露，请在插件设置中更换并同步更新定时任务。'); ?></p>
                            <?php endif; ?>
                        </div>
                    </details>

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
                                $enabled = $domainReady && Fediverse_Core::userEnabled($uid);
                                $actorUrl = $domainReady ? Fediverse_Core::actorUrl($username) : '';
                                ?>
                                <tr>
                                    <td><strong><?php echo $e($localUser['screenName'] ?: $localUser['name']); ?></strong><br><span class="fed-muted">UID <?php echo $uid; ?></span></td>
                                    <?php $handle = $domainReady ? '@' . $username . '@' . Fediverse_Core::domain() : ''; ?>
                                    <td class="fed-break"><span class="fed-value"><code><?php echo $handle !== '' ? $e($handle) : _t('配置联邦域名后生成'); ?></code><?php if ($handle !== ''): ?><button class="fed-copy" type="button" data-copy="<?php echo $e($handle); ?>"><?php _e('复制'); ?></button><?php endif; ?></span></td>
                                    <td><span class="fed-state <?php echo !$enabled ? 'error' : ($actor ? '' : 'warn'); ?>"><?php echo !$domainReady ? _t('等待设置域名') : (!$enabled ? _t('作者已停用') : ($actor ? _t('可发现') : _t('待生成'))); ?></span></td>
                                    <td><?php echo $actor ? $e($formatTime($actor['created'])) : '-'; ?></td>
                                    <td><?php if ($actor && $actorUrl !== ''): ?><a href="<?php echo $e($actorUrl); ?>" target="_blank" rel="noopener noreferrer"><?php _e('查看'); ?></a><?php else: ?>-<?php endif; ?></td>
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
                <?php elseif ($view === 'notifications'): ?>
                    <?php
                    $rows = $db->fetchAll($db->select()->from($notificationsTable)
                        ->order('created', Typecho_Db::SORT_DESC)->limit(100));
                    $commentIds = array_values(array_unique(array_filter(array_map(
                        static fn($row) => (string)$row['type'] === 'Reply' ? (int)$row['resource_id'] : 0,
                        $rows
                    ))));
                    $commentCidMap = array();
                    if ($commentIds) {
                        foreach ($db->fetchAll($db->select('coid', 'cid')->from('table.comments')
                            ->where('coid IN ?', $commentIds)) as $commentRow) {
                            $commentCidMap[(int)$commentRow['coid']] = (int)$commentRow['cid'];
                        }
                    }
                    ?>
                    <div class="fed-actions">
                        <a class="btn btn-s fed-action" data-busy="<?php _e('正在标记…'); ?>" href="<?php echo $e($actionUrl('do=mark-notifications-read', 'notifications')); ?>"><?php _e('全部标记为已读'); ?></a>
                    </div>
                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup><col width="20%"><col width="22%"><col width="34%"><col width="14%"><col></colgroup>
                            <thead><tr><th><?php _e('本地作者'); ?></th><th><?php _e('远端账号'); ?></th><th><?php _e('通知'); ?></th><th><?php _e('时间'); ?></th><th><?php _e('操作'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $owner = $userMap[(int)$row['uid']] ?? null;
                                $commentCid = $commentCidMap[(int)$row['resource_id']] ?? 0;
                                $targetUrl = (string)$row['actor'];
                                $targetLabel = _t('查看账号');
                                $targetExternal = true;
                                if ((string)$row['type'] === 'Reply' && $commentCid > 0) {
                                    $targetUrl = Typecho_Common::url('manage-comments.php?cid=' . $commentCid, $options->adminUrl);
                                    $targetLabel = _t('查看回复');
                                    $targetExternal = false;
                                } elseif (in_array((string)$row['type'], array('Like', 'Announce'), true)
                                    && filter_var((string)$row['object_id'], FILTER_VALIDATE_URL)) {
                                    $targetUrl = (string)$row['object_id'];
                                    $targetLabel = _t('查看文章');
                                }
                                ?>
                                <tr<?php if (!$row['read_at']): ?> class="fed-notification-unread"<?php endif; ?>>
                                    <td><?php echo $owner ? $e($owner['screenName'] ?: $owner['name']) : ('UID ' . (int)$row['uid']); ?></td>
                                    <td class="fed-break"><a href="<?php echo $e($row['actor']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($actorLabel($row['actor'])); ?></a></td>
                                    <td><strong><?php echo $e($notificationLabel($row['type'])); ?></strong><?php if (!$row['read_at']): ?> <span class="fed-state warn"><?php _e('未读'); ?></span><?php endif; ?></td>
                                    <td><?php echo $e($formatTime($row['created'])); ?></td>
                                    <td><a href="<?php echo $e($targetUrl); ?>"<?php if ($targetExternal): ?> target="_blank" rel="noopener noreferrer nofollow"<?php endif; ?>><?php echo $e($targetLabel); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?><tr><td colspan="5"><?php _e('暂无联邦通知'); ?></td></tr><?php endif; ?>
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
                                <?php $mode = $moderationMode((int)$row['uid'], (string)$row['actor']); ?>
                                <tr>
                                    <td><?php echo $owner ? $e($owner['screenName'] ?: $owner['name']) : ('UID ' . (int)$row['uid']); ?></td>
                                    <td class="fed-break"><a href="<?php echo $e($row['actor']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($row['actor']); ?></a></td>
                                    <td class="fed-break"><?php echo $e($row['shared_inbox'] ?: $row['inbox']); ?></td>
                                    <td><?php echo $e($formatTime($row['created'])); ?></td>
                                    <td class="fed-table-actions"><span class="fed-moderation"><a class="fed-action" data-busy="<?php _e('处理中…'); ?>" data-confirm="<?php _e('确认在本地屏蔽此账号吗？其后续活动将被忽略。'); ?>" href="<?php echo $e($actionUrl('do=moderate-actor&uid=' . (int)$row['uid'] . '&actor=' . rawurlencode((string)$row['actor']) . '&mode=' . ($mode === 'blocked' ? '' : 'blocked'), 'followers')); ?>"><?php echo $mode === 'blocked' ? _t('取消屏蔽') : _t('屏蔽'); ?></a><a class="fed-action operate-delete" data-busy="<?php _e('移除中…'); ?>" data-confirm="<?php _e('确认移除此关注者吗？'); ?>" href="<?php echo $e($actionUrl('do=remove-follower&id=' . (int)$row['id'], 'followers')); ?>"><?php _e('移除'); ?></a></span></td>
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
                                <?php $mode = $moderationMode((int)$row['uid'], (string)$row['actor']); ?>
                                <tr>
                                    <td><?php echo $owner ? $e($owner['screenName'] ?: $owner['name']) : ('UID ' . (int)$row['uid']); ?></td>
                                    <td class="fed-break"><strong><?php echo $e($actorLabel($row['actor'])); ?></strong><br><a href="<?php echo $e($row['actor']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($row['actor']); ?></a></td>
                                    <td><span class="fed-state <?php echo $mode === 'blocked' || $row['state'] === 'rejected' ? 'error' : ($mode === 'muted' || $row['state'] !== 'accepted' ? 'warn' : ''); ?>"><?php echo $mode === 'blocked' ? _t('已屏蔽') : ($mode === 'muted' ? _t('已静音') : ($row['state'] === 'accepted' ? _t('已关注') : ($row['state'] === 'rejected' ? _t('已拒绝') : _t('等待确认')))); ?></span></td>
                                    <td><?php echo $e($formatTime($row['updated'])); ?></td>
                                    <td class="fed-table-actions"><span class="fed-moderation"><a class="fed-action" data-busy="<?php _e('处理中…'); ?>" href="<?php echo $e($actionUrl('do=moderate-actor&uid=' . (int)$row['uid'] . '&actor=' . rawurlencode((string)$row['actor']) . '&mode=' . ($mode === 'muted' ? '' : 'muted'), 'following')); ?>"><?php echo $mode === 'muted' ? _t('取消静音') : _t('静音'); ?></a><a class="fed-action" data-busy="<?php _e('处理中…'); ?>" data-confirm="<?php _e('确认在本地屏蔽此账号吗？其后续活动将被忽略。'); ?>" href="<?php echo $e($actionUrl('do=moderate-actor&uid=' . (int)$row['uid'] . '&actor=' . rawurlencode((string)$row['actor']) . '&mode=' . ($mode === 'blocked' ? '' : 'blocked'), 'following')); ?>"><?php echo $mode === 'blocked' ? _t('取消屏蔽') : _t('屏蔽'); ?></a><a class="fed-action operate-delete" data-busy="<?php _e('取消中…'); ?>" data-confirm="<?php _e('确认取消关注此远端账号吗？'); ?>" href="<?php echo $e($actionUrl('do=unfollow-remote&id=' . (int)$row['id'], 'following')); ?>"><?php _e('取消关注'); ?></a></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?><tr><td colspan="5"><?php _e('尚未关注远端账号，可在上方输入完整联邦地址。'); ?></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($moderationRows): ?>
                        <h3><?php _e('本地限制'); ?></h3>
                        <div class="typecho-table-wrap">
                            <table class="typecho-list-table">
                                <colgroup><col width="22%"><col width="46%"><col width="16%"><col></colgroup>
                                <thead><tr><th><?php _e('本地作者'); ?></th><th><?php _e('远端账号'); ?></th><th><?php _e('状态'); ?></th><th><?php _e('操作'); ?></th></tr></thead>
                                <tbody>
                                <?php foreach ($moderationRows as $moderation): ?>
                                    <?php $owner = $userMap[(int)$moderation['uid']] ?? null; ?>
                                    <tr>
                                        <td><?php echo $owner ? $e($owner['screenName'] ?: $owner['name']) : ('UID ' . (int)$moderation['uid']); ?></td>
                                        <td class="fed-break"><a href="<?php echo $e($moderation['actor']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($actorLabel($moderation['actor'])); ?></a></td>
                                        <td><span class="fed-state <?php echo $moderation['mode'] === 'blocked' ? 'error' : 'warn'; ?>"><?php echo $moderation['mode'] === 'blocked' ? _t('已屏蔽') : _t('已静音'); ?></span></td>
                                        <td><a class="fed-action" data-busy="<?php _e('处理中…'); ?>" href="<?php echo $e($actionUrl('do=moderate-actor&uid=' . (int)$moderation['uid'] . '&actor=' . rawurlencode((string)$moderation['actor']) . '&mode=', 'following')); ?>"><?php _e('取消限制'); ?></a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php elseif ($view === 'timeline'): ?>
                    <?php
                    $filterTimeline = static function ($query) use (
                        $bookmarksTable,
                        $timelineUid,
                        $timelineSearch,
                        $timelineBookmarked
                    ) {
                        if ($timelineBookmarked) {
                            $query->join($bookmarksTable . ' b', 'b.tid = t.tid AND b.uid = t.uid');
                        }
                        $query->where('t.deleted = ?', 0);
                        if ($timelineUid > 0) {
                            $query->where('t.uid = ?', $timelineUid);
                        }
                        if ($timelineSearch !== '') {
                            $like = '%' . $timelineSearch . '%';
                            $query->where('(t.content LIKE ? OR t.actor LIKE ? OR t.object_id LIKE ?)', $like, $like, $like);
                        }
                        return $query;
                    };
                    $totalRow = $db->fetchRow($filterTimeline($db->select(array('COUNT(*)' => 'num'))
                        ->from($timelineTable . ' t')));
                    $timelineTotal = (int)($totalRow['num'] ?? 0);
                    $timelinePageSize = 20;
                    $timelinePages = max(1, (int)ceil($timelineTotal / $timelinePageSize));
                    $timelinePage = min($timelinePage, $timelinePages);
                    $rows = $db->fetchAll($filterTimeline($db->select('t.*')->from($timelineTable . ' t'))
                        ->order('t.published', Typecho_Db::SORT_DESC)->page($timelinePage, $timelinePageSize));
                    $timelineMetaMap = array();
                    $timelineIds = array_map('intval', array_column($rows, 'tid'));
                    if ($timelineIds) {
                        foreach ($db->fetchAll($db->select()->from($timelineMetaTable)->where('tid IN ?', $timelineIds)) as $metaRow) {
                            $metaRow['attachments'] = json_decode((string)$metaRow['attachments'], true) ?: array();
                            $metaRow['tags'] = json_decode((string)$metaRow['tags'], true) ?: array();
                            $metaRow['poll'] = json_decode((string)$metaRow['poll'], true) ?: array();
                            $timelineMetaMap[(int)$metaRow['tid']] = $metaRow;
                        }
                    }
                    $bookmarkMap = array();
                    if ($timelineIds) {
                        foreach ($db->fetchAll($db->select('uid', 'tid')->from($bookmarksTable)
                            ->where('tid IN ?', $timelineIds)) as $bookmarkRow) {
                            $bookmarkMap[(int)$bookmarkRow['uid'] . ':' . (int)$bookmarkRow['tid']] = true;
                        }
                    }
                    $remoteActorHashes = array();
                    foreach ($rows as $timelineRow) {
                        $remoteActorHashes[] = hash('sha256', (string)$timelineRow['actor']);
                        $announcedActor = (string)($timelineMetaMap[(int)$timelineRow['tid']]['announced_actor'] ?? '');
                        if ($announcedActor !== '') {
                            $remoteActorHashes[] = hash('sha256', $announcedActor);
                        }
                    }
                    $remoteActorHashes = array_values(array_unique($remoteActorHashes));
                    if ($remoteActorHashes) {
                        foreach ($db->fetchAll($db->select()->from($remoteActorsTable)
                            ->where('actor_hash IN ?', $remoteActorHashes)) as $remoteActor) {
                            $remoteActorMap[(string)$remoteActor['actor_hash']] = $remoteActor;
                        }
                    }
                    $timelineReplyMap = array();
                    $timelineParentMap = array();
                    $timelineObjectIds = array_values(array_unique(array_column($rows, 'object_id')));
                    if ($timelineObjectIds) {
                        $activityRows = $db->fetchAll($db->select('object_id', 'payload')->from($activitiesTable)
                            ->where('object_id IN ?', $timelineObjectIds)->where('type IN ?', array('Create', 'Update'))
                            ->order('aid', Typecho_Db::SORT_DESC));
                        foreach ($activityRows as $activityRow) {
                            $objectId = (string)$activityRow['object_id'];
                            if (isset($timelineReplyMap[$objectId])) {
                                continue;
                            }
                            $payload = json_decode((string)$activityRow['payload'], true);
                            $object = is_array($payload['object'] ?? null) ? $payload['object'] : array();
                            $replyValue = $object['inReplyTo'] ?? '';
                            $replyTo = is_array($replyValue) ? (string)($replyValue['id'] ?? '') : (string)$replyValue;
                            if (str_starts_with(strtolower($replyTo), 'https://')
                                && filter_var($replyTo, FILTER_VALIDATE_URL)) {
                                $timelineReplyMap[$objectId] = $replyTo;
                            }
                        }
                        $replyTargets = array_values(array_unique(array_values($timelineReplyMap)));
                        if ($replyTargets) {
                            foreach ($db->fetchAll($db->select('object_id', 'actor', 'url', 'content')->from($timelineTable)
                                ->where('object_id IN ?', $replyTargets)->where('deleted = ?', 0)) as $parentRow) {
                                $timelineParentMap[(string)$parentRow['object_id']] = $parentRow;
                            }
                        }
                    }
                    $timelinePageUrl = static function ($page) use (
                        $options,
                        $timelineUid,
                        $timelineSearch,
                        $timelineBookmarked
                    ) {
                        $query = 'extending.php?panel=Fediverse%2Ftimeline.php&page=' . max(1, (int)$page);
                        if ($timelineUid > 0) {
                            $query .= '&uid=' . $timelineUid;
                        }
                        if ($timelineSearch !== '') {
                            $query .= '&q=' . rawurlencode($timelineSearch);
                        }
                        if ($timelineBookmarked) {
                            $query .= '&bookmarked=1';
                        }
                        return Typecho_Common::url($query, $options->adminUrl);
                    };
                    ?>
                    <form class="fed-timeline-filter" method="get" action="<?php echo $e(Typecho_Common::url('extending.php', $options->adminUrl)); ?>">
                        <input type="hidden" name="panel" value="Fediverse/timeline.php">
                        <div class="fed-field">
                            <label for="fed-timeline-uid"><?php _e('本地作者'); ?></label>
                            <select id="fed-timeline-uid" name="uid">
                                <option value="0"><?php _e('全部作者'); ?></option>
                                <?php foreach ($users as $localUser): ?>
                                    <option value="<?php echo (int)$localUser['uid']; ?>"<?php if ($timelineUid === (int)$localUser['uid']): ?> selected<?php endif; ?>><?php echo $e($localUser['screenName'] ?: $localUser['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fed-field">
                            <label for="fed-timeline-search"><?php _e('搜索内容或账号'); ?></label>
                            <input id="fed-timeline-search" name="q" type="search" maxlength="100" value="<?php echo $e($timelineSearch); ?>">
                        </div>
                        <label class="fed-filter-check"><input type="checkbox" name="bookmarked" value="1"<?php if ($timelineBookmarked): ?> checked<?php endif; ?>> <?php _e('仅收藏'); ?></label>
                        <button class="btn btn-s" type="submit"><?php _e('筛选'); ?></button>
                        <?php if ($timelineUid > 0 || $timelineSearch !== '' || $timelineBookmarked): ?><a href="<?php echo $e($panelUrl('timeline')); ?>"><?php _e('清除'); ?></a><?php endif; ?>
                    </form>
                    <?php if ($rows): ?>
                        <div class="fed-feed" aria-label="<?php _e('联邦时间轴'); ?>">
                            <?php foreach ($rows as $row): ?>
                                <?php $owner = $userMap[(int)$row['uid']] ?? null; ?>
                                <?php $meta = $timelineMetaMap[(int)$row['tid']] ?? array('summary' => '', 'sensitive' => 0, 'attachments' => array(), 'tags' => array(), 'poll' => array(), 'visibility' => 'unknown', 'announced_actor' => ''); ?>
                                <?php $mode = $moderationMode((int)$row['uid'], (string)$row['actor']); ?>
                                <?php $cachedActor = $remoteActorMap[hash('sha256', (string)$row['actor'])] ?? array(); ?>
                                <?php $profileUrl = (string)($cachedActor['profile_url'] ?? $row['actor']); ?>
                                <?php $bookmarked = isset($bookmarkMap[(int)$row['uid'] . ':' . (int)$row['tid']]); ?>
                                <article class="fed-feed-item">
                                    <?php if ((string)$meta['announced_actor'] !== ''): ?>
                                        <div class="fed-feed-boost"><?php echo $e(_t('%s 转发了此内容', $actorLabel($meta['announced_actor']))); ?></div>
                                    <?php endif; ?>
                                    <header class="fed-feed-head">
                                        <div class="fed-feed-identity">
                                            <strong><a href="<?php echo $e($profileUrl); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($actorLabel($row['actor'])); ?></a></strong>
                                            <div class="fed-muted"><?php echo $owner ? $e(_t('投递给 %s', $owner['screenName'] ?: $owner['name'])) : ('UID ' . (int)$row['uid']); ?> · <?php echo $e($row['object_type']); ?> · <?php echo $e($visibilityLabel($meta['visibility'])); ?><?php if (!empty($cachedActor['avatar_url'])): ?> · <a href="<?php echo $e($cachedActor['avatar_url']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php _e('头像'); ?></a><?php endif; ?></div>
                                        </div>
                                        <time class="fed-feed-time" datetime="<?php echo $e(Fediverse_Core::iso8601((int)$row['published'])); ?>"><?php echo $e($formatTime($row['published'])); ?></time>
                                    </header>
                                    <?php $replyTo = $timelineReplyMap[(string)$row['object_id']] ?? ''; ?>
                                    <?php if ($replyTo !== ''): ?>
                                        <?php $parentRow = $timelineParentMap[$replyTo] ?? null; ?>
                                        <div class="fed-feed-context">
                                            <strong><?php echo $parentRow ? $e(_t('回复 %s', $actorLabel($parentRow['actor']))) : _t('回复上文'); ?></strong>
                                            <?php if ($parentRow): ?><?php echo $e(Typecho_Common::subStr((string)$parentRow['content'], 0, 180, '…')); ?><?php else: ?><a href="<?php echo $e($replyTo); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php _e('查看被回复的内容'); ?></a><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ((string)$meta['summary'] !== '' || !empty($meta['sensitive'])): ?>
                                        <details class="fed-feed-warning">
                                            <summary><?php echo (string)$meta['summary'] !== '' ? $e($meta['summary']) : _t('敏感内容'); ?></summary>
                                            <div class="fed-feed-content"><?php echo nl2br($e($row['content']), false); ?></div>
                                        </details>
                                    <?php else: ?>
                                        <div class="fed-feed-content"><?php echo nl2br($e($row['content']), false); ?></div>
                                    <?php endif; ?>
                                    <?php if ($meta['attachments']): ?>
                                        <ul class="fed-feed-media" aria-label="<?php _e('媒体附件'); ?>">
                                            <?php foreach ($meta['attachments'] as $attachment): ?>
                                                <li><a href="<?php echo $e($attachment['url']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($attachment['name'] ?: _t('打开%s附件', $attachment['type'])); ?></a><?php if ($attachment['mediaType']): ?> <span class="fed-muted"><?php echo $e($attachment['mediaType']); ?></span><?php endif; ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if ($meta['tags']): ?>
                                        <div class="fed-feed-tags">
                                            <?php foreach ($meta['tags'] as $tag): ?>
                                                <?php if ($tag['href']): ?><a href="<?php echo $e($tag['href']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo $e($tag['name']); ?></a><?php else: ?><span><?php echo $e($tag['name']); ?></span><?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($meta['poll']): ?>
                                        <?php
                                        $pollVotes = array_sum(array_map(static fn($option) => (int)($option['votes'] ?? 0), $meta['poll']['options'] ?? array()));
                                        $pollVoters = max(0, (int)($meta['poll']['votersCount'] ?? 0));
                                        $pollScale = max(1, $pollVotes);
                                        ?>
                                        <div class="fed-feed-poll" aria-label="<?php _e('投票'); ?>">
                                            <?php foreach ((array)($meta['poll']['options'] ?? array()) as $option): ?>
                                                <?php $percentage = min(100, max(0, (int)round(((int)$option['votes'] / $pollScale) * 100))); ?>
                                                <div class="fed-poll-option"><span><?php echo $e($option['name']); ?></span><strong><?php echo (int)$option['votes']; ?></strong><div class="fed-poll-track" aria-hidden="true"><span style="width:<?php echo $percentage; ?>%"></span></div></div>
                                            <?php endforeach; ?>
                                            <div class="fed-poll-meta"><?php echo $e(_t('%d 票', $pollVotes)); ?><?php if ($pollVoters > 0 && !empty($meta['poll']['multiple'])): ?> · <?php echo $e(_t('%d 人参与', $pollVoters)); ?><?php endif; ?><?php if (!empty($meta['poll']['multiple'])): ?> · <?php _e('可多选'); ?><?php endif; ?><?php if (!empty($meta['poll']['closed'])): ?> · <?php _e('已结束'); ?><?php elseif (!empty($meta['poll']['endTime'])): ?> · <?php echo $e(_t('%s 结束', $formatTime($meta['poll']['endTime']))); ?><?php endif; ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="fed-feed-actions">
                                        <a class="fed-action<?php if ($bookmarked): ?> is-active<?php endif; ?>" data-busy="<?php _e('处理中…'); ?>" href="<?php echo $e($actionUrl('do=timeline-bookmark&tid=' . (int)$row['tid'], 'timeline')); ?>"><?php echo $bookmarked ? _t('取消收藏') : _t('收藏'); ?></a>
                                        <a class="fed-action<?php if ($row['liked_activity']): ?> is-active<?php endif; ?>" data-busy="<?php _e('处理中…'); ?>" href="<?php echo $e($actionUrl('do=' . ($row['liked_activity'] ? 'timeline-unlike' : 'timeline-like') . '&tid=' . (int)$row['tid'], 'timeline')); ?>"><?php echo $row['liked_activity'] ? _t('取消点赞') : _t('点赞'); ?></a>
                                        <?php if ((string)$meta['visibility'] === 'public'): ?>
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
                                        <?php endif; ?>
                                        <a class="fed-action" data-busy="<?php _e('处理中…'); ?>" href="<?php echo $e($actionUrl('do=moderate-actor&uid=' . (int)$row['uid'] . '&actor=' . rawurlencode((string)$row['actor']) . '&mode=' . ($mode === 'muted' ? '' : 'muted'), 'timeline')); ?>"><?php echo $mode === 'muted' ? _t('取消静音') : _t('静音'); ?></a>
                                        <a class="fed-action" data-busy="<?php _e('处理中…'); ?>" data-confirm="<?php _e('确认在本地屏蔽此账号吗？当前时间轴内容将被隐藏，后续活动将被忽略。'); ?>" href="<?php echo $e($actionUrl('do=moderate-actor&uid=' . (int)$row['uid'] . '&actor=' . rawurlencode((string)$row['actor']) . '&mode=' . ($mode === 'blocked' ? '' : 'blocked'), 'timeline')); ?>"><?php echo $mode === 'blocked' ? _t('取消屏蔽') : _t('屏蔽'); ?></a>
                                        <a href="<?php echo $e($row['url']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php _e('查看原文'); ?></a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($timelinePages > 1): ?>
                            <nav class="fed-pagination" aria-label="<?php _e('时间轴分页'); ?>">
                                <?php if ($timelinePage > 1): ?><a href="<?php echo $e($timelinePageUrl($timelinePage - 1)); ?>"><?php _e('上一页'); ?></a><?php else: ?><span></span><?php endif; ?>
                                <strong><?php echo $e(_t('第 %d / %d 页，共 %d 条', $timelinePage, $timelinePages, $timelineTotal)); ?></strong>
                                <?php if ($timelinePage < $timelinePages): ?><a href="<?php echo $e($timelinePageUrl($timelinePage + 1)); ?>"><?php _e('下一页'); ?></a><?php else: ?><span></span><?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($timelineUid > 0 || $timelineSearch !== '' || $timelineBookmarked): ?>
                            <div class="fed-empty"><strong><?php _e('没有符合条件的内容'); ?></strong><a href="<?php echo $e($panelUrl('timeline')); ?>"><?php _e('清除筛选'); ?></a></div>
                        <?php else: ?>
                            <div class="fed-empty"><strong><?php _e('时间轴还是空的'); ?></strong><span><?php _e('关注远端账号并等待对方接受后，新内容会由远端投递到这里。'); ?></span><br><a href="<?php echo $e($panelUrl('following')); ?>"><?php _e('添加关注'); ?></a></div>
                        <?php endif; ?>
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
