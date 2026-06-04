<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/web_push.php';

$dryRun = in_array('--dry-run', $argv, true);
$type = 'all';
foreach ($argv as $arg) {
    $value = trim((string) $arg);
    if (str_starts_with($value, '--type=')) {
        $type = strtolower(substr($value, 7));
    }
}
if (!in_array($type, ['all', 'service', 'onair'], true)) {
    fwrite(STDERR, "Invalid --type. Use all, service or onair.\n");
    exit(2);
}

function ensure_service_push_schema(): void
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
            tag varchar(120) NULL,
            created_at datetime NOT NULL,
            consumed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_push_pending_user_key (user_id, notification_key),
            KEY idx_push_pending_user (user_id, consumed_at, created_at),
            CONSTRAINT fk_push_pending_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function service_push_log_exists(int $userId, string $key): bool
{
    $stmt = db()->prepare('SELECT 1 FROM push_notification_log WHERE user_id = ? AND notification_key = ? LIMIT 1');
    $stmt->execute([$userId, $key]);
    return (bool) $stmt->fetchColumn();
}

function service_push_mark_sent(int $userId, string $key): void
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO push_notification_log (user_id, notification_key, sent_at)
         VALUES (?, ?, NOW())'
    );
    $stmt->execute([$userId, $key]);
}

function service_push_queue(int $userId, string $key, string $title, string $body, string $url, string $tag): void
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO push_pending_notifications (user_id, notification_key, title, body, url, tag, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$userId, $key, $title, $body, $url, $tag]);
}

function service_push_empty_to_user(int $userId, bool $dryRun, array &$errors): bool
{
    $stmt = db()->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll();
    if (!$subscriptions) {
        return false;
    }
    $ok = false;
    foreach ($subscriptions as $subscription) {
        if ($dryRun) {
            $ok = true;
            continue;
        }
        try {
            $result = web_push_send_empty($subscription);
            if ($result['ok'] ?? false) {
                $ok = true;
            } elseif (in_array((int) ($result['status'] ?? 0), [404, 410], true)) {
                $deactivate = db()->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE id = ?');
                $deactivate->execute([(int) ($subscription['id'] ?? 0)]);
            } else {
                $errors[] = 'user ' . $userId . ': HTTP ' . (int) ($result['status'] ?? 0);
            }
        } catch (Throwable $e) {
            $errors[] = 'user ' . $userId . ': ' . $e->getMessage();
        }
    }
    return $ok;
}

function service_push_window(string $lead): ?array
{
    return match ($lead) {
        'week' => ['+167 hours', '+169 hours', '1 Woche'],
        'day' => ['+23 hours', '+25 hours', '1 Tag'],
        '1h' => ['+55 minutes', '+65 minutes', '1 Stunde'],
        '5m' => ['+0 minutes', '+10 minutes', '5 Minuten'],
        default => null,
    };
}

function service_push_label(string $startsAt): string
{
    try {
        $date = new DateTimeImmutable($startsAt);
        return $date->format('d.m.Y H:i');
    } catch (Throwable) {
        return $startsAt;
    }
}

ensure_service_push_schema();

$users = db()->query(
    "SELECT u.id,
            u.email,
            COALESCE(NULLIF(u.person_id, ''), (
                SELECT p.id
                FROM people p
                WHERE LOWER(COALESCE(p.email, '')) = LOWER(COALESCE(u.email, ''))
                LIMIT 1
            )) AS effective_person_id,
            up.payload_json
     FROM users u
     INNER JOIN user_preferences up ON up.user_id = u.id AND up.preference_key = 'default'
     WHERE u.is_active = 1"
)->fetchAll();

$sent = 0;
$skipped = 0;
$errors = [];

foreach ($users as $user) {
    $userId = (int) ($user['id'] ?? 0);
    $personId = trim((string) ($user['effective_person_id'] ?? ''));
    $prefs = json_decode((string) ($user['payload_json'] ?? '{}'), true);
    if (!is_array($prefs) || $userId <= 0 || $personId === '') {
        $skipped++;
        continue;
    }

    $jobs = [];
    $serviceLead = strtolower(trim((string) ($prefs['serviceReminderLead'] ?? '')));
    if (($type === 'all' || $type === 'service') && in_array($serviceLead, ['day', 'week'], true)) {
        $jobs[] = ['kind' => 'service', 'lead' => $serviceLead];
    }
    $onAirLead = strtolower(trim((string) ($prefs['onAirReminderLead'] ?? '')));
    if (($type === 'all' || $type === 'onair') && in_array($onAirLead, ['5m', '1h'], true)) {
        $jobs[] = ['kind' => 'onair', 'lead' => $onAirLead];
    }
    if (!$jobs) {
        $skipped++;
        continue;
    }

    foreach ($jobs as $job) {
        $window = service_push_window($job['lead']);
        if (!$window) {
            continue;
        }
        [$fromSpec, $toSpec, $leadLabel] = $window;
        $from = (new DateTimeImmutable($fromSpec))->format('Y-m-d H:i:s');
        $to = (new DateTimeImmutable($toSpec))->format('Y-m-d H:i:s');
        $stmt = db()->prepare(
            "SELECT DISTINCT s.service_id, s.title, st.starts_at, sv.team, sv.role
             FROM service_volunteers sv
             INNER JOIN services s ON s.service_id = sv.service_id
             INNER JOIN service_times st ON st.service_id = s.service_id
             WHERE sv.person_id = ?
               AND st.starts_at >= ?
               AND st.starts_at < ?
             ORDER BY st.starts_at
             LIMIT 10"
        );
        $stmt->execute([$personId, $from, $to]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            continue;
        }

        foreach ($rows as $row) {
            $serviceId = trim((string) ($row['service_id'] ?? ''));
            $startsAt = trim((string) ($row['starts_at'] ?? ''));
            if ($serviceId === '' || $startsAt === '') {
                continue;
            }
            $key = $job['kind'] . '_' . $job['lead'] . '_' . $serviceId . '_' . preg_replace('/[^0-9]/', '', $startsAt);
            if (service_push_log_exists($userId, $key)) {
                continue;
            }
            $title = $job['kind'] === 'onair' ? 'Gottesdienst startet bald' : 'Dein Einsatz steht an';
            $serviceTitle = trim((string) ($row['title'] ?? 'Gottesdienst'));
            $team = trim((string) ($row['team'] ?? ''));
            $role = trim((string) ($row['role'] ?? ''));
            $parts = array_filter([$serviceTitle, service_push_label($startsAt), $team, $role]);
            $body = $leadLabel . ' vorher: ' . implode(' · ', $parts);
            $url = '/?tab=calendar&service=' . rawurlencode($serviceId);
            $tag = 'service-' . $job['kind'] . '-' . $serviceId;
            if (!$dryRun) {
                service_push_queue($userId, $key, $title, $body, $url, $tag);
            }
            if (service_push_empty_to_user($userId, $dryRun, $errors)) {
                if (!$dryRun) {
                    service_push_mark_sent($userId, $key);
                }
                $sent++;
            }
        }
    }
}

echo json_encode([
    'ok' => !$errors,
    'dryRun' => $dryRun,
    'type' => $type,
    'sentUsers' => $sent,
    'skippedUsers' => $skipped,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors ? 1 : 0);
