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
    $envRole = is_array($user) ? google_login_role_for_email((string) ($user['email'] ?? '')) : 'guest';
    if (is_array($user) && in_array($envRole, ['admin', 'super_admin'], true) && ($user['role'] ?? '') !== $envRole) {
        $upgrade = db()->prepare("UPDATE users SET role = ? WHERE id = ?");
        $upgrade->execute([$envRole, (int) $user['id']]);
        $user['role'] = $envRole;
    }
    if (!is_array($user)) {
        return null;
    }

    refresh_app_session_cookie();
    $payload = user_payload($user);
    $impersonatorId = $_SESSION['impersonator_user_id'] ?? null;
    if ($impersonatorId) {
        $adminStmt = db()->prepare('SELECT id, email, display_name, role, is_active FROM users WHERE id = ? AND is_active = 1');
        $adminStmt->execute([(int) $impersonatorId]);
        $admin = $adminStmt->fetch();
        if (is_array($admin)) {
            $payload['impersonating'] = [
                'active' => true,
                'originalUser' => user_payload($admin),
            ];
        }
    }
    return $payload;
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
    $existingUser = find_user_by_email_for_login($email);
    $stmt = db()->prepare(
        "INSERT INTO users (email, display_name, role, is_active, last_login_at)
         VALUES (:email, :display_name, :role, 1, :last_login_at)
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
            role = IF(VALUES(role) IN ('admin', 'super_admin'), VALUES(role), IF(role = 'guest', VALUES(role), role)),
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

    if (!$existingUser && ($user['role'] ?? '') === 'guest') {
        notify_super_admins_about_new_guest_user($user);
    }

    start_app_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['impersonator_user_id']);
    return user_payload($user);
}

function find_user_by_email_for_login(string $email): ?array
{
    $stmt = db()->prepare('SELECT id, email, display_name, role, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function ensure_admin_push_notification_schema(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS push_subscriptions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            endpoint text NOT NULL,
            endpoint_hash char(64) NOT NULL,
            p256dh text NULL,
            auth text NULL,
            user_agent text NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            last_seen_at datetime NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
            KEY idx_push_user_active (user_id, is_active),
            CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS push_notification_log (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            notification_key varchar(190) NOT NULL,
            sent_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_push_notification_log_user_key (user_id, notification_key),
            KEY idx_push_notification_log_sent (sent_at),
            CONSTRAINT fk_push_notification_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS push_pending_notifications (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            notification_key varchar(190) NOT NULL,
            title varchar(190) NOT NULL,
            body text NULL,
            url text NULL,
            tag varchar(190) NULL,
            created_at datetime NOT NULL,
            consumed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_push_pending_user_key (user_id, notification_key),
            KEY idx_push_pending_user (user_id, consumed_at, created_at),
            CONSTRAINT fk_push_pending_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function notify_super_admins_about_new_guest_user(array $newUser): void
{
    try {
        ensure_admin_push_notification_schema();
        require_once __DIR__ . '/web_push.php';

        $guestId = (int) ($newUser['id'] ?? 0);
        if ($guestId <= 0) {
            return;
        }
        $name = trim((string) ($newUser['display_name'] ?? ''));
        $email = strtolower(trim((string) ($newUser['email'] ?? '')));
        $label = $name !== '' ? $name : $email;
        if ($label === '') {
            $label = 'Ein neuer Benutzer';
        }
        $key = 'new_guest_user_' . $guestId;
        $title = 'Neue Anmeldung wartet';
        $body = $label . ' hat sich als Gast registriert.';
        $url = '/?tab=tools&panel=users';
        $tag = 'clz-new-guest-user-' . $guestId;

        $admins = db()->query(
            "SELECT id, email
             FROM users
             WHERE role = 'super_admin'
               AND is_active = 1"
        )->fetchAll();

        foreach ($admins as $admin) {
            $adminId = (int) ($admin['id'] ?? 0);
            if ($adminId <= 0 || $adminId === $guestId) {
                continue;
            }
            if (admin_push_notification_was_sent($adminId, $key)) {
                continue;
            }
            queue_pending_push_notification($adminId, $key, $title, $body, $url, $tag);
            $sent = send_empty_push_to_user($adminId);
            if ($sent) {
                mark_admin_push_notification_sent($adminId, $key);
            }
        }
    } catch (Throwable) {
        // A failed admin push must never block login.
    }
}

function admin_push_notification_was_sent(int $userId, string $key): bool
{
    $stmt = db()->prepare('SELECT 1 FROM push_notification_log WHERE user_id = ? AND notification_key = ? LIMIT 1');
    $stmt->execute([$userId, $key]);
    return (bool) $stmt->fetchColumn();
}

function queue_pending_push_notification(int $userId, string $key, string $title, string $body, string $url, string $tag): void
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO push_pending_notifications (user_id, notification_key, title, body, url, tag, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$userId, $key, $title, $body, $url, $tag]);
}

function send_empty_push_to_user(int $userId): bool
{
    $stmt = db()->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll();
    if (!$subscriptions) {
        return false;
    }

    $ok = false;
    foreach ($subscriptions as $subscription) {
        try {
            $result = web_push_send_empty($subscription);
            if ($result['ok'] ?? false) {
                $ok = true;
                continue;
            }
            if (in_array((int) ($result['status'] ?? 0), [404, 410], true)) {
                $deactivate = db()->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE id = ?');
                $deactivate->execute([(int) ($subscription['id'] ?? 0)]);
            }
        } catch (Throwable) {
            // Try the next device.
        }
    }
    return $ok;
}

function mark_admin_push_notification_sent(int $userId, string $key): void
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO push_notification_log (user_id, notification_key, sent_at)
         VALUES (?, ?, NOW())'
    );
    $stmt->execute([$userId, $key]);
}

function google_login_role_for_email(string $email): string
{
    $superAdminRaw = (string) env('APP_SUPER_ADMIN_EMAILS', '');
    $superAdminEmails = array_filter(array_map(
        static fn(string $item): string => strtolower(trim($item)),
        explode(',', $superAdminRaw)
    ));
    $adminEmails = array_filter(array_map(
        static fn(string $item): string => strtolower(trim($item)),
        explode(',', (string) env('APP_ADMIN_EMAILS', ''))
    ));

    $email = strtolower($email);
    if (in_array($email, $superAdminEmails, true)) {
        return 'super_admin';
    }
    return in_array($email, $adminEmails, true) ? 'admin' : 'guest';
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
