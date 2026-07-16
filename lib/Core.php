<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Fediverse_Core
{
    private static $settings;

    public static function helperCall($method, ...$args)
    {
        foreach (array('Utils\\Helper', 'Typecho\\Helper', 'Helper') as $class) {
            if (class_exists($class) && method_exists($class, $method)) {
                return call_user_func_array(array($class, $method), $args);
            }
        }
        throw new RuntimeException('Typecho Helper::' . $method . ' is unavailable');
    }

    public static function settings()
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        try {
            self::$settings = Typecho_Widget::widget('Widget_Options')->plugin('Fediverse');
        } catch (Throwable $e) {
            self::$settings = new Typecho_Config(array());
        }
        return self::$settings;
    }

    public static function setting($name, $default = null)
    {
        $settings = self::settings();
        $value = isset($settings->{$name}) ? $settings->{$name} : $default;
        return $value === '' ? $default : $value;
    }

    public static function isEnabled()
    {
        return (string)self::setting('enabled', '1') === '1';
    }

    public static function options()
    {
        return Typecho_Widget::widget('Widget_Options');
    }

    public static function baseUrl()
    {
        return rtrim((string)self::options()->index, '/');
    }

    public static function origin()
    {
        $url = parse_url((string)self::options()->siteUrl);
        $scheme = $url['scheme'] ?? 'https';
        $host = self::domain();
        $port = isset($url['port']) ? ':' . (int)$url['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    public static function domain()
    {
        $domain = strtolower(trim((string)self::setting('domain', '')));
        if ($domain !== '') {
            return preg_replace('/[^a-z0-9.:-]/i', '', $domain);
        }
        return strtolower((string)parse_url((string)self::options()->siteUrl, PHP_URL_HOST));
    }

    public static function url($path)
    {
        return self::baseUrl() . '/' . ltrim((string)$path, '/');
    }

    public static function actorUrl($username)
    {
        return self::url('fediverse/author/' . rawurlencode((string)$username));
    }

    public static function objectUrl($cid)
    {
        return self::url('fediverse/post/' . (int)$cid);
    }

    public static function userByUsername($username)
    {
        $username = strtolower(trim((string)$username));
        $db = Typecho_Db::get();
        $actor = $db->fetchRow($db->select()->from(Fediverse_Database::table('actors'))
            ->where('username = ?', $username)->limit(1));
        if ($actor) {
            return self::userById((int)$actor['uid']);
        }

        $users = $db->fetchAll($db->select()->from('table.users'));
        foreach ($users as $user) {
            if (self::usernameForUser($user) === $username && self::userEnabled((int)$user['uid'])) {
                self::ensureActor($user, $username);
                return $user;
            }
        }
        return null;
    }

    public static function userById($uid)
    {
        $db = Typecho_Db::get();
        return $db->fetchRow($db->select()->from('table.users')->where('uid = ?', (int)$uid)->limit(1)) ?: null;
    }

    public static function personalSettings($uid)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('value')->from('table.options')
            ->where('name = ?', '_plugin:Fediverse')->where('user = ?', (int)$uid)->limit(1));
        if (!$row || !isset($row['value'])) {
            return array();
        }
        $value = @unserialize((string)$row['value']);
        if (!is_array($value)) {
            $value = json_decode((string)$row['value'], true);
        }
        return is_array($value) ? $value : array();
    }

    public static function userEnabled($uid)
    {
        $settings = self::personalSettings($uid);
        return !isset($settings['enabled']) || (string)$settings['enabled'] === '1';
    }

    public static function usernameForUser($user)
    {
        $settings = self::personalSettings((int)$user['uid']);
        $name = trim((string)($settings['username'] ?? ''));
        if ($name === '') {
            $name = trim((string)$user['name']);
        }
        $name = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $name));
        return $name !== '' ? substr($name, 0, 64) : 'user' . (int)$user['uid'];
    }

    public static function ensureActor($user, $username = null)
    {
        $db = Typecho_Db::get();
        $table = Fediverse_Database::table('actors');
        $existing = $db->fetchRow($db->select()->from($table)->where('uid = ?', (int)$user['uid'])->limit(1));
        if ($existing) {
            return $existing;
        }

        $username = $username ?: self::usernameForUser($user);
        $collision = $db->fetchRow($db->select('uid')->from($table)
            ->where('username = ?', strtolower($username))->limit(1));
        if ($collision && (int)$collision['uid'] !== (int)$user['uid']) {
            $suffix = '-' . (int)$user['uid'];
            $username = substr((string)$username, 0, 64 - strlen($suffix)) . $suffix;
        }
        $key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
        if ($key === false) {
            throw new RuntimeException('Unable to generate author RSA key');
        }
        $private = '';
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        $public = (string)($details['key'] ?? '');
        $now = time();
        $db->query($db->insert($table)->rows(array(
            'uid' => (int)$user['uid'],
            'username' => strtolower($username),
            'private_key' => $private,
            'public_key' => $public,
            'created' => $now,
            'modified' => $now
        )));
        return $db->fetchRow($db->select()->from($table)->where('uid = ?', (int)$user['uid'])->limit(1));
    }

    public static function actorByUid($uid)
    {
        $user = self::userById((int)$uid);
        return $user && self::userEnabled((int)$uid) ? self::ensureActor($user) : null;
    }

    public static function json($value)
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function iso8601($timestamp)
    {
        return gmdate('Y-m-d\\TH:i:s\\Z', (int)$timestamp);
    }

    public static function activityId($prefix)
    {
        return self::url('fediverse/activity/' . rawurlencode((string)$prefix) . '-' . bin2hex(random_bytes(10)));
    }
}
