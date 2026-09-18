<?php

define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));
require dirname(__DIR__) . '/lib/Http.php';

function checkKey($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function rejectsKey($method, $document, $keyId, $actorId)
{
    try {
        $method->invoke(null, $document, $keyId, $actorId);
        return false;
    } catch (RuntimeException $e) {
        return true;
    }
}

$actor = 'https://social.example/users/alice';
$keyId = $actor . '#main-key';
$key = array('id' => $keyId, 'owner' => $actor, 'publicKeyPem' => 'PUBLIC KEY');
$document = array('id' => $actor, 'publicKey' => $key);
$extract = new ReflectionMethod(Fediverse_Http::class, 'extractPublicKey');
checkKey($extract->invoke(null, $document, $keyId, $actor) === $key, 'Actor-owned key must be accepted');
checkKey(rejectsKey($extract, array('id' => 'https://attacker.example/actor', 'publicKey' => $key), $keyId, $actor),
    'Key document must belong to the claimed actor');
checkKey(rejectsKey($extract, array('id' => $actor, 'publicKey' => array('id' => $keyId, 'publicKeyPem' => 'PUBLIC KEY')),
    $keyId, $actor), 'A key without owner must be rejected');
checkKey(rejectsKey($extract, $document, 'https://attacker.example/key', $actor),
    'The signed key ID must appear in the actor document');
checkKey(rejectsKey($extract, array('id' => $actor, 'publicKey' => array($key, array(
    'id' => 'https://attacker.example/key', 'owner' => $actor, 'publicKeyPem' => 'OTHER KEY'
))), 'https://missing.example/key', $actor), 'An unmatched key in a list must be rejected');

$redirect = new ReflectionMethod(Fediverse_Http::class, 'redirectUrl');
checkKey($redirect->invoke(null, 'https://social.example/old/inbox', '../new/inbox')
    === 'https://social.example/new/inbox', 'Relative inbox redirects must resolve correctly');
checkKey($redirect->invoke(null, 'https://social.example/old/inbox', 'https://social.example/inbox')
    === 'https://social.example/inbox', 'Absolute inbox redirects must resolve correctly');

fwrite(STDOUT, "signature-keys: ok\n");
