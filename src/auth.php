<?php

declare(strict_types=1);

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = app_session_lifetime_seconds();
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.cookie_lifetime', (string) $lifetime);

    session_name((string) env('APP_SESSION_NAME', 'clz_app_session'));
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function app_session_lifetime_seconds(): int
{
    $days = (int) env('APP_SESSION_DAYS', '30');
    if ($days < 1) {
        $days = 30;
    }
    if ($days > 365) {
        $days = 365;
    }
    return $days * 24 * 60 * 60;
}

function refresh_app_session_cookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !ini_get('session.use_cookies')) {
        return;
    }

    $params = session_get_cookie_params();
    $options = [
        'expires' => time() + app_session_lifetime_seconds(),
        'path' => $params['path'] ?: '/',
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => (bool) ($params['httponly'] ?? true),
        'samesite' => $params['samesite'] ?? 'Lax',
    ];
    if (!empty($params['domain'])) {
        $options['domain'] = $params['domain'];
    }
    setcookie(session_name(), session_id(), $options);
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
    if (is_array($user) && google_login_role_for_email((string) ($user['email'] ?? '')) === 'admin' && ($user['role'] ?? '') !== 'admin') {
        $upgrade = db()->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $upgrade->execute([(int) $user['id']]);
        $user['role'] = 'admin';
    }
    if (!is_array($user)) {
        return null;
    }

    refresh_app_session_cookie();
    return user_payload($user);
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
