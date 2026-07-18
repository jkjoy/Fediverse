<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Action.php';
require_once __DIR__ . '/AdminAction.php';

/**
 * 让 Typecho 作者以独立账号接入 ActivityPub/Fediverse 网络。
 *
 * @package Fediverse
 * @author Typecho Community
 * @version 0.5.4
 * @link https://www.w3.org/TR/activitypub/
 */
class Fediverse_Plugin implements Typecho_Plugin_Interface
{
    private const PANEL = 'Fediverse/manage.php';
    private const ADMIN_ACTION = 'fediverse-admin';

    private const ROUTES = array(
        'fediverse_webfinger' => array('/.well-known/webfinger', 'webfinger'),
        'fediverse_nodeinfo_discovery' => array('/.well-known/nodeinfo', 'nodeinfoDiscovery'),
        'fediverse_nodeinfo' => array('/fediverse/nodeinfo/2.1', 'nodeinfo'),
        'fediverse_actor' => array('/fediverse/author/[username:alpha]', 'actor'),
        'fediverse_inbox' => array('/fediverse/author/[username:alpha]/inbox', 'inbox'),
        'fediverse_shared_inbox' => array('/fediverse/inbox', 'sharedInbox'),
        'fediverse_outbox' => array('/fediverse/author/[username:alpha]/outbox', 'outbox'),
        'fediverse_followers' => array('/fediverse/author/[username:alpha]/followers', 'followers'),
        'fediverse_following' => array('/fediverse/author/[username:alpha]/following', 'following'),
        'fediverse_object' => array('/fediverse/post/[cid:digital]', 'object'),
        'fediverse_reply' => array('/fediverse/reply/[token:alpha]', 'reply'),
        'fediverse_cron' => array('/fediverse/cron/[token:alpha]', 'cron')
    );

    public static function activate()
    {
        if (PHP_VERSION_ID < 80200) {
            throw new Typecho_Plugin_Exception(_t('Fediverse 需要 PHP 8.2 或更高版本'));
        }

        if (!extension_loaded('openssl') || !function_exists('openssl_pkey_new')) {
            throw new Typecho_Plugin_Exception(_t('Fediverse 需要 OpenSSL 扩展'));
        }

        if (!extension_loaded('curl') || !function_exists('curl_init')) {
            throw new Typecho_Plugin_Exception(_t('Fediverse 需要 cURL 扩展'));
        }

        Fediverse_Database::install();
        foreach (self::ROUTES as $name => $route) {
            Fediverse_Core::helperCall('addRoute', $name, $route[0], 'Fediverse_Action', $route[1], 'index');
        }
        Fediverse_Core::helperCall(
            'addPanel',
            3,
            self::PANEL,
            _t('联邦宇宙'),
            _t('联邦账号与投递管理'),
            'administrator'
        );
        Fediverse_Core::helperCall('addAction', self::ADMIN_ACTION, 'Fediverse_AdminAction');

        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'publishPost');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishMark = array(__CLASS__, 'markPost');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->delete = array(__CLASS__, 'deletePost');

        return _t('Fediverse 已启用。请保存插件设置，并为服务器配置队列 Cron。');
    }

    public static function deactivate()
    {
        foreach (array_keys(self::ROUTES) as $name) {
            Fediverse_Core::helperCall('removeRoute', $name);
        }
        Fediverse_Core::helperCall('removePanel', 3, self::PANEL);
        Fediverse_Core::helperCall('removeAction', self::ADMIN_ACTION);

        return _t('Fediverse 已停用，联邦数据表和作者密钥已保留。');
    }

    public static function upgrade()
    {
        Fediverse_Database::install();
        $routingTable = Fediverse_Core::options()->routingTable;
        $upgrades = array(
            'fediverse_following' => 'fediverse_followers',
            'fediverse_reply' => 'fediverse_object'
        );
        foreach ($upgrades as $name => $after) {
            if (!isset($routingTable[$name])) {
                $route = self::ROUTES[$name];
                Fediverse_Core::helperCall('addRoute', $name, $route[0], 'Fediverse_Action', $route[1], $after);
            }
        }
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $enabled = new Typecho_Widget_Helper_Form_Element_Radio(
            'enabled',
            array('1' => _t('启用'), '0' => _t('停用')),
            '1',
            _t('联邦功能')
        );
        $form->addInput($enabled);

        $domain = new Typecho_Widget_Helper_Form_Element_Text(
            'domain',
            null,
            '',
            _t('联邦域名'),
            _t('通常留空，自动使用博客域名。只填写域名，不要包含协议或路径。')
        );
        $domain->addRule('xssCheck', _t('域名格式不正确'));
        $form->addInput($domain);

        $summary = new Typecho_Widget_Helper_Form_Element_Textarea(
            'summary',
            null,
            _t('来自 Typecho 博客的作者账号'),
            _t('默认账号简介')
        );
        $form->addInput($summary);

        $comments = new Typecho_Widget_Helper_Form_Element_Radio(
            'acceptReplies',
            array('1' => _t('接收并直接显示'), '0' => _t('不接收')),
            '1',
            _t('远端回复')
        );
        $form->addInput($comments);

        $batch = new Typecho_Widget_Helper_Form_Element_Text(
            'queueBatch',
            null,
            '20',
            _t('每次队列处理数量')
        );
        $batch->addRule('isInteger', _t('必须是整数'));
        $form->addInput($batch);

        $retention = new Typecho_Widget_Helper_Form_Element_Text(
            'activityRetentionDays',
            null,
            '90',
            _t('入站活动与时间轴保留天数'),
            _t('Cron 会自动删除超过此天数的入站活动和时间轴内容，范围 7 至 3650 天。')
        );
        $retention->addRule('isInteger', _t('必须是整数'));
        $form->addInput($retention);

        $token = new Typecho_Widget_Helper_Form_Element_Text(
            'cronToken',
            null,
            bin2hex(random_bytes(12)),
            _t('Cron 令牌'),
            _t('Cron 请求地址中的私密令牌，建议保存自动生成值。')
        );
        $token->addRule('alphaNumeric', _t('令牌只能包含字母和数字'));
        $form->addInput($token);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        $enabled = new Typecho_Widget_Helper_Form_Element_Radio(
            'enabled',
            array('1' => _t('启用'), '0' => _t('停用')),
            '1',
            _t('我的联邦账号')
        );
        $form->addInput($enabled);

        $username = new Typecho_Widget_Helper_Form_Element_Text(
            'username',
            null,
            '',
            _t('联邦用户名'),
            _t('留空时使用 Typecho 登录名；只允许字母、数字、下划线和连字符。保存后不建议修改。')
        );
        $username->addRule('regexp', _t('用户名只能包含字母、数字、下划线和连字符'), '/^$|^[A-Za-z0-9_-]{1,64}$/');
        $username->addRule('xssCheck', _t('用户名格式不正确'));
        $form->addInput($username);

        $summary = new Typecho_Widget_Helper_Form_Element_Textarea(
            'summary',
            null,
            '',
            _t('账号简介'),
            _t('留空时使用插件的默认账号简介。')
        );
        $form->addInput($summary);

        $avatarUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'avatarUrl',
            null,
            '',
            _t('头像地址'),
            _t('填写可公开访问的 HTTPS 图片地址；留空时不向联邦宇宙提供头像。')
        );
        $avatarUrl->addRule('url', _t('头像地址格式不正确'));
        $avatarUrl->addRule('regexp', _t('头像地址必须是 HTTPS 地址'), '/^$|^https:\/\/[^\s]+$/i');
        $form->addInput($avatarUrl);

        $headerUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'headerUrl',
            null,
            '',
            _t('Banner 地址'),
            _t('填写可公开访问的 HTTPS 图片地址；在 Mastodon 中显示为个人资料页背景图。')
        );
        $headerUrl->addRule('url', _t('Banner 地址格式不正确'));
        $headerUrl->addRule('regexp', _t('Banner 地址必须是 HTTPS 地址'), '/^$|^https:\/\/[^\s]+$/i');
        $form->addInput($headerUrl);

        for ($index = 1; $index <= 4; $index++) {
            $fieldName = new Typecho_Widget_Helper_Form_Element_Text(
                'profileField' . $index . 'Name',
                null,
                '',
                _t('资料字段 %d：名称', $index),
                $index === 1 ? _t('例如“网站”“位置”或“关键词”。名称和内容都填写时才会显示。') : null
            );
            $form->addInput($fieldName);

            $fieldValue = new Typecho_Widget_Helper_Form_Element_Text(
                'profileField' . $index . 'Value',
                null,
                '',
                _t('资料字段 %d：内容', $index),
                $index === 1 ? _t('HTTPS 地址会显示为可点击且可用于 rel=me 验证的链接，其他内容按纯文本显示。') : null
            );
            $form->addInput($fieldValue);
        }
    }

    public static function personalConfigHandle($settings, $isInit)
    {
        $uid = $isInit ? 0 : (int)Typecho_Widget::widget('Widget_User')->uid;
        Fediverse_Core::savePersonalSettings($uid, $settings);
        if (!$isInit && $uid > 0) {
            Fediverse_Queue::enqueueActorUpdate($uid);
        }
    }

    public static function postInteractions($cid)
    {
        $cid = (int)$cid;
        $data = array('likes' => 0, 'announces' => 0, 'replies' => 0, 'recent' => array());
        if ($cid <= 0 || !Fediverse_Core::isEnabled()) {
            return $data;
        }

        $db = Typecho_Db::get();
        $objectIds = array(Fediverse_Core::objectUrl($cid));
        $tracked = $db->fetchRow($db->select('object_id')->from(Fediverse_Database::table('posts'))
            ->where('cid = ?', $cid)->limit(1));
        if ($tracked && !in_array((string)$tracked['object_id'], $objectIds, true)) {
            $objectIds[] = (string)$tracked['object_id'];
        }

        $counts = $db->fetchAll($db->select('type', array('COUNT(*)' => 'num'))
            ->from(Fediverse_Database::table('activities'))
            ->where('object_id IN ?', $objectIds)->where('type IN ?', array('Like', 'Announce'))
            ->where('status = ?', 'accepted')->group('type'));
        foreach ($counts as $count) {
            if ((string)$count['type'] === 'Like') {
                $data['likes'] = (int)$count['num'];
            } elseif ((string)$count['type'] === 'Announce') {
                $data['announces'] = (int)$count['num'];
            }
        }

        $reply = $db->fetchRow($db->select(array('COUNT(*)' => 'num'))->from('table.comments')
            ->where('cid = ?', $cid)->where('type = ?', 'comment')
            ->where('status IN ?', array('approved', 'waiting'))
            ->where('agent = ?', 'ActivityPub'));
        $data['replies'] = (int)($reply['num'] ?? 0);
        if ($data['likes'] + $data['announces'] > 0) {
            $data['recent'] = $db->fetchAll($db->select('type', 'actor', 'created')
                ->from(Fediverse_Database::table('activities'))
                ->where('object_id IN ?', $objectIds)->where('type IN ?', array('Like', 'Announce'))
                ->where('status = ?', 'accepted')->order('aid', Typecho_Db::SORT_DESC)->limit(12));
        }
        return $data;
    }

    public static function renderPostInteractions($archive)
    {
        if (!$archive || !method_exists($archive, 'is') || !$archive->is('post')) {
            return;
        }
        if (!Fediverse_Core::userEnabled((int)$archive->authorId)) {
            return;
        }
        $data = self::postInteractions((int)$archive->cid);
        $e = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?>
        <section class="fediverse-interactions" aria-labelledby="fediverse-interactions-title">
            <div class="fediverse-interactions__header">
                <h2 id="fediverse-interactions-title"><?php _e('联邦宇宙互动'); ?></h2>
                <span>ActivityPub</span>
            </div>
            <dl class="fediverse-interactions__stats">
                <div><dt><?php _e('点赞'); ?></dt><dd><?php echo (int)$data['likes']; ?></dd></div>
                <div><dt><?php _e('转发'); ?></dt><dd><?php echo (int)$data['announces']; ?></dd></div>
                <div><dt><?php _e('联邦回复'); ?></dt><dd><?php echo (int)$data['replies']; ?></dd></div>
            </dl>
            <?php if ($data['recent']): ?>
                <details class="fediverse-interactions__details">
                    <summary><?php _e('查看最近互动'); ?></summary>
                    <ul>
                        <?php foreach ($data['recent'] as $interaction): ?>
                            <?php
                            $actor = (string)$interaction['actor'];
                            $host = (string)parse_url($actor, PHP_URL_HOST);
                            $path = trim((string)parse_url($actor, PHP_URL_PATH), '/');
                            $username = $path !== '' ? rawurldecode((string)basename($path)) : '';
                            $label = $username !== '' && $host !== '' ? '@' . $username . '@' . $host : ($host ?: $actor);
                            ?>
                            <li>
                                <a href="<?php echo $e($actor); ?>" target="_blank" rel="ugc nofollow noopener noreferrer"><?php echo $e($label); ?></a>
                                <span><?php echo (string)$interaction['type'] === 'Like' ? _t('点赞') : _t('转发'); ?> · <?php echo $e(date('Y-m-d H:i', (int)$interaction['created'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </section>
        <style>
        .fediverse-interactions{--fediverse-divider:rgba(127,127,127,.28);--fediverse-muted-opacity:.62;--fediverse-focus:currentColor;isolation:isolate;width:100%;max-width:100%;margin:28px 0 20px;padding:18px 0;border-block:1px solid var(--fediverse-divider);background:transparent;color:inherit;font-family:inherit;font-size:16px;line-height:1.5;letter-spacing:0;text-align:start}
        .fediverse-interactions,.fediverse-interactions *{box-sizing:border-box}
        .fediverse-interactions__header{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin:0 0 14px;padding:0}
        .fediverse-interactions__header h2{min-width:0;margin:0;padding:0;border:0;background:transparent;color:inherit;font-family:inherit;font-size:16px;font-weight:700;line-height:1.4;letter-spacing:0;text-wrap:balance}
        .fediverse-interactions__header span{flex:0 0 auto;margin:0;padding:0;color:inherit;font-family:inherit;font-size:12px;font-weight:400;line-height:1.4;letter-spacing:0;opacity:var(--fediverse-muted-opacity)}
        .fediverse-interactions__stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0;margin:0;padding:0;border:0;background:transparent}
        .fediverse-interactions__stats div{display:flex;min-width:0;min-height:60px;margin:0;padding:2px 16px 0 0;flex-direction:column}
        .fediverse-interactions__stats div+div{padding-inline-start:16px;border-inline-start:1px solid var(--fediverse-divider)}
        .fediverse-interactions__stats div:last-child{padding-inline-end:0}
        .fediverse-interactions__stats dd{order:1;margin:0;padding:0;border:0;background:transparent;color:inherit;font-family:inherit;font-size:22px;font-weight:600;line-height:1.3;font-variant-numeric:tabular-nums;letter-spacing:0}
        .fediverse-interactions__stats dt{order:2;margin:2px 0 0;padding:0;border:0;background:transparent;color:inherit;font-family:inherit;font-size:12px;font-weight:400;line-height:1.35;letter-spacing:0;opacity:var(--fediverse-muted-opacity);overflow-wrap:anywhere}
        .fediverse-interactions__details{margin:16px 0 0;padding:0;border:0;border-top:1px solid var(--fediverse-divider);background:transparent;color:inherit;font-family:inherit;font-size:14px;font-weight:400;line-height:1.5}
        .fediverse-interactions__details summary{display:flex;align-items:center;min-height:44px;margin:0;padding:0 2px;border:0;background:transparent;color:inherit;font-family:inherit;font-size:14px;font-weight:600;line-height:1.4;letter-spacing:0;cursor:pointer;list-style:none;user-select:none;transition-property:opacity;transition-duration:150ms;transition-timing-function:ease-out}
        .fediverse-interactions__details summary::-webkit-details-marker{display:none}
        .fediverse-interactions__details summary::marker{content:""}
        .fediverse-interactions__details summary::after{content:"";width:8px;height:8px;margin-inline:16px 3px;border-inline-end:1.5px solid currentColor;border-block-end:1.5px solid currentColor;transform:rotate(45deg);transition-property:transform;transition-duration:160ms;transition-timing-function:cubic-bezier(.2,0,0,1)}
        .fediverse-interactions__details[open] summary::after{transform:rotate(225deg)}
        .fediverse-interactions__details summary:hover{opacity:.76}
        .fediverse-interactions__details summary:focus-visible{outline:2px solid var(--fediverse-focus);outline-offset:2px}
        .fediverse-interactions__details ul{margin:0;padding:0;border:0;background:transparent;list-style:none}
        .fediverse-interactions__details li{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:baseline;gap:4px 16px;margin:0;padding:8px 2px;border:0;border-top:1px solid var(--fediverse-divider);background:transparent;color:inherit;list-style:none;text-wrap:pretty}
        .fediverse-interactions__details a{min-width:0;margin:0;padding:0;background:transparent;color:inherit;font:inherit;text-decoration:none;overflow-wrap:anywhere;word-break:break-word}
        .fediverse-interactions__details a:hover{text-decoration:underline;text-decoration-thickness:from-font;text-underline-offset:3px}
        .fediverse-interactions__details a:focus-visible{outline:2px solid var(--fediverse-focus);outline-offset:2px}
        .fediverse-interactions__details li span{min-width:0;margin:0;padding:0;color:inherit;font-family:inherit;font-size:12px;font-weight:400;line-height:1.4;letter-spacing:0;opacity:var(--fediverse-muted-opacity);white-space:nowrap}
        .fediverse-comment-source{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;max-width:100%;min-height:20px;margin:0 0 0 6px;padding:0 5px;border:1px solid var(--fediverse-comment-divider,rgba(127,127,127,.32));border-radius:3px;background:transparent;color:inherit;font-family:inherit;font-size:11px;font-weight:500;line-height:18px;letter-spacing:0;text-align:center;text-decoration:none;text-transform:none;white-space:nowrap;vertical-align:middle}
        @media(max-width:520px){.fediverse-interactions{margin-top:22px}.fediverse-interactions__stats div{padding-inline-end:10px}.fediverse-interactions__stats div+div{padding-inline-start:10px}.fediverse-interactions__details li{grid-template-columns:minmax(0,1fr);gap:2px}.fediverse-interactions__details li span{white-space:normal}}
        @media(prefers-reduced-motion:reduce){.fediverse-interactions__details summary,.fediverse-interactions__details summary::after{transition-duration:0s}}
        @media(forced-colors:active){.fediverse-interactions{--fediverse-divider:CanvasText;--fediverse-focus:Highlight}.fediverse-comment-source{border-color:CanvasText}}
        </style>
        <?php
    }

    public static function publishPost($contents, $widget)
    {
        if (!Fediverse_Core::isEnabled() || !isset($widget->cid)) {
            return;
        }

        Fediverse_Queue::enqueuePost((int)$widget->cid, 'upsert');
    }

    public static function markPost($status, $cid, $widget)
    {
        if (!Fediverse_Core::isEnabled()) {
            return;
        }

        Fediverse_Queue::enqueuePost((int)$cid, $status === 'publish' ? 'upsert' : 'delete');
    }

    public static function deletePost($cid, $widget)
    {
        if (Fediverse_Core::isEnabled()) {
            Fediverse_Queue::enqueuePost((int)$cid, 'delete');
        }
    }
}
