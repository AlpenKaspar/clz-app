<?php

declare(strict_types=1);

function web_push_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function web_push_read_der_length(string $der, int &$offset): int
{
    $length = ord($der[$offset] ?? "\0");
    $offset++;
    if ($length < 0x80) {
        return $length;
    }
    $bytes = $length & 0x7f;
    $length = 0;
    for ($i = 0; $i < $bytes; $i++) {
        $length = ($length << 8) | ord($der[$offset] ?? "\0");
        $offset++;
    }
    return $length;
}

function web_push_der_to_jose(string $der, int $partLength = 32): string
{
    $offset = 0;
    if (ord($der[$offset] ?? "\0") !== 0x30) {
        throw new RuntimeException('Invalid ECDSA signature.');
    }
    $offset++;
    web_push_read_der_length($der, $offset);

    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$offset] ?? "\0") !== 0x02) {
            throw new RuntimeException('Invalid ECDSA signature integer.');
        }
        $offset++;
        $length = web_push_read_der_length($der, $offset);
        $part = substr($der, $offset, $length);
        $offset += $length;
        $part = ltrim($part, "\x00");
        if (strlen($part) > $partLength) {
            $part = substr($part, -$partLength);
        }
        $parts[] = str_pad($part, $partLength, "\0", STR_PAD_LEFT);
    }

    return $parts[0] . $parts[1];
}

function web_push_private_key_pem(): string
{
    $file = env('VAPID_PRIVATE_KEY_FILE', '');
    if ($file !== null && $file !== '' && is_readable($file)) {
        return (string) file_get_contents($file);
    }

    $pem = env('VAPID_PRIVATE_KEY_PEM', '');
    $pem = str_replace('\\n', "\n", (string) $pem);
    return trim($pem);
}

function web_push_vapid_headers(string $endpoint): array
{
    $publicKey = trim((string) env('VAPID_PUBLIC_KEY', ''));
    $privatePem = web_push_private_key_pem();
    if ($publicKey === '' || $privatePem === '') {
        throw new RuntimeException('VAPID_PUBLIC_KEY und VAPID_PRIVATE_KEY_PEM oder VAPID_PRIVATE_KEY_FILE fehlen.');
    }

    $parts = parse_url($endpoint);
    $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    if ($audience === 'https://') {
        throw new RuntimeException('Push endpoint ist ungültig.');
    }

    $header = web_push_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
    $payload = web_push_base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 60 * 60,
        'sub' => env('VAPID_SUBJECT', 'mailto:info@clzspiez.ch'),
    ], JSON_THROW_ON_ERROR));
    $input = $header . '.' . $payload;

    $privateKey = openssl_pkey_get_private($privatePem);
    if ($privateKey === false) {
        throw new RuntimeException('VAPID Private Key konnte nicht gelesen werden.');
    }
    $signature = '';
    if (!openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID Signatur konnte nicht erstellt werden.');
    }
    $jwt = $input . '.' . web_push_base64url_encode(web_push_der_to_jose($signature));

    return [
        'Authorization: vapid t=' . $jwt . ', k=' . $publicKey,
        'TTL: 86400',
        'Urgency: normal',
        'Content-Length: 0',
    ];
}

function web_push_send_empty(array $subscription): array
{
    $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
    if ($endpoint === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Endpoint fehlt.'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => web_push_vapid_headers($endpoint),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $error,
        'response' => is_string($response) ? substr($response, 0, 500) : '',
    ];
}
