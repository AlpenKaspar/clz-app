<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

function ensure_pending_push_notification_schema(): void
{
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

try {
    $user = current_user();
    if (!$user || empty($user['isAuthenticated'])) {
        json_error('Nicht angemeldet.', 401);
    }

    ensure_pending_push_notification_schema();
    $stmt = db()->prepare(
        'SELECT id, notification_key, title, body, url, tag
         FROM push_pending_notifications
         WHERE user_id = ?
           AND consumed_at IS NULL
         ORDER BY created_at, id
         LIMIT 1'
    );
    $stmt->execute([(int) ($user['id'] ?? 0)]);
    $row = $stmt->fetch();

    if (is_array($row)) {
        $update = db()->prepare('UPDATE push_pending_notifications SET consumed_at = NOW() WHERE id = ?');
        $update->execute([(int) ($row['id'] ?? 0)]);

        json_response([
            'ok' => true,
            'notification' => [
                'title' => trim((string) ($row['title'] ?? 'CLZ Spiez')),
                'body' => trim((string) ($row['body'] ?? '')),
                'tag' => trim((string) ($row['tag'] ?? ($row['notification_key'] ?? 'clz-notification'))),
                'url' => trim((string) ($row['url'] ?? '/')) ?: '/',
            ],
        ]);
    }

    json_response(['ok' => true, 'notification' => null]);
} catch (Throwable $e) {
    json_error('Push-Info konnte nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}
