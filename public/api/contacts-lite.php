<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    require_user();

    $stmt = db()->query(
        "SELECT id, firstname, preferred_name, lastname, display_name, email, phone, mobile,
                category_name, family_id, gender, birthday, home_address, home_city, home_postcode, picture_url
         FROM people
         ORDER BY lastname, firstname"
    );

    $contacts = [];
    foreach ($stmt as $row) {
        $contacts[] = [
            'id' => $row['id'],
            'name' => $row['display_name'] ?: trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
            'first' => $row['firstname'] ?? '',
            'preferred' => $row['preferred_name'] ?? '',
            'last' => $row['lastname'] ?? '',
            'email' => $row['email'] ?? '',
            'phone' => $row['phone'] ?? '',
            'mobile' => $row['mobile'] ?? '',
            'category' => $row['category_name'] ?? '',
            'familyId' => $row['family_id'] ?? '',
            'gender' => $row['gender'] ?? '',
            'birthday' => $row['birthday'] ?? '',
            'address' => $row['home_address'] ?? '',
            'city' => $row['home_city'] ?? '',
            'postcode' => $row['home_postcode'] ?? '',
            'picture' => $row['picture_url'] ?? '',
        ];
    }

    json_response([
        'ok' => true,
        'ts' => date('c'),
        'dataVersion' => app_setting_contacts('DATA_VERSION', '1'),
        'contacts' => $contacts,
    ]);
} catch (Throwable $e) {
    json_error('Kontakte konnten nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

function app_setting_contacts(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : $default;
}
