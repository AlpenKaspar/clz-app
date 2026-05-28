<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/web_push.php';

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);

if ($key === false) {
    fwrite(STDERR, "Could not generate VAPID key.\n");
    exit(1);
}

$privatePem = '';
openssl_pkey_export($key, $privatePem);
$details = openssl_pkey_get_details($key);
$x = $details['ec']['x'] ?? '';
$y = $details['ec']['y'] ?? '';

if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
    fwrite(STDERR, "Could not read VAPID public key coordinates.\n");
    exit(1);
}

$publicKey = web_push_base64url_encode("\x04" . $x . $y);
$privateEnv = str_replace("\n", '\\n', trim($privatePem));

echo "VAPID_PUBLIC_KEY={$publicKey}\n";
echo "VAPID_PRIVATE_KEY_PEM={$privateEnv}\n";
echo "VAPID_SUBJECT=mailto:info@clzspiez.ch\n";
