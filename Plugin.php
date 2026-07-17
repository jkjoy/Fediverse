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
 * @version 0.4.3
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
            array('1' => _t('接收并进入审核'), '0' => _t('不接收')),
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
            ->where('cid = ?', $cid)->where('type = ?', 'comment')->where('status = ?', 'approved')
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
        .fediverse-interactions{margin:28px 0 20px;padding:18px 0;border-top:1px solid var(--berry-border-color,rgba(0,0,0,.1));border-bottom:1px solid var(--berry-border-color,rgba(0,0,0,.1))}
        .fediverse-interactions__header{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin-bottom:14px}
        .fediverse-interactions__header h2{margin:0;font-size:16px;font-weight:700;letter-spacing:0}
        .fediverse-interactions__header span{color:var(--berry-text-gray-lightest,rgba(0,0,0,.5));font-size:12px}
        .fediverse-interactions__stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0}
        .fediverse-interactions__stats div{display:flex;min-width:0;flex-direction:column}
        .fediverse-interactions__stats dd{margin:0;color:var(--berry-text-color,rgba(0,0,0,.84));font-size:22px;font-variant-numeric:tabular-nums;line-height:1.3}
        .fediverse-interactions__stats dt{order:2;color:var(--berry-text-gray,rgba(0,0,0,.6));font-size:12px}
        .fediverse-interactions__details{margin-top:14px;border-top:1px solid var(--berry-border-color-light,rgba(0,0,0,.05));font-size:14px}
        .fediverse-interactions__details summary{display:flex;align-items:center;min-height:40px;color:var(--berry-main-color,#5f4b8b);cursor:pointer;list-style-position:inside}
        .fediverse-interactions__details summary::after{content:"+";margin-left:auto;font-size:18px}
        .fediverse-interactions__details[open] summary::after{content:"-"}
        .fediverse-interactions__details summary:focus-visible{outline:2px solid var(--berry-main-color,#5f4b8b);outline-offset:2px}
        .fediverse-interactions__details ul{margin:0;padding:0;list-style:none}
        .fediverse-interactions__details li{display:flex;align-items:baseline;justify-content:space-between;gap:16px;padding:7px 0;border-top:1px solid var(--berry-border-color-light,rgba(0,0,0,.05))}
        .fediverse-interactions__details a{min-width:0;color:var(--berry-text-color,rgba(0,0,0,.84));overflow-wrap:anywhere}
        .fediverse-interactions__details a:hover{color:var(--berry-hover-color,#654ea3);text-decoration:underline}
        .fediverse-interactions__details li span{flex:0 0 auto;color:var(--berry-text-gray-lightest,rgba(0,0,0,.5));font-size:12px}
        .fediverse-comment-source{display:inline-flex;align-items:center;margin-left:6px;padding:0 5px;border:1px solid var(--berry-border-color,rgba(0,0,0,.1));border-radius:3px;color:var(--berry-text-gray,rgba(0,0,0,.6));font-size:10px;font-weight:400;line-height:18px;vertical-align:middle}
        @media(max-width:600px){.fediverse-interactions{margin-top:22px}.fediverse-interactions__details li{align-items:flex-start;flex-direction:column;gap:2px}}
        </style>
        <?php
    }

    public static function publishPost($contents, $widget)
    {
        if (!Fediverse_Core::isEnabled() || !isset($widget->cid) || $widget->status !== 'publish') {
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
