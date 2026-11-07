<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();

$tab = ($_GET['tab'] ?? '') === 'requests' ? 'requests' : 'bookings';
$statuses = ['pending', 'approved', 'rejected', 'cancelled', 'completed'];
$status = $_GET['status'] ?? 'pending';
if ($status !== 'all' && !in_array($status, $statuses, true)) {
    $status = 'pending';
}

[$cond, $params] = city_filter($m['key'], 'a');
$alias = $tab === 'bookings' ? 'b' : 'r';
$where = " WHERE 1 = 1$cond";
if ($status !== 'all') {
    $where .= " AND $alias.status = ?";
    $params[] = $status;
}
$order = $status === 'pending' ? 'ASC' : 'DESC';   // oldest first while waiting, newest first for history

if ($tab === 'bookings') {
    $base = "FROM bookings b JOIN areas a ON a.id = b.area_id";
    $join = " JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id";
    $cols = "b.id, u.full_name AS customer, CONCAT(v.model, ' (', v.plate_no, ')') AS item, a.barangay, b.start_at, b.end_at,
             IF(b.with_driver, 'Yes', 'No') AS driver, b.total_price, b.status";
    $headers = ['id' => '#', 'customer' => 'Customer', 'item' => 'Vehicle', 'barangay' => 'Pickup barangay', 'start_at' => 'Start', 'end_at' => 'End',
                'driver' => 'Driver', 'total_price' => 'Total', 'status' => 'Status'];
    $page = 'booking.php';
} else {
    $base = "FROM service_requests r JOIN areas a ON a.id = r.area_id";
    $join = " JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id";
    $cols = "r.id, u.full_name AS customer, s.title AS item, a.barangay, r.scheduled_at, r.total_price, r.status";
    $headers = ['id' => '#', 'customer' => 'Customer', 'item' => 'Service', 'barangay' => 'Barangay', 'scheduled_at' => 'Scheduled', 'total_price' => 'Total', 'status' => 'Status'];
    $page = 'request.php';
}
$pg = paginate((int)db_value($pdo, "SELECT COUNT(*) $base$where", $params), 50);
$st = $pdo->prepare("SELECT $cols $base$join$where ORDER BY $alias.id $order LIMIT {$pg['per']} OFFSET {$pg['offset']}");
$st->execute($params);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    foreach (['start_at', 'end_at', 'scheduled_at'] as $k) if (isset($r[$k])) $r[$k] = fmt_dt($r[$k]);
    $r['total_price'] = peso($r['total_price']);
    $r['status'] = ucfirst($r['status']);
}
unset($r);
[$pb, $pr] = manager_pending($pdo, $m);

manager_header('Approvals', 'approvals.php');
$badge = fn(int $n) => $n > 0 ? '<span class="count">' . $n . '</span>' : '';
echo '<div class="tabs"><a href="?tab=bookings&amp;status=' . e($status) . '" class="' . ($tab === 'bookings' ? 'on' : '') . '">Vehicle bookings' . $badge($pb) . '</a>'
   . '<a href="?tab=requests&amp;status=' . e($status) . '" class="' . ($tab === 'requests' ? 'on' : '') . '">Service requests' . $badge($pr) . '</a></div>';
echo '<div class="tabs">';
foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'completed' => 'Completed', 'all' => 'All'] as $k => $label) {
    echo '<a href="?tab=' . $tab . '&amp;status=' . $k . '" class="' . ($status === $k ? 'on' : '') . '">' . $label . '</a>';
}
echo '</div>';
render_table($headers, $rows, ['id' => fn($r) => $page . '?id=' . $r['id']]);
render_pager($pg);
page_footer();
