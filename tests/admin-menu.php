<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

function _t($message, ...$args)
{
    return $args ? vsprintf((string)$message, $args) : (string)$message;
}

interface Typecho_Plugin_Interface
{
}

interface Widget_Interface_Do
{
}

class FakeOptions
{
    public array $panelTable = array(
        'child' => array(
            3 => array(array(
                '联邦宇宙',
                '联邦账号与投递管理',
                'extending.php?panel=Fediverse%2Fmanage.php',
                'administrator',
                false,
                ''
            ))
        ),
        'file' => array('Fediverse%2Fmanage.php')
    );
}

class Typecho_Widget
{
    public static FakeOptions $options;

    public static function widget($name, ...$args)
    {
        return self::$options;
    }
}

class Typecho_Plugin
{
    public static function factory($name)
    {
        return new self();
    }
}

class Helper
{
    public static function addMenu($name)
    {
        $table = Typecho_Widget::$options->panelTable;
        $table['parent'][] = $name;
        Typecho_Widget::$options->panelTable = $table;
        end($table['parent']);
        return key($table['parent']) + 10;
    }

    public static function removeMenu($name)
    {
        $table = Typecho_Widget::$options->panelTable;
        $key = array_search($name, $table['parent'] ?? array(), true);
        if ($key !== false) {
            unset($table['parent'][$key]);
        }
        Typecho_Widget::$options->panelTable = $table;
        return $key === false ? 0 : $key + 10;
    }

    public static function addPanel($index, $file, $title, $subtitle, $level)
    {
        $table = Typecho_Widget::$options->panelTable;
        $encoded = urlencode(trim((string)$file, '/'));
        $table['child'][$index][] = array(
            $title,
            $subtitle,
            'extending.php?panel=' . $encoded,
            $level,
            false,
            ''
        );
        $table['file'][] = $encoded;
        $table['file'] = array_values(array_unique($table['file']));
        Typecho_Widget::$options->panelTable = $table;
    }

    public static function removePanel($index, $file)
    {
        $table = Typecho_Widget::$options->panelTable;
        $encoded = urlencode(trim((string)$file, '/'));
        foreach ($table['child'][$index] ?? array() as $key => $item) {
            if (($item[2] ?? '') === 'extending.php?panel=' . $encoded) {
                unset($table['child'][$index][$key]);
            }
        }
        $table['file'] = array_values(array_filter(
            $table['file'] ?? array(),
            static fn($item) => $item !== $encoded
        ));
        Typecho_Widget::$options->panelTable = $table;
    }
}

Typecho_Widget::$options = new FakeOptions();
require dirname(__DIR__) . '/Plugin.php';

function assertMenu($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$method = new ReflectionMethod(Fediverse_Plugin::class, 'ensureAdminPanels');
$method->invoke(null);
$method->invoke(null);

$table = Typecho_Widget::$options->panelTable;
assertMenu(($table['parent'] ?? array()) === array('联邦宇宙'), 'The top-level menu must be registered once');

$manage = array_values(array_filter(
    $table['child'][3] ?? array(),
    static fn($item) => ($item[2] ?? '') === 'extending.php?panel=Fediverse%2Fmanage.php'
));
assertMenu(count($manage) === 1 && $manage[0][0] === '联邦管理', 'The legacy panel must be renamed once');

$children = array_values($table['child'][10] ?? array());
assertMenu(count($children) === 3, 'The Fediverse menu must have three child panels');
assertMenu(
    array_column($children, 0) === array('时间轴', '关注者', '正在关注'),
    'Fediverse child panels must keep the requested order'
);

fwrite(STDOUT, "admin-menu: ok\n");
