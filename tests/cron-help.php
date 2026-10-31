<?php

$source = file_get_contents(dirname(__DIR__) . '/manage.php');
$required = array(
    '如何设置定时任务',
    '宝塔面板',
    '支持 URL 定时访问的主机面板',
    'Linux Crontab',
    'curl -fsS --max-time 50',
    'processed 是本次处理数',
    'Cron 地址包含私密令牌'
);

foreach ($required as $text) {
    if (!str_contains($source, $text)) {
        fwrite(STDERR, 'Cron help is missing: ' . $text . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, "cron-help: ok\n");
