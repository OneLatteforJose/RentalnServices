<?php
// Shared by every page in /manager. A manager only ever sees and changes rows whose area is in their own city
// (same province + city_municipality as the barangay on their account). Every query goes through city_filter()
// so there is one rule for it, and no page trusts an id from the URL without that check.
require_once __DIR__ . '/site.php';      // auth.php (session, csrf, log_action) and service_categories()
require_once __DIR__ . '/areas.php';
require_once __DIR__ . '/layout.php';

// Re-checks the database on every request, so a deactivated manager is locked out immediately.
// Anyone else who opens a manager URL is only redirected: their own session is left alone.
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
            $row['key'] = city_key($row['province'], $row['city']);
            return $m = $row;
        }
        $_SESSION = [];   // deactivated, removed, or has no barangay
    }
    header('Location: ../login.php');
    exit;
}

function db_value(PDO $pdo, string $sql, array $params = []) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

// [pending bookings, pending service requests] in the manager's city. Two indexed COUNTs.
function manager_pending(PDO $pdo, array $m): array {
    [$cond, $cp] = city_filter($m['key'], 'a');
    return [
        (int)db_value($pdo, "SELECT COUNT(*) FROM bookings b JOIN areas a ON a.id = b.area_id WHERE b.status = 'pending'$cond", $cp),
        (int)db_value($pdo, "SELECT COUNT(*) FROM service_requests r JOIN areas a ON a.id = r.area_id WHERE r.status = 'pending'$cond", $cp),
    ];
}

function manager_header(string $title, string $active = ''): void {
    $m = require_manager();
    [$pb, $pr] = manager_pending(db(), $m);
    shell_header($title, $active, [
        'dashboard.php' => ['Dashboard', 'home'],
        'approvals.php' => ['Approvals', 'calendar'],
        'vehicles.php'  => ['Vehicles',  'car'],
        'services.php'  => ['Services',  'wrench'],
        'providers.php' => ['Providers', 'briefcase'],
    ], [
        'suffix'   => 'Manager',
        'name'     => $m['full_name'],
        'city'     => $m['city'] . ', ' . $m['province'],
        'bell'     => $pb + $pr,
        'bellHref' => 'approvals.php?tab=' . ($pb === 0 && $pr > 0 ? 'requests' : 'bookings') . '&status=pending',
    ]);
}

function fmt_dt(?string $dt): string {
    return $dt ? date('M j, Y g:i A', strtotime($dt)) : '-';
}

function area_in_my_city(PDO $pdo, array $m, int $areaId): bool {
    return (bool)db_value($pdo, "SELECT 1 FROM areas WHERE id = ? AND province = ? AND city_municipality = ?", [$areaId, $m['province'], $m['city']]);
}

// Barangays of the manager's city: [['id' => .., 'barangay' => ..], ...]
function my_barangays(PDO $pdo, array $m): array {
    $st = $pdo->prepare("SELECT id, barangay FROM areas WHERE province = ? AND city_municipality = ? ORDER BY barangay");
    $st->execute([$m['province'], $m['city']]);
    return $st->fetchAll();
}

// Active providers living in the manager's city.
function my_providers(PDO $pdo, array $m): array {
    $st = $pdo->prepare(
        "SELECT u.id, u.full_name, pp.business_name FROM users u
         JOIN areas a ON a.id = u.area_id LEFT JOIN provider_profiles pp ON pp.user_id = u.id
         WHERE u.role = 'provider' AND u.is_active = 1 AND a.province = ? AND a.city_municipality = ? ORDER BY u.full_name"
    );
    $st->execute([$m['province'], $m['city']]);
    return $st->fetchAll();
}

// Driver services offered in the manager's city (a booking "with driver" uses one of these).
function my_driver_services(PDO $pdo, array $m, bool $onlyAvailable = true): array {
    $st = $pdo->prepare(
        "SELECT s.id, s.title, s.provider_id, p.full_name AS provider FROM services s
         JOIN users p ON p.id = s.provider_id JOIN areas a ON a.id = s.area_id
         WHERE s.category = 'driver' AND a.province = ? AND a.city_municipality = ?" . ($onlyAvailable ? " AND s.status = 'available'" : '') . " ORDER BY p.full_name, s.title"
    );
    $st->execute([$m['province'], $m['city']]);
    return $st->fetchAll();
}
