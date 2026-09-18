<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Fediverse_Database
{
    public static function install()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapter = strtolower((string)$db->getAdapterName());

        if (str_contains($adapter, 'pgsql')) {
            self::installPgsql($db, $prefix);
        } elseif (str_contains($adapter, 'sqlite')) {
            self::installSqlite($db, $prefix);
        } else {
            self::installMysql($db, $prefix);
        }
    }

    public static function table($name)
    {
        return Typecho_Db::get()->getPrefix() . 'fediverse_' . $name;
    }

    private static function installMysql($db, $p)
    {
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_actors` ('
            . '`id` int unsigned NOT NULL AUTO_INCREMENT, `uid` int unsigned NOT NULL, '
            . '`username` varchar(64) NOT NULL, `private_key` text NOT NULL, `public_key` text NOT NULL, '
            . '`created` int unsigned NOT NULL, `modified` int unsigned NOT NULL, '
            . 'PRIMARY KEY (`id`), UNIQUE KEY `uid` (`uid`), UNIQUE KEY `username` (`username`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_followers` ('
            . '`id` int unsigned NOT NULL AUTO_INCREMENT, `uid` int unsigned NOT NULL, `actor` varchar(512) NOT NULL, '
            . '`inbox` varchar(512) NOT NULL, `shared_inbox` varchar(512) DEFAULT NULL, `state` varchar(16) NOT NULL, '
            . '`created` int unsigned NOT NULL, PRIMARY KEY (`id`), KEY `uid_state` (`uid`,`state`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_activities` ('
            . '`aid` int unsigned NOT NULL AUTO_INCREMENT, `activity_hash` char(64) NOT NULL, `activity_id` text NOT NULL, '
            . '`type` varchar(32) NOT NULL, `actor` text NOT NULL, `object_id` text, `payload` longtext NOT NULL, '
            . '`status` varchar(16) NOT NULL, `created` int unsigned NOT NULL, '
            . 'PRIMARY KEY (`aid`), UNIQUE KEY `activity_hash` (`activity_hash`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_inbound` ('
            . '`iid` int unsigned NOT NULL AUTO_INCREMENT, `activity_hash` char(64) NOT NULL, '
            . '`actor_hash` char(64) NOT NULL, `source_domain` varchar(255) NOT NULL, `created` int unsigned NOT NULL, '
            . 'PRIMARY KEY (`iid`), UNIQUE KEY `activity_hash` (`activity_hash`), '
            . 'KEY `actor_created` (`actor_hash`,`created`), KEY `domain_created` (`source_domain`,`created`), '
            . 'KEY `created` (`created`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_queue` ('
            . '`qid` int unsigned NOT NULL AUTO_INCREMENT, `uid` int unsigned NOT NULL, `inbox` varchar(512) NOT NULL, '
            . '`activity` longtext NOT NULL, `attempts` int unsigned NOT NULL DEFAULT 0, `available` int unsigned NOT NULL, '
            . '`last_error` varchar(500) DEFAULT NULL, `created` int unsigned NOT NULL, `updated` int unsigned NOT NULL, '
            . 'PRIMARY KEY (`qid`), KEY `available` (`available`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_posts` ('
            . '`cid` int unsigned NOT NULL, `uid` int unsigned NOT NULL, `object_id` varchar(512) NOT NULL, '
            . '`content_hash` char(64) NOT NULL, `published` int unsigned NOT NULL, `updated` int unsigned NOT NULL, '
            . 'PRIMARY KEY (`cid`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_followings` ('
            . '`id` int unsigned NOT NULL AUTO_INCREMENT, `uid` int unsigned NOT NULL, `actor_hash` char(64) NOT NULL, '
            . '`actor` varchar(512) NOT NULL, `inbox` varchar(512) NOT NULL, `shared_inbox` varchar(512) DEFAULT NULL, '
            . '`follow_id` varchar(512) NOT NULL, `state` varchar(16) NOT NULL, `created` int unsigned NOT NULL, '
            . '`updated` int unsigned NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uid_actor` (`uid`,`actor_hash`), '
            . 'KEY `uid_state` (`uid`,`state`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_timeline` ('
            . '`tid` int unsigned NOT NULL AUTO_INCREMENT, `uid` int unsigned NOT NULL, `object_hash` char(64) NOT NULL, '
            . '`activity_id` text NOT NULL, `object_id` text NOT NULL, `actor` text NOT NULL, `object_type` varchar(32) NOT NULL, '
            . '`url` text, `content` longtext NOT NULL, `published` int unsigned NOT NULL, `updated` int unsigned NOT NULL, '
            . '`liked_activity` text, `announced_activity` text, `deleted` tinyint unsigned NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (`tid`), UNIQUE KEY `object_hash` (`object_hash`), KEY `uid_published` (`uid`,`published`), '
            . 'KEY `published` (`published`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_replies` ('
            . '`token` char(32) NOT NULL, `uid` int unsigned NOT NULL, `in_reply_to` text NOT NULL, `content` longtext NOT NULL, '
            . '`to_actor` text NOT NULL, `published` int unsigned NOT NULL, `deleted` tinyint unsigned NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (`token`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->query('CREATE TABLE IF NOT EXISTS `' . $p . 'fediverse_reply_objects` ('
            . '`object_hash` char(64) NOT NULL, `actor` text NOT NULL, `object_id` text NOT NULL, '
            . '`comment_id` int unsigned NOT NULL, `created` int unsigned NOT NULL, '
            . 'PRIMARY KEY (`object_hash`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private static function installPgsql($db, $p)
    {
        $q = static fn($name) => '"' . $p . 'fediverse_' . $name . '"';
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('actors') . ' ('
            . '"id" serial PRIMARY KEY, "uid" integer NOT NULL UNIQUE, "username" varchar(64) NOT NULL UNIQUE, '
            . '"private_key" text NOT NULL, "public_key" text NOT NULL, "created" integer NOT NULL, "modified" integer NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('followers') . ' ('
            . '"id" serial PRIMARY KEY, "uid" integer NOT NULL, "actor" varchar(512) NOT NULL, '
            . '"inbox" varchar(512) NOT NULL, "shared_inbox" varchar(512), "state" varchar(16) NOT NULL, "created" integer NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('activities') . ' ('
            . '"aid" serial PRIMARY KEY, "activity_hash" char(64) NOT NULL UNIQUE, "activity_id" text NOT NULL, '
            . '"type" varchar(32) NOT NULL, "actor" text NOT NULL, "object_id" text, "payload" text NOT NULL, '
            . '"status" varchar(16) NOT NULL, "created" integer NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('inbound') . ' ('
            . '"iid" serial PRIMARY KEY, "activity_hash" char(64) NOT NULL UNIQUE, "actor_hash" char(64) NOT NULL, '
            . '"source_domain" varchar(255) NOT NULL, "created" integer NOT NULL)');
        $db->query('CREATE INDEX IF NOT EXISTS "' . $p . 'fediverse_inbound_actor_created" ON '
            . $q('inbound') . ' ("actor_hash", "created")');
        $db->query('CREATE INDEX IF NOT EXISTS "' . $p . 'fediverse_inbound_domain_created" ON '
            . $q('inbound') . ' ("source_domain", "created")');
        $db->query('CREATE INDEX IF NOT EXISTS "' . $p . 'fediverse_inbound_created" ON '
            . $q('inbound') . ' ("created")');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('queue') . ' ('
            . '"qid" serial PRIMARY KEY, "uid" integer NOT NULL, "inbox" varchar(512) NOT NULL, "activity" text NOT NULL, '
            . '"attempts" integer NOT NULL DEFAULT 0, "available" integer NOT NULL, "last_error" varchar(500), '
            . '"created" integer NOT NULL, "updated" integer NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('posts') . ' ('
            . '"cid" integer PRIMARY KEY, "uid" integer NOT NULL, "object_id" varchar(512) NOT NULL, '
            . '"content_hash" char(64) NOT NULL, "published" integer NOT NULL, "updated" integer NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('followings') . ' ('
            . '"id" serial PRIMARY KEY, "uid" integer NOT NULL, "actor_hash" char(64) NOT NULL, '
            . '"actor" varchar(512) NOT NULL, "inbox" varchar(512) NOT NULL, "shared_inbox" varchar(512), '
            . '"follow_id" varchar(512) NOT NULL, "state" varchar(16) NOT NULL, "created" integer NOT NULL, '
            . '"updated" integer NOT NULL, UNIQUE ("uid", "actor_hash"))');
        $db->query('CREATE INDEX IF NOT EXISTS "' . $p . 'fediverse_followings_uid_state" ON '
            . $q('followings') . ' ("uid", "state")');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('timeline') . ' ('
            . '"tid" serial PRIMARY KEY, "uid" integer NOT NULL, "object_hash" char(64) NOT NULL UNIQUE, '
            . '"activity_id" text NOT NULL, "object_id" text NOT NULL, "actor" text NOT NULL, "object_type" varchar(32) NOT NULL, '
            . '"url" text, "content" text NOT NULL, "published" integer NOT NULL, "updated" integer NOT NULL, '
            . '"liked_activity" text, "announced_activity" text, "deleted" integer NOT NULL DEFAULT 0)');
        $db->query('CREATE INDEX IF NOT EXISTS "' . $p . 'fediverse_timeline_uid_published" ON '
            . $q('timeline') . ' ("uid", "published")');
        $db->query('CREATE INDEX IF NOT EXISTS "' . $p . 'fediverse_timeline_published" ON '
            . $q('timeline') . ' ("published")');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('replies') . ' ('
            . '"token" char(32) PRIMARY KEY, "uid" integer NOT NULL, "in_reply_to" text NOT NULL, "content" text NOT NULL, '
            . '"to_actor" text NOT NULL, "published" integer NOT NULL, "deleted" integer NOT NULL DEFAULT 0)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('reply_objects') . ' ('
            . '"object_hash" char(64) PRIMARY KEY, "actor" text NOT NULL, "object_id" text NOT NULL, '
            . '"comment_id" integer NOT NULL, "created" integer NOT NULL)');
    }

    private static function installSqlite($db, $p)
    {
        $q = static fn($name) => '`' . $p . 'fediverse_' . $name . '`';
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('actors') . ' ('
            . '`id` INTEGER PRIMARY KEY AUTOINCREMENT, `uid` INTEGER NOT NULL UNIQUE, `username` varchar(64) NOT NULL UNIQUE, '
            . '`private_key` text NOT NULL, `public_key` text NOT NULL, `created` INTEGER NOT NULL, `modified` INTEGER NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('followers') . ' ('
            . '`id` INTEGER PRIMARY KEY AUTOINCREMENT, `uid` INTEGER NOT NULL, `actor` varchar(512) NOT NULL, '
            . '`inbox` varchar(512) NOT NULL, `shared_inbox` varchar(512), `state` varchar(16) NOT NULL, `created` INTEGER NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('activities') . ' ('
            . '`aid` INTEGER PRIMARY KEY AUTOINCREMENT, `activity_hash` char(64) NOT NULL UNIQUE, `activity_id` text NOT NULL, '
            . '`type` varchar(32) NOT NULL, `actor` text NOT NULL, `object_id` text, `payload` text NOT NULL, '
            . '`status` varchar(16) NOT NULL, `created` INTEGER NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('inbound') . ' ('
            . '`iid` INTEGER PRIMARY KEY AUTOINCREMENT, `activity_hash` char(64) NOT NULL UNIQUE, '
            . '`actor_hash` char(64) NOT NULL, `source_domain` varchar(255) NOT NULL, `created` INTEGER NOT NULL)');
        $db->query('CREATE INDEX IF NOT EXISTS `' . $p . 'fediverse_inbound_actor_created` ON '
            . $q('inbound') . ' (`actor_hash`, `created`)');
        $db->query('CREATE INDEX IF NOT EXISTS `' . $p . 'fediverse_inbound_domain_created` ON '
            . $q('inbound') . ' (`source_domain`, `created`)');
        $db->query('CREATE INDEX IF NOT EXISTS `' . $p . 'fediverse_inbound_created` ON '
            . $q('inbound') . ' (`created`)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('queue') . ' ('
            . '`qid` INTEGER PRIMARY KEY AUTOINCREMENT, `uid` INTEGER NOT NULL, `inbox` varchar(512) NOT NULL, `activity` text NOT NULL, '
            . '`attempts` INTEGER NOT NULL DEFAULT 0, `available` INTEGER NOT NULL, `last_error` varchar(500), '
            . '`created` INTEGER NOT NULL, `updated` INTEGER NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('posts') . ' ('
            . '`cid` INTEGER PRIMARY KEY, `uid` INTEGER NOT NULL, `object_id` varchar(512) NOT NULL, '
            . '`content_hash` char(64) NOT NULL, `published` INTEGER NOT NULL, `updated` INTEGER NOT NULL)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('followings') . ' ('
            . '`id` INTEGER PRIMARY KEY AUTOINCREMENT, `uid` INTEGER NOT NULL, `actor_hash` char(64) NOT NULL, '
            . '`actor` varchar(512) NOT NULL, `inbox` varchar(512) NOT NULL, `shared_inbox` varchar(512), '
            . '`follow_id` varchar(512) NOT NULL, `state` varchar(16) NOT NULL, `created` INTEGER NOT NULL, '
            . '`updated` INTEGER NOT NULL, UNIQUE (`uid`, `actor_hash`))');
        $db->query('CREATE INDEX IF NOT EXISTS `' . $p . 'fediverse_followings_uid_state` ON '
            . $q('followings') . ' (`uid`, `state`)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('timeline') . ' ('
            . '`tid` INTEGER PRIMARY KEY AUTOINCREMENT, `uid` INTEGER NOT NULL, `object_hash` char(64) NOT NULL UNIQUE, '
            . '`activity_id` text NOT NULL, `object_id` text NOT NULL, `actor` text NOT NULL, `object_type` varchar(32) NOT NULL, '
            . '`url` text, `content` text NOT NULL, `published` INTEGER NOT NULL, `updated` INTEGER NOT NULL, '
            . '`liked_activity` text, `announced_activity` text, `deleted` INTEGER NOT NULL DEFAULT 0)');
        $db->query('CREATE INDEX IF NOT EXISTS `' . $p . 'fediverse_timeline_uid_published` ON '
            . $q('timeline') . ' (`uid`, `published`)');
        $db->query('CREATE INDEX IF NOT EXISTS `' . $p . 'fediverse_timeline_published` ON '
            . $q('timeline') . ' (`published`)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('replies') . ' ('
            . '`token` char(32) PRIMARY KEY, `uid` INTEGER NOT NULL, `in_reply_to` text NOT NULL, `content` text NOT NULL, '
            . '`to_actor` text NOT NULL, `published` INTEGER NOT NULL, `deleted` INTEGER NOT NULL DEFAULT 0)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $q('reply_objects') . ' ('
            . '`object_hash` char(64) PRIMARY KEY, `actor` text NOT NULL, `object_id` text NOT NULL, '
            . '`comment_id` INTEGER NOT NULL, `created` INTEGER NOT NULL)');
    }
}
