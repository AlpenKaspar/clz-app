<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    require_user();

    $serviceId = trim((string) ($_GET['serviceId'] ?? $_POST['serviceId'] ?? ''));
    $elvantoId = trim((string) ($_GET['elvantoId'] ?? $_POST['elvantoId'] ?? ''));
    if ($serviceId === '' && str_starts_with((string) $elvantoId, 'SERVICE-')) {
        $serviceId = substr($elvantoId, 8);
    }
    if ($serviceId === '') {
        json_error('serviceId fehlt.', 400);
    }

    $stmt = db()->prepare('SELECT * FROM services WHERE service_id = ?');
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch();
    if (!$service) {
        json_response(['ok' => true, 'service' => null, 'times' => [], 'volunteers' => [], 'planItems' => []]);
    }

    $times = fetch_all_prepared('SELECT * FROM service_times WHERE service_id = ? ORDER BY starts_at', [$serviceId]);
    $volunteers = fetch_all_prepared('SELECT * FROM service_volunteers WHERE service_id = ? ORDER BY team, role, display_name', [$serviceId]);
    $planItems = fetch_all_prepared('SELECT * FROM service_plan_items WHERE service_id = ? ORDER BY item_order', [$serviceId]);

    json_response([
        'ok' => true,
        'service' => $service,
        'times' => $times,
        'volunteers' => $volunteers,
        'planItems' => $planItems,
    ]);
} catch (Throwable $e) {
    json_error('Service-Details konnten nicht geladen werden.', 500, [
        'detail' => env('APP_DEBUG', '0') === '1' ? $e->getMessage() : null,
    ]);
}

function fetch_all_prepared(string $sql, array $params): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
