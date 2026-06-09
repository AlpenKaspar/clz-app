<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

$url = trim((string) ($_GET['url'] ?? ''));
if ($url === '') {
    http_response_code(400);
    echo 'Missing PDF URL.';
    exit;
}

$parts = parse_url($url);
$host = strtolower((string) ($parts['host'] ?? ''));
$scheme = strtolower((string) ($parts['scheme'] ?? ''));
$path = (string) ($parts['path'] ?? '');

$allowedHosts = ['clzspiez.ch', 'www.clzspiez.ch'];
if (!in_array($scheme, ['https'], true) || !in_array($host, $allowedHosts, true) || !preg_match('/\.pdf$/i', $path)) {
    http_response_code(403);
    echo 'PDF URL is not allowed.';
    exit;
}

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 20,
        'follow_location' => 1,
        'header' => "User-Agent: CLZ-App-PDF-Proxy/1.0\r\n",
    ],
]);

$data = @file_get_contents($url, false, $context);
if ($data === false || $data === '') {
    http_response_code(502);
    echo 'PDF could not be loaded.';
    exit;
}

if (strncmp($data, '%PDF', 4) !== 0) {
    http_response_code(415);
    echo 'Remote file is not a PDF.';
    exit;
}

$filename = basename($path) ?: 'predigtscript.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($data));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
header('Cache-Control: public, max-age=3600');
echo $data;
