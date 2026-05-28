<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

function birthday_push_name(array $row): string
{
    $preferred = trim((string) ($row['preferred_name'] ?? ''));
    $first = trim((string) ($row['firstname'] ?? ''));
    $last = trim((string) ($row['lastname'] ?? ''));
    $display = trim((string) ($row['display_name'] ?? ''));

    $name = trim(($preferred !== '' ? $preferred : $first) . ' ' . $last);
    return $name !== '' ? $name : $display;
}

function birthday_push_day_label(int $days, DateTimeImmutable $date): string
{
    if ($days === 0) {
        return 'heute';
    }
    if ($days === 1) {
        return 'morgen';
    }
    return $date->format('d.m.');
}

function birthday_push_items(DateTimeImmutable $today): array
{
    $rows = db()->query(
        "SELECT firstname, preferred_name, lastname, display_name, birthday
         FROM people
         WHERE birthday IS NOT NULL
           AND birthday <> ''
           AND (status IS NULL OR status = '' OR LOWER(status) = 'active')
           AND (category_name IS NULL OR category_name = '' OR LOWER(category_name) IN ('gemeinde', 'kontakte'))
         ORDER BY lastname, firstname"
    )->fetchAll();

    $year = (int) $today->format('Y');
    $items = [];
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
        if ($days < 0 || $days > 6) {
            continue;
        }

        $name = birthday_push_name($row);
        if ($name === '') {
            continue;
        }
        $items[] = [
            'name' => $name,
            'days' => $days,
            'label' => birthday_push_day_label($days, $candidate),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return ((int) $a['days'] <=> (int) $b['days'])
            ?: strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $items;
}

function birthday_push_body(array $items, bool $withDayLabel): string
{
    $shown = array_slice($items, 0, 5);
    $names = array_map(static function (array $item) use ($withDayLabel): string {
        $name = (string) ($item['name'] ?? '');
        if (!$withDayLabel) {
            return $name;
        }
        return $name . ' (' . (string) ($item['label'] ?? '') . ')';
    }, $shown);

    $remaining = count($items) - count($shown);
    if ($remaining > 0) {
        $names[] = '+' . $remaining . ' weitere';
    }
    return implode(', ', $names);
}

try {
    $user = current_user();
    if (!$user || empty($user['isAuthenticated'])) {
        json_error('Nicht angemeldet.', 401);
    }

    $stmt = db()->prepare('SELECT payload_json FROM user_preferences WHERE user_id = ? AND preference_key = ? LIMIT 1');
    $stmt->execute([(int) ($user['id'] ?? 0), 'default']);
    $prefs = json_decode((string) ($stmt->fetchColumn() ?: '{}'), true);
    $notifications = is_array($prefs['notifications'] ?? null) ? $prefs['notifications'] : [];

    $today = new DateTimeImmutable('today');
    $weekItems = birthday_push_items($today);
    $todayItems = array_values(array_filter($weekItems, static fn(array $item): bool => (int) ($item['days'] ?? -1) === 0));

    $mode = '';
    if (!empty($notifications['birthdaysToday']) && count($todayItems) > 0) {
        $mode = 'today';
    } elseif (!empty($notifications['birthdaysWeek']) && count($weekItems) > 0) {
        $mode = 'week';
    } elseif (count($todayItems) > 0) {
        $mode = 'today';
    } elseif (count($weekItems) > 0) {
        $mode = 'week';
    }

    if ($mode === 'today') {
        json_response([
            'ok' => true,
            'notification' => [
                'title' => count($todayItems) === 1 ? 'Geburtstag heute' : 'Geburtstage heute',
                'body' => birthday_push_body($todayItems, false),
                'tag' => 'clz-birthdays-today-' . $today->format('Y-m-d'),
                'url' => '/?tab=contacts&filter=birthday_today',
            ],
        ]);
    }

    if ($mode === 'week') {
        json_response([
            'ok' => true,
            'notification' => [
                'title' => 'Geburtstage diese Woche',
                'body' => birthday_push_body($weekItems, true),
                'tag' => 'clz-birthdays-week-' . $today->format('o-W'),
                'url' => '/?tab=contacts&filter=birthday_week',
            ],
        ]);
    }

    json_response([
        'ok' => true,
        'notification' => [
            'title' => 'CLZ Spiez',
            'body' => 'Keine Geburtstage gefunden.',
            'tag' => 'clz-birthdays',
            'url' => '/',
        ],
    ]);
} catch (Throwable $e) {
    json_error('Geburtstagsinfos konnten nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}
