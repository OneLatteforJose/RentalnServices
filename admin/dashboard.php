<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();

$roles  = $pdo->query("SELECT role, COUNT(*) AS total, SUM(is_active) AS active FROM users GROUP BY role")->fetchAll(PDO::FETCH_UNIQUE);
$veh    = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status='available'),0) AS available FROM vehicles")->fetch();
$svc    = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status='available'),0) AS available FROM services")->fetch();
$book   = $pdo->query("SELECT status, COUNT(*) FROM bookings GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$req    = $pdo->query("SELECT status, COUNT(*) FROM service_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$paid   = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid'")->fetchColumn();
$rating = $pdo->query("SELECT ROUND(AVG(rating),2) AS avg, COUNT(*) AS c FROM reviews")->fetch();
$recent = $pdo->query(
    "SELECT b.id, u.full_name AS customer, v.model, b.total_price, b.status, b.created_at
     FROM bookings b JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id
     ORDER BY b.id DESC LIMIT 5"
)->fetchAll();

// A city is every areas row sharing province + city_municipality. Anti-join against the cities that do have an active manager.
$noManager = $pdo->query(
    "SELECT a.province, a.city_municipality AS name FROM areas a
     LEFT JOIN (SELECT DISTINCT ma.province, ma.city_municipality
                FROM users u JOIN areas ma ON ma.id = u.area_id
                WHERE u.role = 'manager' AND u.is_active = 1) m
            ON m.province = a.province AND m.city_municipality = a.city_municipality
     WHERE m.province IS NULL
     GROUP BY a.province, a.city_municipality ORDER BY a.province, a.city_municipality"
)->fetchAll();

$cnt = fn(array $a, string $k) => (int)($a[$k] ?? 0);
$pending = $cnt($book, 'pending') + $cnt($req, 'pending');

$cards = [
    ['Managers (active)',   ($roles['manager']['active'] ?? 0) . ' / ' . ($roles['manager']['total'] ?? 0)],
    ['Providers (active)',  ($roles['provider']['active'] ?? 0) . ' / ' . ($roles['provider']['total'] ?? 0)],
    ['Users',               $roles['user']['total'] ?? 0],
    ['Vehicles available',  $veh['available'] . ' / ' . $veh['total']],
    ['Services available',  $svc['available'] . ' / ' . $svc['total']],
    ['Awaiting approval',   $pending],
    ['Online payments received', number_format((float)$paid, 2)],
    ['Cities without a manager', count($noManager)],
    ['Average rating',      $rating['c'] ? $rating['avg'] . ' (' . $rating['c'] . ')' : 'No reviews'],
];

page_header('Dashboard', 'dashboard.php');
echo '<div class="grid">';
foreach ($cards as [$label, $value]) {
    echo '<div class="card"><div class="n">' . e($value) . '</div><div class="l">' . e($label) . '</div></div>';
}
echo '</div>';

if ($noManager) {
    echo '<p class="err">These cities have no active manager, so nobody can approve bookings or requests there: '
       . e(implode(', ', array_map(fn($c) => $c['name'], array_slice($noManager, 0, 10))))
       . (count($noManager) > 10 ? ' and ' . (count($noManager) - 10) . ' more' : '') . '. <a href="accounts.php">Add a manager</a></p>';
}

echo '<h2>Bookings by status</h2><div class="grid">';
foreach (['pending','approved','rejected','cancelled','completed'] as $s) {
    echo '<div class="card"><div class="n">' . $cnt($book, $s) . '</div><div class="l">' . ucfirst($s) . '</div></div>';
}
echo '</div><h2>Service requests by status</h2><div class="grid">';
foreach (['pending','approved','rejected','cancelled','completed'] as $s) {
    echo '<div class="card"><div class="n">' . $cnt($req, $s) . '</div><div class="l">' . ucfirst($s) . '</div></div>';
}
echo '</div><h2>Latest bookings</h2>';
render_table(
    ['id' => '#', 'customer' => 'Customer', 'model' => 'Vehicle', 'total_price' => 'Total', 'status' => 'Status', 'created_at' => 'Created'],
    $recent,
    ['id' => fn($r) => 'booking.php?id=' . $r['id']]
);
page_footer();
