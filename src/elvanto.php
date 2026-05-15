<?php

declare(strict_types=1);

function elvanto_post(string $path, array $payload = []): array
{
    $apiKey = env('ELVANTO_API_KEY', '');
    if (!$apiKey) {
        throw new RuntimeException('ELVANTO_API_KEY fehlt.');
    }

    $url = 'https://api.elvanto.com/v1/' . ltrim($path, '/');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('Elvanto Payload konnte nicht serialisiert werden.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($apiKey . ':x'),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 45,
    ]);

    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        throw new RuntimeException('Elvanto API antwortet nicht: ' . $error);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Elvanto API HTTP ' . $code . ': ' . substr($response, 0, 500));
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Elvanto API lieferte ungueltiges JSON.');
    }
    if (isset($decoded['error'])) {
        $message = is_array($decoded['error']) ? ($decoded['error']['message'] ?? 'Elvanto API Fehler') : (string) $decoded['error'];
        throw new RuntimeException($message);
    }

    return $decoded;
}

