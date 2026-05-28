<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/web_push.php';

$dryRun = in_array('--dry-run', $argv, true);

function ensure_push_notification_schema(): void
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
}

function birthday_counts(DateTimeImmutable $today): array
{
    $rows = db()->query(
        "SELECT birthday
         FROM people
         WHERE birthday IS NOT NULL
           AND (status IS NULL OR status = '' OR LOWER(status) = 'active')
           AND (category_name IS NULL OR category_name = '' OR LOWER(category_name) IN ('gemeinde', 'kontakte'))"
    )->fetchAll();

    $todayCount = 0;
    $weekCount = 0;
    $year = (int) $today->format('Y');
    foreach ($rows as $row) {
        $raw = trim((string) ($row['birthday'] ?? ''));
        if ($raw === '') {
            continue;
        }
        try {
            $birth = new DateTimeImmutable($raw);
        } catch (Throwable) {
            continue;
        }
        $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $birth->format('n') . '-' . $birth->format('j'));
        if (!$candidate) {
            continue;
        }
        if ($candidate < $today) {
            $candidate = $candidate->modify('+1 year');
        }
        $days = (int) $today->diff($candidate)->format('%a');
        if ($days === 0) {
            $todayCount++;
        }
        if ($days >= 0 && $days <= 6) {
            $weekCount++;
        }
    }

    return ['today' => $todayCount, 'week' => $weekCount];
}

function user_has_notification_log(int $userId, string $key): bool
{
    $stmt = db()->prepare('SELECT 1 FROM push_notification_log WHERE user_id = ? AND notification_key = ? LIMIT 1');
    $stmt->execute([$userId, $key]);
    return (bool) $stmt->fetchColumn();
}

function mark_notification_sent(int $userId, string $key): void
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO push_notification_log (user_id, notification_key, sent_at)
         VALUES (?, ?, NOW())'
    );
    $stmt->execute([$userId, $key]);
}

ensure_push_notification_schema();

$today = new DateTimeImmutable('today');
$counts = birthday_counts($today);
$todayKey = 'birthdays_today_' . $today->format('Y-m-d');
$weekKey = 'birthdays_week_' . $today->format('o-W');

$users = db()->query(
    "SELECT u.id, u.email, up.payload_json
     FROM users u
     INNER JOIN user_preferences up ON up.user_id = u.id AND up.preference_key = 'default'
     WHERE u.is_active = 1"
)->fetchAll();

$sent = 0;
$skipped = 0;
$errors = [];

foreach ($users as $user) {
    $userId = (int) ($user['id'] ?? 0);
    $prefs = json_decode((string) ($user['payload_json'] ?? '{}'), true);
    $notifications = is_array($prefs['notifications'] ?? null) ? $prefs['notifications'] : [];

    $key = '';
    if (!empty($notifications['birthdaysToday']) && $counts['today'] > 0 && !user_has_notification_log($userId, $todayKey)) {
        $key = $todayKey;
    } elseif (!empty($notifications['birthdaysWeek']) && $counts['week'] > 0 && !user_has_notification_log($userId, $weekKey)) {
        $key = $weekKey;
    }
    if ($key === '') {
        $skipped++;
        continue;
    }

    $stmt = db()->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll();
    if (!$subscriptions) {
        $skipped++;
        continue;
    }

    $okForUser = false;
    foreach ($subscriptions as $subscription) {
        if ($dryRun) {
            $okForUser = true;
            continue;
        }
        try {
            $result = web_push_send_empty($subscription);
            if ($result['ok'] ?? false) {
                $okForUser = true;
            } elseif (in_array((int) ($result['status'] ?? 0), [404, 410], true)) {
                $deactivate = db()->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE id = ?');
                $deactivate->execute([(int) ($subscription['id'] ?? 0)]);
            } else {
                $errors[] = ($user['email'] ?? 'user') . ': HTTP ' . (int) ($result['status'] ?? 0);
            }
        } catch (Throwable $e) {
            $errors[] = ($user['email'] ?? 'user') . ': ' . $e->getMessage();
        }
    }

    if ($okForUser) {
        if (!$dryRun) {
            mark_notification_sent($userId, $key);
        }
        $sent++;
    }
}

echo json_encode([
    'ok' => !$errors,
    'dryRun' => $dryRun,
    'date' => $today->format('Y-m-d'),
    'birthdays' => $counts,
    'sentUsers' => $sent,
    'skippedUsers' => $skipped,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors ? 1 : 0);
