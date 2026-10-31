<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));

class Typecho_Config
{
    public string $enabled = '';
    public string $domain = '';

    public function __construct($values = array())
    {
        foreach ($values as $name => $value) {
            $this->{$name} = $value;
        }
    }
}

class FakeDomainOptions
{
    public string $siteUrl = 'https://fallback.example/';
    public string $index = 'https://fallback.example/';
    public Typecho_Config $settings;

    public function plugin($name)
    {
        return $this->settings;
    }
}

class Typecho_Widget
{
    public static FakeDomainOptions $options;

    public static function widget($name)
    {
        return self::$options;
    }
}

require dirname(__DIR__) . '/lib/Core.php';

function domainSettings($settings)
{
    Typecho_Widget::$options = new FakeDomainOptions();
    Typecho_Widget::$options->settings = new Typecho_Config($settings);
    $property = new ReflectionProperty(Fediverse_Core::class, 'settings');
    $property->setValue(null, null);
}

function checkDomain($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

domainSettings(array('enabled' => '1', 'domain' => ''));
checkDomain(!Fediverse_Core::hasDomain(), 'An empty federation domain must be unconfigured');
checkDomain(!Fediverse_Core::isEnabled(), 'Federation must stay disabled until a domain is configured');

require dirname(__DIR__) . '/lib/Queue.php';
require dirname(__DIR__) . '/lib/Client.php';
checkDomain(Fediverse_Queue::enqueueActorUpdate(1) === 0, 'Profile delivery must not be queued without a domain');
foreach (array(
    static fn() => Fediverse_Queue::run(),
    static fn() => Fediverse_Client::follow(1, '@remote@example.com')
) as $operation) {
    try {
        $operation();
        checkDomain(false, 'A federation operation ran without a domain');
    } catch (RuntimeException $e) {
        checkDomain(str_contains($e->getMessage(), '联邦域名'), 'Missing-domain failure must explain the requirement');
    }
}

domainSettings(array('enabled' => '1', 'domain' => 'laosun.de'));
checkDomain(Fediverse_Core::hasDomain(), 'A public DNS name must be accepted');
checkDomain(Fediverse_Core::isEnabled(), 'Federation may start after a domain is configured');
checkDomain(Fediverse_Core::domain() === 'laosun.de', 'The configured domain must be canonical');

foreach (array('https://laosun.de', 'laosun.de/path', 'localhost', 'laosun.de:443', '-bad.example') as $invalid) {
    domainSettings(array('enabled' => '1', 'domain' => $invalid));
    checkDomain(!Fediverse_Core::hasDomain(), 'Invalid federation domain was accepted: ' . $invalid);
}

$pluginSource = file_get_contents(dirname(__DIR__) . '/Plugin.php');
checkDomain(str_contains($pluginSource, '$domain->addRule(\'required\''), 'The domain field must be required');

fwrite(STDOUT, "domain-required: ok\n");
