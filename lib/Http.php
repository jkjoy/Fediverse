<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Fediverse_Http
{
    private const MAX_RESPONSE = 2097152;

    public static function getJson($url)
    {
        $response = self::request('GET', $url, array(
            'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams", application/json',
            'User-Agent: Typecho-Fediverse/0.1'
        ));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Remote HTTP status ' . $response['status']);
        }
        $data = json_decode($response['body'], true, 32);
        if (!is_array($data)) {
            throw new RuntimeException('Remote response is not valid JSON');
        }
        return $data;
    }

    public static function signedPost($url, $activity, $actor)
    {
        $body = Fediverse_Core::json($activity);
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $host = $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $host .= ':' . (int)$parts['port'];
        }
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        $signed = '(request-target): post ' . $path . "\n"
            . 'host: ' . $host . "\n"
            . 'date: ' . $date . "\n"
            . 'digest: ' . $digest;
        $signature = '';
        if (!openssl_sign($signed, $signature, $actor['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign delivery');
        }
        $keyId = Fediverse_Core::actorUrl($actor['username']) . '#main-key';
        $signatureHeader = 'keyId="' . addcslashes($keyId, '"\\')
            . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="'
            . base64_encode($signature) . '"';

        $response = self::request('POST', $url, array(
            'Accept: application/activity+json',
            'Content-Type: application/activity+json',
            'User-Agent: Typecho-Fediverse/0.1',
            'Host: ' . $host,
            'Date: ' . $date,
            'Digest: ' . $digest,
            'Signature: ' . $signatureHeader
        ), $body);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Inbox HTTP status ' . $response['status']);
        }
        return true;
    }

    public static function verifyIncoming($request, $rawBody, $expectedActor = null)
    {
        $digest = trim((string)$request->getHeader('Digest', ''));
        $date = trim((string)$request->getHeader('Date', ''));
        $signatureHeader = trim((string)$request->getHeader('Signature', ''));
        if ($digest === '' || $date === '' || $signatureHeader === '') {
            throw new RuntimeException('Missing Digest, Date or Signature header');
        }
        $expectedDigest = 'SHA-256=' . base64_encode(hash('sha256', $rawBody, true));
        if (!hash_equals($expectedDigest, $digest)) {
            throw new RuntimeException('Digest verification failed');
        }
        $time = strtotime($date);
        if ($time === false || abs(time() - $time) > 43200) {
            throw new RuntimeException('Request date is outside the allowed window');
        }

        $params = self::parseSignature($signatureHeader);
        if (empty($params['keyId']) || empty($params['signature'])) {
            throw new RuntimeException('Invalid Signature header');
        }
        $headers = preg_split('/\s+/', strtolower(trim((string)($params['headers'] ?? 'date'))));
        foreach (array('(request-target)', 'host', 'date', 'digest') as $required) {
            if (!in_array($required, $headers, true)) {
                throw new RuntimeException('Signature does not cover ' . $required);
            }
        }
        $lines = array();
        foreach ($headers as $name) {
            if ($name === '(request-target)') {
                $method = strtolower((string)$request->getServer('REQUEST_METHOD', 'POST'));
                $uri = (string)$request->getRequestUri();
                $lines[] = '(request-target): ' . $method . ' ' . $uri;
            } else {
                $value = $request->getHeader($name, '');
                if ($value === '') {
                    throw new RuntimeException('Signed header is missing: ' . $name);
                }
                $lines[] = $name . ': ' . $value;
            }
        }

        $document = self::getJson($params['keyId']);
        $publicKey = self::extractPublicKey($document, $params['keyId']);
        $owner = $publicKey['owner'] ?? ($document['id'] ?? '');
        if ($expectedActor !== null && $owner !== '' && rtrim((string)$owner, '/') !== rtrim((string)$expectedActor, '/')) {
            throw new RuntimeException('Signature key owner does not match activity actor');
        }
        $decoded = base64_decode($params['signature'], true);
        if ($decoded === false || openssl_verify(implode("\n", $lines), $decoded, $publicKey['publicKeyPem'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('HTTP signature verification failed');
        }
        return true;
    }

    private static function extractPublicKey($document, $keyId)
    {
        $key = $document['publicKey'] ?? null;
        if (is_array($key) && isset($key[0])) {
            foreach ($key as $candidate) {
                if (($candidate['id'] ?? '') === $keyId) {
                    $key = $candidate;
                    break;
                }
            }
        }
        if (!is_array($key) || empty($key['publicKeyPem'])) {
            throw new RuntimeException('Remote public key was not found');
        }
        return $key;
    }

    private static function parseSignature($header)
    {
        $result = array();
        preg_match_all('/([A-Za-z]+)="((?:\\\\.|[^"])*)"/', $header, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $result[$match[1]] = stripcslashes($match[2]);
        }
        return $result;
    }

    private static function request($method, $url, $headers, $body = null)
    {
        $resolvedIp = self::assertSafeUrl($url);
        $parts = parse_url($url);
        $host = trim((string)$parts['host'], '[]');
        $port = (int)($parts['port'] ?? 443);
        $resolveIp = str_contains($resolvedIp, ':') ? '[' . $resolvedIp . ']' : $resolvedIp;
        $responseBody = '';
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => array($host . ':' . $port . ':' . $resolveIp),
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$responseBody, &$tooLarge) {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            }
        ));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $success = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($tooLarge) {
            throw new RuntimeException('Remote response is too large');
        }
        if ($success === false) {
            throw new RuntimeException('Remote request failed: ' . $error);
        }
        return array('status' => $status, 'body' => $responseBody);
    }

    private static function assertSafeUrl($url)
    {
        $parts = parse_url((string)$url);
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('Remote URL must use HTTPS');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Remote URL credentials are not allowed');
        }
        $host = trim((string)$parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : self::resolveHost($host);
        if (!$ips) {
            throw new RuntimeException('Remote host could not be resolved');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Remote URL resolves to a private or reserved address');
            }
        }
        return $ips[0];
    }

    private static function resolveHost($host)
    {
        $ips = array();
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : array() as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }
}
