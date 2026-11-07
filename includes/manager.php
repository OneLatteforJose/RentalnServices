<?php
// Helpers for the manager side. A manager covers one city: every `areas` row that shares the
// province and city_municipality of the manager's own area. Every manager query is limited to it.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/areas.php';
require_once __DIR__ . '/site.php';   // service_categories(), vehicle_types()

// The signed-in manager, or a redirect to the login page. Re-checks the database on every request,
// so a manager who was deactivated (or lost their area) is locked out immediately.
// Returns ['id', 'name', 'area_id', 'province', 'city'].
function require_manager(): array {
    static $m = null;
    if ($m !== null) {
        return $m;
    }
    if (($_SESSION['user']['role'] ?? '') === 'manager') {
        $st = db()->prepare(
            "SELECT u.id, u.full_name, u.area_id, a.province, a.city_municipality AS city
             FROM users u JOIN areas a ON a.id = u.area_id
             WHERE u.id = ? AND u.role = 'manager' AND u.is_active = 1"
        );
        $st->execute([$_SESSION['user']['id'] ?? 0]);
        if ($row = $st->fetch()) {
            return $m = ['id' => (int)$row['id'], 'name' => $row['full_name'], 'area_id' => (int)$row['area_id'],
                         'province' => $row['province'], 'city' => $row['city']];
        }
        $_SESSION = [];   // deactivated or removed
    }
    header('Location: ../login.php');
    exit;
}

// [province, city] for the placeholders of "ca.province = ? AND ca.city_municipality = ?".
function city_params(array $m): array {
    return [$m['province'], $m['city']];
}

function manager_nav(): array {
    return [
        'dashboard.php' => ['Dashboard', 'home'],
        'approvals.php' => ['Approvals', 'calendar'],
        'vehicles.php'  => ['Vehicles', 'car'],
        'services.php'  => ['Services', 'wrench'],
        'providers.php' => ['Providers', 'users'],
    ];
}

// Pending approvals in the manager's city, for the bell: [[text, link, count], ...]
function manager_attention(array $m): array {
    $items = [];
    try {
        foreach ([['bookings', 'booking', 'bookings'], ['service_requests', 'service request', 'requests']] as [$table, $noun, $tab]) {
            $st = db()->prepare("SELECT COUNT(*) FROM $table x JOIN areas ca ON ca.id = x.area_id WHERE x.status = 'pending' AND ca.province = ? AND ca.city_municipality = ?");
            $st->execute(city_params($m));
            $c = (int)$st->fetchColumn();
            if ($c) $items[] = [$c . ' ' . $noun . ($c === 1 ? '' : 's') . ' awaiting approval', "approvals.php?tab=$tab&status=pending", $c];
        }
    } catch (Throwable $e) {
    }
    return $items;
}

function manager_header(string $title, string $active, array $m, array $opts = []): void {
    page_header($title, $active, array_merge([
        'nav' => manager_nav(), 'attention' => manager_attention($m), 'label' => 'Manager',
    ], $opts));
}
