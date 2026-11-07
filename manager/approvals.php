<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$cp = city_params($m);

$tab = ($_GET['tab'] ?? '') === 'requests' ? 'requests' : 'bookings';
$statuses = ['pending', 'approved', 'completed', 'rejected', 'cancelled'];
$status = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';

if ($tab === 'bookings') {
    $from = "FROM bookings x JOIN areas ca ON ca.id = x.area_id JOIN users u ON u.id = x.user_id JOIN vehicles v ON v.id = x.vehicle_id";
    $cols = "x.id, u.full_name AS customer, CONCAT(v.model, ' (', v.plate_no, ')') AS item, x.start_at, x.end_at, IF(x.with_driver, 'Yes', 'No') AS driver, x.total_price, x.status, x.created_at";
    $headers = ['id' => '#', 'customer' => 'Customer', 'item' => 'Vehicle', 'start_at' => 'Start', 'end_at' => 'End', 'driver' => 'Driver',
                'total_price' => 'Total', 'status' => 'Status', 'created_at' => 'Submitted'];
    $page = 'booking.php';
} else {
    $from = "FROM service_requests x JOIN areas ca ON ca.id = x.area_id JOIN users u ON u.id = x.user_id JOIN services s ON s.id = x.service_id";
    $cols = "x.id, u.full_name AS customer, s.title AS item, s.category, x.scheduled_at, x.total_price, x.status, x.created_at";
    $headers = ['id' => '#', 'customer' => 'Customer', 'item' => 'Service', 'category' => 'Category', 'scheduled_at' => 'Scheduled',
                'total_price' => 'Total', 'status' => 'Status', 'created_at' => 'Submitted'];
    $page = 'request.php';
}
$where = " WHERE ca.province = ? AND ca.city_municipality = ?" . ($status !== '' ? " AND x.status = ?" : '');
$params = $status !== '' ? array_merge($cp, [$status]) : $cp;

$cnt = $pdo->prepare("SELECT COUNT(*) $from $where");
$cnt->execute($params);
$pg = paginate((int)$cnt->fetchColumn(), 25);
// pending ones oldest first (first come, first served); everything else newest first
$order = $status === 'pending' ? 'x.created_at ASC' : 'x.created_at DESC';
$st = $pdo->prepare("SELECT $cols $from $where ORDER BY $order LIMIT {$pg['per']} OFFSET {$pg['offset']}");
$st->execute($params);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    if (isset($r['category'])) $r['category'] = ucfirst(str_replace('_', ' ', $r['category']));
    $r['status'] = ucfirst($r['status']);
}
unset($r);

manager_header('Approvals', 'approvals.php', $m);
echo '<div class="tabs"><a href="?tab=bookings" class="' . ($tab === 'bookings' ? 'on' : '') . '">Vehicle bookings</a>'
   . '<a href="?tab=requests" class="' . ($tab === 'requests' ? 'on' : '') . '">Service requests</a></div>';
echo '<div class="tabs"><a href="?tab=' . $tab . '" class="' . ($status === '' ? 'on' : '') . '">All</a>';
foreach ($statuses as $s) {
    echo '<a href="?tab=' . $tab . '&amp;status=' . $s . '" class="' . ($status === $s ? 'on' : '') . '">' . ucfirst($s) . '</a>';
}
echo '</div>';
render_table($headers, $rows, ['id' => fn($r) => $page . '?id=' . $r['id']]);
render_pager($pg);
page_footer();
