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
$length = strlen($data);
$range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
header('Cache-Control: public, max-age=3600');
header('Accept-Ranges: bytes');

if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match)) {
    $start = $match[1] !== '' ? (int) $match[1] : 0;
    $end = $match[2] !== '' ? (int) $match[2] : $length - 1;
    if ($match[1] === '' && $match[2] !== '') {
        $suffixLength = max(0, (int) $match[2]);
        $start = max(0, $length - $suffixLength);
        $end = $length - 1;
    }
    $start = max(0, min($start, max(0, $length - 1)));
    $end = max($start, min($end, max(0, $length - 1)));
    $chunkLength = $end - $start + 1;

    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$length}");
    header('Content-Length: ' . $chunkLength);
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo substr($data, $start, $chunkLength);
    }
    exit;
}

header('Content-Length: ' . $length);
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    echo $data;
}
