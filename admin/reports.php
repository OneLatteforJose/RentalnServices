<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/reports.php';
require_admin();
$pdo = db();
$f = report_filters();
$range = [$f['start'], $f['end']];
$hasCity = $f['city'] !== '';
[$cond, $cityP] = city_filter($f['city'], 'a');

$run = function (string $sql, array $params) use ($pdo): PDOStatement {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
};

// The areas join is only added when a city is chosen, so the unfiltered report stays a plain indexed range scan.
$bookJoin = $hasCity ? ' JOIN areas a ON a.id = b.area_id' : '';
$reqJoin  = $hasCity ? ' JOIN areas a ON a.id = r.area_id' : '';
$payJoin  = $hasCity ? ' LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN service_requests r ON r.id = p.service_request_id'
                       . ' JOIN areas a ON a.id = COALESCE(b.area_id, r.area_id)' : '';
$revJoin  = $hasCity ? ' LEFT JOIN bookings b ON b.id = rv.booking_id LEFT JOIN service_requests r ON r.id = rv.service_request_id'
                       . ' JOIN areas a ON a.id = COALESCE(b.area_id, r.area_id)' : '';
$userJoin = $hasCity ? ' JOIN areas a ON a.id = u.area_id' : '';

$bookings = $run("SELECT b.status, COUNT(*) FROM bookings b$bookJoin
                  WHERE b.created_at >= ? AND b.created_at < ?$cond GROUP BY b.status",
                 array_merge($range, $cityP))->fetchAll(PDO::FETCH_KEY_PAIR);
$requests = $run("SELECT r.status, COUNT(*) FROM service_requests r$reqJoin
                  WHERE r.created_at >= ? AND r.created_at < ?$cond GROUP BY r.status",
                 array_merge($range, $cityP))->fetchAll(PDO::FETCH_KEY_PAIR);

$payTotal = fn(string $status) => $run("SELECT COALESCE(SUM(p.amount), 0) FROM payments p$payJoin
                                         WHERE p.status = ? AND p.paid_at >= ? AND p.paid_at < ?$cond",
                                        array_merge([$status], $range, $cityP))->fetchColumn();
$paid     = $payTotal('paid');
$refunded = $payTotal('refunded');

$rating = $run("SELECT ROUND(AVG(rv.rating), 2) AS avg, COUNT(*) AS c FROM reviews rv$revJoin
                WHERE rv.created_at >= ? AND rv.created_at < ?$cond",
               array_merge($range, $cityP))->fetch();
$newAccounts = $run("SELECT u.role, COUNT(*) FROM users u$userJoin
                     WHERE u.role <> 'admin' AND u.created_at >= ? AND u.created_at < ?$cond GROUP BY u.role",
                    array_merge($range, $cityP))->fetchAll(PDO::FETCH_KEY_PAIR);

$statusRows = [];
foreach (['pending', 'approved', 'rejected', 'cancelled', 'completed'] as $s) {
    $statusRows[] = ['status' => ucfirst($s), 'bookings' => (int)($bookings[$s] ?? 0), 'requests' => (int)($requests[$s] ?? 0)];
}

// By city: three grouped scans over the date range (each uses the created_at / paid_at index) merged in PHP,
// instead of three correlated subqueries per city. Only cities with activity in the range are listed.
$byCityMap = [];
$collect = function (PDOStatement $st, string $field) use (&$byCityMap) {
    foreach ($st->fetchAll() as $r) {
        $k = city_key($r['province'], $r['city_municipality']);
        $byCityMap[$k] ??= ['city' => $r['city_municipality'] . ', ' . $r['province'], 'bookings' => 0, 'requests' => 0, 'revenue' => 0.0];
        $byCityMap[$k][$field] = $r['n'] + 0;
    }
};
$collect($run("SELECT a.province, a.city_municipality, COUNT(*) AS n FROM bookings b JOIN areas a ON a.id = b.area_id
               WHERE b.created_at >= ? AND b.created_at < ?$cond GROUP BY a.province, a.city_municipality", array_merge($range, $cityP)), 'bookings');
$collect($run("SELECT a.province, a.city_municipality, COUNT(*) AS n FROM service_requests r JOIN areas a ON a.id = r.area_id
               WHERE r.created_at >= ? AND r.created_at < ?$cond GROUP BY a.province, a.city_municipality", array_merge($range, $cityP)), 'requests');
$collect($run("SELECT a.province, a.city_municipality, SUM(p.amount) AS n FROM payments p
               LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN service_requests r ON r.id = p.service_request_id
               JOIN areas a ON a.id = COALESCE(b.area_id, r.area_id)
               WHERE p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ?$cond GROUP BY a.province, a.city_municipality", array_merge($range, $cityP)), 'revenue');
usort($byCityMap, fn($x, $y) => [$y['revenue'], $y['bookings'], $x['city']] <=> [$x['revenue'], $x['bookings'], $y['city']]);
$byCity = array_slice($byCityMap, 0, 50);
foreach ($byCity as &$row) $row['revenue'] = money($row['revenue']);
unset($row);

$topVehicles = $run(
    "SELECT v.model, v.plate_no, va.city_municipality AS city, COUNT(b.id) AS bookings, COALESCE(SUM(b.total_price), 0) AS value
     FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN areas va ON va.id = v.area_id$bookJoin
     WHERE b.created_at >= ? AND b.created_at < ? AND b.status IN ('approved', 'completed')$cond
     GROUP BY v.id, v.model, v.plate_no, va.city_municipality ORDER BY bookings DESC, value DESC LIMIT 5",
    array_merge($range, $cityP)
)->fetchAll();
foreach ($topVehicles as &$row) $row['value'] = money($row['value']);
unset($row);

$topServices = $run(
    "SELECT s.title, s.category, sa.city_municipality AS city, COUNT(r.id) AS requests, COALESCE(SUM(r.total_price), 0) AS value
     FROM service_requests r JOIN services s ON s.id = r.service_id JOIN areas sa ON sa.id = s.area_id$reqJoin
     WHERE r.created_at >= ? AND r.created_at < ? AND r.status IN ('approved', 'completed')$cond
     GROUP BY s.id, s.title, s.category, sa.city_municipality ORDER BY requests DESC, value DESC LIMIT 5",
    array_merge($range, $cityP)
)->fetchAll();
foreach ($topServices as &$row) { $row['value'] = money($row['value']); $row['category'] = ucfirst(str_replace('_', ' ', $row['category'])); }
unset($row);

$cities = city_options($pdo);
$qs = http_build_query(['from' => $f['from'], 'to' => $f['to'], 'city' => $f['city']]);

$cards = [
    ['Bookings', array_sum($bookings)],
    ['Service requests', array_sum($requests)],
    ['Payments received', '₱' . money($paid)],
    ['Refunded', '₱' . money($refunded)],
    ['Average rating', $rating['c'] ? $rating['avg'] . ' (' . $rating['c'] . ' reviews)' : 'No reviews'],
    ['New accounts', array_sum($newAccounts) . ' (' . (int)($newAccounts['user'] ?? 0) . ' users, ' . (int)($newAccounts['provider'] ?? 0) . ' providers, ' . (int)($newAccounts['manager'] ?? 0) . ' managers)'],
];

page_header('Reports', 'reports.php');
?>
<form method="get" class="box">
  <label>From <input type="date" name="from" value="<?= e($f['from']) ?>" required></label>
  <label>To <input type="date" name="to" value="<?= e($f['to']) ?>" required></label>
  <label>City
    <select name="city">
      <option value="">All cities</option>
      <?php foreach ($cities as $c): ?>
        <option value="<?= e($c['key']) ?>" <?= $f['city'] === $c['key'] ? 'selected' : '' ?>><?= e($c['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>&nbsp;<button>Apply</button></label>
</form>
<p class="muted">Bookings, requests and accounts are counted by the date they were created. Payments are counted by the date they were paid.</p>

<div class="grid">
  <?php foreach ($cards as [$label, $value]): ?>
    <div class="card"><div class="n" style="font-size:<?= strlen((string)$value) > 14 ? '1.05rem' : '1.7rem' ?>"><?= e($value) ?></div><div class="l"><?= e($label) ?></div></div>
  <?php endforeach; ?>
</div>

<h2>By status</h2>
<?php render_table(['status' => 'Status', 'bookings' => 'Bookings', 'requests' => 'Service requests'], $statusRows); ?>

<h2>By city</h2>
<p class="muted">Cities with activity in this range, top 50 by payments received.</p>
<?php render_table(['city' => 'City', 'bookings' => 'Bookings', 'requests' => 'Service requests', 'revenue' => 'Payments received'], $byCity); ?>

<h2>Most booked vehicles</h2>
<?php render_table(['model' => 'Vehicle', 'plate_no' => 'Plate', 'city' => 'City', 'bookings' => 'Bookings', 'value' => 'Booked value'], $topVehicles); ?>

<h2>Most requested services</h2>
<?php render_table(['title' => 'Service', 'category' => 'Category', 'city' => 'City', 'requests' => 'Requests', 'value' => 'Requested value'], $topServices); ?>

<h2>Download CSV</h2>
<p class="muted">Uses the dates and city selected above. Opens in Excel or Google Sheets.</p>
<div class="actions" style="display:flex;gap:.5rem;flex-wrap:wrap">
  <?php foreach (['bookings' => 'Bookings', 'requests' => 'Service requests', 'payments' => 'Payments', 'reviews' => 'Reviews', 'accounts' => 'New accounts'] as $k => $label): ?>
    <a class="tabs-link" href="export.php?type=<?= $k ?>&amp;<?= e($qs) ?>" style="padding:.45rem .9rem;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--fg);text-decoration:none"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php page_footer();
