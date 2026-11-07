<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$cp = city_params($m);

$one = function (string $sql) use ($pdo, $cp) { $st = $pdo->prepare($sql); $st->execute($cp); return $st->fetch(); };
$pendB = (int)$one("SELECT COUNT(*) c FROM bookings x JOIN areas ca ON ca.id = x.area_id WHERE x.status = 'pending' AND ca.province = ? AND ca.city_municipality = ?")['c'];
$pendR = (int)$one("SELECT COUNT(*) c FROM service_requests x JOIN areas ca ON ca.id = x.area_id WHERE x.status = 'pending' AND ca.province = ? AND ca.city_municipality = ?")['c'];
$veh   = $one("SELECT COUNT(*) total, COALESCE(SUM(x.status = 'available'), 0) available FROM vehicles x JOIN areas ca ON ca.id = x.area_id WHERE ca.province = ? AND ca.city_municipality = ?");
$svc   = $one("SELECT COUNT(*) total, COALESCE(SUM(x.status = 'available'), 0) available FROM services x JOIN areas ca ON ca.id = x.area_id WHERE ca.province = ? AND ca.city_municipality = ?");
$prov  = $one("SELECT COUNT(*) total, COALESCE(SUM(x.is_active), 0) active FROM users x JOIN areas ca ON ca.id = x.area_id WHERE x.role = 'provider' AND ca.province = ? AND ca.city_municipality = ?");
$cust  = (int)$one("SELECT COUNT(*) c FROM users x JOIN areas ca ON ca.id = x.area_id WHERE x.role = 'user' AND ca.province = ? AND ca.city_municipality = ?")['c'];

$stats = [
    ['calendar',  'Bookings to approve', $pendB, 'vehicle bookings waiting', 'approvals.php?tab=bookings&status=pending', $pendB > 0],
    ['wrench',    'Requests to approve', $pendR, 'service requests waiting', 'approvals.php?tab=requests&status=pending', $pendR > 0],
    ['car',       'Vehicles available',  (int)$veh['available'], 'of ' . (int)$veh['total'] . ' vehicles', 'vehicles.php', false],
    ['wrench',    'Services available',  (int)$svc['available'], 'of ' . (int)$svc['total'] . ' services', 'services.php', false],
    ['briefcase', 'Active providers',    (int)$prov['active'],   'of ' . (int)$prov['total'] . ' providers', 'providers.php', false],
    ['users',     'Customers',           $cust, 'in ' . $m['city'], 'approvals.php', false],
];

// Oldest waiting first, so nobody waits longer than they have to.
$st = $pdo->prepare(
    "SELECT x.* FROM (
        (SELECT 'booking' AS kind, b.id AS ref, u.full_name AS customer, CONCAT(UPPER(LEFT(v.type, 1)), SUBSTRING(v.type, 2), ': ', v.model) AS item, b.created_at
           FROM bookings b JOIN areas ca ON ca.id = b.area_id JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id
          WHERE b.status = 'pending' AND ca.province = ? AND ca.city_municipality = ? ORDER BY b.created_at LIMIT 10)
        UNION ALL
        (SELECT 'request' AS kind, r.id AS ref, u.full_name AS customer, s.title AS item, r.created_at
           FROM service_requests r JOIN areas ca ON ca.id = r.area_id JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id
          WHERE r.status = 'pending' AND ca.province = ? AND ca.city_municipality = ? ORDER BY r.created_at LIMIT 10)
     ) x ORDER BY x.created_at LIMIT 10"
);
$st->execute(array_merge($cp, $cp));
$waiting = $st->fetchAll();
foreach ($waiting as &$w) {
    $w['type'] = $w['kind'] === 'booking' ? 'Booking' : 'Service request';
    $w['when'] = substr($w['created_at'], 0, 16);
    $w['open'] = 'Review';
}
unset($w);

manager_header('Dashboard', 'dashboard.php', $m, ['welcome' => true, 'subtitle' => $m['city'] . ', ' . $m['province']]);
?>
<div class="stats">
  <?php foreach ($stats as [$ic, $label, $num, $note, $href, $warn]): ?>
    <a class="stat<?= $warn ? ' warn' : '' ?>" href="<?= e($href) ?>">
      <span class="ico"><?= icon($ic) ?></span>
      <span><span class="num"><?= number_format($num) ?></span><span class="lbl"><?= e($label) ?></span><span class="note"><?= e($note) ?></span></span>
    </a>
  <?php endforeach; ?>
</div>

<section class="panel">
  <div class="panel-h"><h2>Waiting for your approval</h2><a class="view" href="approvals.php?status=pending">See all &rarr;</a></div>
  <?php render_table(
      ['type' => 'Type', 'customer' => 'Customer', 'item' => 'For', 'when' => 'Submitted', 'open' => ''],
      $waiting,
      ['open' => fn($r) => ($r['kind'] === 'booking' ? 'booking.php?id=' : 'request.php?id=') . $r['ref']]
  ); ?>
</section>
<?php page_footer();
