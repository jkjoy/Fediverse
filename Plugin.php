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
 * @version 0.2.1
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
        'fediverse_object' => array('/fediverse/post/[cid:digital]', 'object'),
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
            _t('入站活动日志保留天数'),
            _t('Cron 会自动删除超过此天数的入站活动记录，范围 7 至 3650 天。')
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
