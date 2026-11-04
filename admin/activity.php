<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();

$tabs = ['bookings' => 'Bookings', 'requests' => 'Service requests', 'payments' => 'Payments', 'reviews' => 'Reviews'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'bookings';

$statuses = $tab === 'payments'
    ? ['pending', 'paid', 'failed', 'refunded']
    : ['pending', 'approved', 'rejected', 'cancelled', 'completed'];
$status = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$where  = $status !== '' ? ' WHERE %s.status = ?' : '';
$params = $status !== '' ? [$status] : [];

// Each tab: a FROM/JOIN part (shared by the COUNT and the page query), its alias for the status filter, and the page query.
// Pagination keeps every tab to one page of rows no matter how large the tables grow.
$page = ['per' => 50];
switch ($tab) {
    case 'bookings':
        $from = "FROM bookings b JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id JOIN areas a ON a.id = b.area_id
                 LEFT JOIN users m ON m.id = b.reviewed_by" . sprintf($where, 'b');
        $cols = "b.id, u.full_name AS customer, CONCAT(a.barangay, ', ', a.city_municipality) AS city, CONCAT(v.model, ' (', v.plate_no, ')') AS item,
                 b.start_at, b.end_at, IF(b.with_driver, 'Yes', 'No') AS driver, b.total_price, b.status, m.full_name AS reviewer";
        $order = 'b.id';
        $count = "SELECT COUNT(*) FROM bookings b" . sprintf($where, 'b');
        $headers = ['id' => '#', 'customer' => 'Customer', 'item' => 'Vehicle', 'city' => 'Pickup area', 'start_at' => 'Start', 'end_at' => 'End',
                    'driver' => 'Driver', 'total_price' => 'Total', 'status' => 'Status', 'reviewer' => 'Reviewed by'];
        break;
    case 'requests':
        $from = "FROM service_requests r JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id JOIN areas a ON a.id = r.area_id
                 LEFT JOIN users m ON m.id = r.reviewed_by" . sprintf($where, 'r');
        $cols = "r.id, u.full_name AS customer, CONCAT(a.barangay, ', ', a.city_municipality) AS city, s.title AS item, s.category, r.scheduled_at,
                 r.total_price, r.status, m.full_name AS reviewer";
        $order = 'r.id';
        $count = "SELECT COUNT(*) FROM service_requests r" . sprintf($where, 'r');
        $headers = ['id' => '#', 'customer' => 'Customer', 'item' => 'Service', 'category' => 'Category', 'city' => 'Service area',
                    'scheduled_at' => 'Scheduled', 'total_price' => 'Total', 'status' => 'Status', 'reviewer' => 'Reviewed by'];
        break;
    case 'payments':
        $from = "FROM payments p" . sprintf($where, 'p');
        $cols = "p.id, p.booking_id, p.service_request_id, IF(p.booking_id IS NOT NULL, CONCAT('Booking #', p.booking_id), CONCAT('Request #', p.service_request_id)) AS for_item,
                 p.amount, p.method, p.reference_no, p.status, p.paid_at";
        $order = 'p.id';
        $count = "SELECT COUNT(*) FROM payments p" . sprintf($where, 'p');
        $headers = ['id' => '#', 'for_item' => 'For', 'amount' => 'Amount', 'method' => 'Method',
                    'reference_no' => 'Reference', 'status' => 'Status', 'paid_at' => 'Paid at'];
        break;
    default: // reviews have no status filter
        $params = [];
        $from = "FROM reviews rv JOIN users u ON u.id = rv.user_id
                 LEFT JOIN bookings b ON b.id = rv.booking_id LEFT JOIN vehicles v ON v.id = b.vehicle_id
                 LEFT JOIN service_requests sr ON sr.id = rv.service_request_id LEFT JOIN services s ON s.id = sr.service_id";
        $cols = "rv.id, rv.booking_id, rv.service_request_id, u.full_name AS reviewer,
                 COALESCE(CONCAT(v.model, ' (vehicle)'), CONCAT(s.title, ' (service)')) AS item, rv.rating, rv.comment, rv.created_at";
        $order = 'rv.id';
        $count = "SELECT COUNT(*) FROM reviews rv";
        $headers = ['id' => '#', 'reviewer' => 'Reviewer', 'item' => 'Reviewed', 'rating' => 'Rating', 'comment' => 'Comment', 'created_at' => 'Date'];
}
$cnt = $pdo->prepare($count);
$cnt->execute($params);
$pg = paginate((int)$cnt->fetchColumn(), 50);
$st = $pdo->prepare("SELECT $cols $from ORDER BY $order DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}");
$st->execute($params);

page_header('Activity', 'activity.php');
echo '<div class="tabs">';
foreach ($tabs as $k => $label) {
    echo '<a href="?tab=' . $k . '" class="' . ($tab === $k ? 'on' : '') . '">' . e($label) . '</a>';
}
echo '</div>';
if ($tab !== 'reviews') {
    echo '<div class="tabs"><a href="?tab=' . $tab . '" class="' . ($status === '' ? 'on' : '') . '">All</a>';
    foreach ($statuses as $s) {
        echo '<a href="?tab=' . $tab . '&status=' . $s . '" class="' . ($status === $s ? 'on' : '') . '">' . ucfirst($s) . '</a>';
    }
    echo '</div>';
}
$links = [];
if ($tab === 'bookings') {
    $links['id'] = fn($r) => 'booking.php?id=' . $r['id'];
} elseif ($tab === 'requests') {
    $links['id'] = fn($r) => 'request.php?id=' . $r['id'];
} else {
    $target = fn($r) => $r['booking_id'] ? 'booking.php?id=' . $r['booking_id'] : ($r['service_request_id'] ? 'request.php?id=' . $r['service_request_id'] : null);
    $links[$tab === 'payments' ? 'for_item' : 'item'] = $target;
}
render_table($headers, $st->fetchAll(), $links);
render_pager($pg);
page_footer();
