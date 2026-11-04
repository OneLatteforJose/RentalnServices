<?php
// Shared by reports.php (summary) and export.php (CSV).
require_once __DIR__ . '/areas.php';

const EXPORT_MAX_ROWS = 50000;

function valid_date(string $d): ?string {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

// Date range + optional city from the query string. Default: the last 30 days.
// 'city' is a city key ("Province|City", see includes/areas.php) or '' for all cities.
function report_filters(): array {
    $today = new DateTimeImmutable('today');
    $from  = valid_date($_GET['from'] ?? '') ?: $today->modify('-29 days')->format('Y-m-d');
    $to    = valid_date($_GET['to'] ?? '') ?: $today->format('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $city = parse_city_key($_GET['city'] ?? '') ? (string)$_GET['city'] : '';
    return [
        'from'  => $from,
        'to'    => $to,
        'city'  => $city,
        'start' => $from . ' 00:00:00',
        'end'   => (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
    ];
}

// Returns [headers, PDOStatement] for an export type, or null for an unknown type.
// The statement is not fetched here, so export.php can stream it row by row instead of holding it all in memory.
function report_dataset(PDO $pdo, string $type, array $f): ?array {
    $range = [$f['start'], $f['end']];
    switch ($type) {
        case 'bookings':
            [$cond, $cp] = city_filter($f['city'], 'a');
            $sql = "SELECT b.id, b.created_at, u.full_name AS customer, u.email, v.type, v.model, v.plate_no,
                           a.province, a.city_municipality AS city, a.barangay, b.pickup_street,
                           b.start_at, b.end_at, IF(b.with_driver, 'Yes', 'No') AS with_driver, b.total_price, b.status, m.full_name AS reviewed_by
                    FROM bookings b JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id
                    JOIN areas a ON a.id = b.area_id LEFT JOIN users m ON m.id = b.reviewed_by
                    WHERE b.created_at >= ? AND b.created_at < ?$cond ORDER BY b.id LIMIT " . EXPORT_MAX_ROWS;
            $headers = ['id' => 'ID', 'created_at' => 'Created', 'customer' => 'Customer', 'email' => 'Email', 'type' => 'Type', 'model' => 'Model',
                        'plate_no' => 'Plate', 'province' => 'Province', 'city' => 'City', 'barangay' => 'Barangay', 'pickup_street' => 'Pickup street',
                        'start_at' => 'Start', 'end_at' => 'End', 'with_driver' => 'With driver', 'total_price' => 'Total', 'status' => 'Status',
                        'reviewed_by' => 'Reviewed by'];
            $params = array_merge($range, $cp);
            break;
        case 'requests':
            [$cond, $cp] = city_filter($f['city'], 'a');
            $sql = "SELECT r.id, r.created_at, u.full_name AS customer, u.email, s.category, s.title AS service,
                           a.province, a.city_municipality AS city, a.barangay, r.service_street,
                           r.scheduled_at, r.total_price, r.status, m.full_name AS reviewed_by
                    FROM service_requests r JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id
                    JOIN areas a ON a.id = r.area_id LEFT JOIN users m ON m.id = r.reviewed_by
                    WHERE r.created_at >= ? AND r.created_at < ?$cond ORDER BY r.id LIMIT " . EXPORT_MAX_ROWS;
            $headers = ['id' => 'ID', 'created_at' => 'Created', 'customer' => 'Customer', 'email' => 'Email', 'category' => 'Category',
                        'service' => 'Service', 'province' => 'Province', 'city' => 'City', 'barangay' => 'Barangay', 'service_street' => 'Street',
                        'scheduled_at' => 'Scheduled', 'total_price' => 'Total', 'status' => 'Status', 'reviewed_by' => 'Reviewed by'];
            $params = array_merge($range, $cp);
            break;
        case 'payments': // filtered by the date the payment was made
            [$cond, $cp] = city_filter($f['city'], 'a');
            $sql = "SELECT p.id, p.paid_at, p.amount, p.method, p.reference_no, p.status,
                           IF(p.booking_id IS NOT NULL, 'Booking', 'Service request') AS for_type,
                           COALESCE(p.booking_id, p.service_request_id) AS for_id, a.city_municipality AS city
                    FROM payments p
                    LEFT JOIN bookings b ON b.id = p.booking_id
                    LEFT JOIN service_requests r ON r.id = p.service_request_id
                    LEFT JOIN areas a ON a.id = COALESCE(b.area_id, r.area_id)
                    WHERE p.paid_at >= ? AND p.paid_at < ?$cond ORDER BY p.id LIMIT " . EXPORT_MAX_ROWS;
            $headers = ['id' => 'ID', 'paid_at' => 'Paid at', 'amount' => 'Amount', 'method' => 'Method', 'reference_no' => 'Reference',
                        'status' => 'Status', 'for_type' => 'For', 'for_id' => 'For ID', 'city' => 'City'];
            $params = array_merge($range, $cp);
            break;
        case 'reviews':
            [$cond, $cp] = city_filter($f['city'], 'a');
            $sql = "SELECT rv.id, rv.created_at, u.full_name AS reviewer, IF(rv.booking_id IS NOT NULL, 'Vehicle', 'Service') AS kind,
                           COALESCE(v.model, s.title) AS item, rv.rating, rv.comment, a.city_municipality AS city
                    FROM reviews rv JOIN users u ON u.id = rv.user_id
                    LEFT JOIN bookings b ON b.id = rv.booking_id LEFT JOIN vehicles v ON v.id = b.vehicle_id
                    LEFT JOIN service_requests r ON r.id = rv.service_request_id LEFT JOIN services s ON s.id = r.service_id
                    LEFT JOIN areas a ON a.id = COALESCE(b.area_id, r.area_id)
                    WHERE rv.created_at >= ? AND rv.created_at < ?$cond ORDER BY rv.id LIMIT " . EXPORT_MAX_ROWS;
            $headers = ['id' => 'ID', 'created_at' => 'Date', 'reviewer' => 'Reviewer', 'kind' => 'Type', 'item' => 'Reviewed',
                        'rating' => 'Rating', 'comment' => 'Comment', 'city' => 'City'];
            $params = array_merge($range, $cp);
            break;
        case 'accounts': // no passwords are ever exported
            [$cond, $cp] = city_filter($f['city'], 'a');
            $sql = "SELECT u.id, u.created_at, u.role, u.full_name, u.email, u.phone, a.province, a.city_municipality AS city, a.barangay, u.street,
                           IF(u.is_active, 'Active', 'Deactivated') AS status
                    FROM users u LEFT JOIN areas a ON a.id = u.area_id
                    WHERE u.role <> 'admin' AND u.created_at >= ? AND u.created_at < ?$cond ORDER BY u.id LIMIT " . EXPORT_MAX_ROWS;
            $headers = ['id' => 'ID', 'created_at' => 'Created', 'role' => 'Role', 'full_name' => 'Name', 'email' => 'Email', 'phone' => 'Phone',
                        'province' => 'Province', 'city' => 'City', 'barangay' => 'Barangay', 'street' => 'Street', 'status' => 'Status'];
            $params = array_merge($range, $cp);
            break;
        default:
            return null;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return [$headers, $st];
}

// Stops spreadsheet programs from running cell text that starts with = + - @ as a formula.
function csv_safe($v): string {
    $v = (string)$v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}
