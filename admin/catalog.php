<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();

$tab    = ($_GET['tab'] ?? '') === 'services' ? 'services' : 'vehicles';
$city   = parse_city_key($_GET['city'] ?? '') ? (string)$_GET['city'] : '';
$status = $_GET['status'] ?? '';
$allowedStatus = $tab === 'vehicles' ? ['available', 'booked', 'unavailable'] : ['available', 'unavailable'];
if (!in_array($status, $allowedStatus, true)) $status = '';

$alias = $tab === 'vehicles' ? 'v' : 's';
[$cityCond, $params] = city_filter($city, 'a');
$w = ' WHERE 1 = 1' . $cityCond;
if ($status !== '') { $w .= " AND $alias.status = ?"; $params[] = $status; }

$table = $tab === 'vehicles' ? 'vehicles v' : 'services s';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM $table JOIN areas a ON a.id = $alias.area_id" . $w);
$cnt->execute($params);
$pg = paginate((int)$cnt->fetchColumn(), 50);

if ($tab === 'vehicles') {
    $sql = "SELECT v.id, v.type, v.model, v.plate_no, v.rate_per_day, v.status, a.city_municipality AS city, a.barangay, p.full_name AS provider,
                   IF(v.own_driver_id IS NULL, 'No', 'Yes') AS own_driver,
                   (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id) AS bookings,
                   (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS rating
            FROM vehicles v JOIN areas a ON a.id = v.area_id JOIN users p ON p.id = v.provider_id" . $w . " ORDER BY v.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}";
    $headers = ['id' => '#', 'type' => 'Type', 'model' => 'Model', 'plate_no' => 'Plate', 'provider' => 'Provider', 'city' => 'City', 'barangay' => 'Barangay',
                'rate_per_day' => 'Rate / day', 'own_driver' => 'Own driver', 'status' => 'Status', 'bookings' => 'Bookings', 'rating' => 'Rating'];
} else {
    $sql = "SELECT s.id, s.category, s.title, s.rate, s.status, a.city_municipality AS city, a.barangay, p.full_name AS provider,
                   (SELECT COUNT(*) FROM service_requests r WHERE r.service_id = s.id) AS requests,
                   (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS rating
            FROM services s JOIN areas a ON a.id = s.area_id JOIN users p ON p.id = s.provider_id" . $w . " ORDER BY s.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}";
    $headers = ['id' => '#', 'category' => 'Category', 'title' => 'Service', 'provider' => 'Provider', 'city' => 'City', 'barangay' => 'Barangay',
                'rate' => 'Rate', 'status' => 'Status', 'requests' => 'Requests', 'rating' => 'Rating'];
}
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    $r['rating'] = $r['rating'] === null ? '-' : $r['rating'];
    foreach (['type', 'category', 'status'] as $k) {
        if (isset($r[$k])) $r[$k] = ucfirst(str_replace('_', ' ', $r[$k]));
    }
}
unset($r);

$cities = city_options($pdo);

page_header('Vehicles & services', 'catalog.php');
echo '<p class="muted">Vehicles and services are added by area managers. This page is for reviewing them.</p>';
echo '<div class="tabs"><a href="?tab=vehicles" class="' . ($tab === 'vehicles' ? 'on' : '') . '">Vehicles</a>'
   . '<a href="?tab=services" class="' . ($tab === 'services' ? 'on' : '') . '">Services</a></div>';
?>
<form method="get" class="box" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr)) auto">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <label>City
    <select name="city">
      <option value="">All cities</option>
      <?php foreach ($cities as $c): ?>
        <option value="<?= e($c['key']) ?>" <?= $city === $c['key'] ? 'selected' : '' ?>><?= e($c['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Status
    <select name="status">
      <option value="">All</option>
      <?php foreach ($allowedStatus as $s): ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>&nbsp;<button>Filter</button></label>
</form>
<?php
render_table($headers, $rows);
render_pager($pg);
page_footer();
