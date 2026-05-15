<?php

declare(strict_types=1);

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name((string) env('APP_SESSION_NAME', 'clz_app_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_https_request(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return $https === 'on' || $https === '1' || $forwardedProto === 'https';
}

function app_base_url(): string
{
    $configured = rtrim((string) env('APP_URL', ''), '/');
    if ($configured !== '') {
        return $configured;
    }

    $scheme = is_https_request() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$scheme}://{$host}";
}

function google_oauth_redirect_uri(): string
{
    return app_base_url() . '/api/auth/google-callback.php';
}

function current_user(): ?array
{
    start_app_session();
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, email, display_name, role, is_active FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $id]);
    $user = $stmt->fetch();
    return is_array($user) ? user_payload($user) : null;
}

function user_payload(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'email' => $user['email'] ?? '',
        'displayName' => $user['display_name'] ?? '',
        'role' => $user['role'] ?? 'guest',
        'isAuthenticated' => true,
    ];
}

function guest_user_payload(): array
{
    return [
        'id' => null,
        'email' => '',
        'displayName' => '',
        'role' => 'guest',
        'isAuthenticated' => false,
    ];
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        json_error('Bitte anmelden.', 401);
    }
    return $user;
}

function login_user_from_google(array $profile): array
{
    $email = strtolower(trim((string) ($profile['email'] ?? '')));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Google-Profil enthaelt keine gueltige E-Mail-Adresse.');
    }

    $displayName = trim((string) ($profile['name'] ?? ''));
    if ($displayName === '') {
        $displayName = $email;
    }

    $role = google_login_role_for_email($email);
    $stmt = db()->prepare(
        "INSERT INTO users (email, display_name, role, is_active, last_login_at)
         VALUES (:email, :display_name, :role, 1, :last_login_at)
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
            role = IF(VALUES(role) = 'admin', 'admin', IF(role = 'guest', VALUES(role), role)),
            is_active = 1,
            last_login_at = VALUES(last_login_at)"
    );
    $stmt->execute([
        ':email' => $email,
        ':display_name' => $displayName,
        ':role' => $role,
        ':last_login_at' => date('Y-m-d H:i:s'),
    ]);

    $stmt = db()->prepare('SELECT id, email, display_name, role, is_active FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!is_array($user)) {
        throw new RuntimeException('User konnte nicht geladen werden.');
    }

    start_app_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return user_payload($user);
}

function google_login_role_for_email(string $email): string
{
    $adminEmails = array_filter(array_map(
        static fn(string $item): string => strtolower(trim($item)),
        explode(',', (string) env('APP_ADMIN_EMAILS', ''))
    ));

    return in_array(strtolower($email), $adminEmails, true) ? 'admin' : 'member';
}

function logout_user(): void
{
    start_app_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function google_oauth_is_configured(): bool
{
    return trim((string) env('GOOGLE_CLIENT_ID', '')) !== '' && trim((string) env('GOOGLE_CLIENT_SECRET', '')) !== '';
}
