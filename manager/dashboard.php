<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
[$cond, $cp] = city_filter($m['key'], 'a');

[$pb, $pr] = manager_pending($pdo, $m);
$upcoming = (int)db_value($pdo,
    "SELECT (SELECT COUNT(*) FROM bookings b JOIN areas a ON a.id = b.area_id
              WHERE b.status = 'approved' AND b.start_at >= NOW() AND b.start_at < NOW() + INTERVAL 7 DAY$cond)
          + (SELECT COUNT(*) FROM service_requests r JOIN areas a ON a.id = r.area_id
              WHERE r.status = 'approved' AND r.scheduled_at >= NOW() AND r.scheduled_at < NOW() + INTERVAL 7 DAY$cond)",
    array_merge($cp, $cp));

$stat = function (string $sql) use ($pdo, $cond, $cp): array {
    $st = $pdo->prepare($sql . $cond);
    $st->execute($cp);
    return array_map('intval', $st->fetch(PDO::FETCH_NUM));
};
[$vTotal, $vAvail] = $stat("SELECT COUNT(*), COALESCE(SUM(v.status = 'available'), 0) FROM vehicles v JOIN areas a ON a.id = v.area_id WHERE 1 = 1");
[$sTotal, $sAvail] = $stat("SELECT COUNT(*), COALESCE(SUM(s.status = 'available'), 0) FROM services s JOIN areas a ON a.id = s.area_id WHERE 1 = 1");
[$pTotal, $pActive] = $stat("SELECT COUNT(*), COALESCE(SUM(u.is_active), 0) FROM users u JOIN areas a ON a.id = u.area_id WHERE u.role = 'provider'");

$monthStart = date('Y-m-01 00:00:00');
$monthEnd   = (new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d H:i:s');
$paid = db_value($pdo,
    "SELECT COALESCE(SUM(p.amount), 0) FROM payments p
     LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN service_requests r ON r.id = p.service_request_id
     JOIN areas a ON a.id = COALESCE(b.area_id, r.area_id)
     WHERE p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ?$cond", array_merge([$monthStart, $monthEnd], $cp));

$rows = function (string $sql) use ($pdo, $cp): array {
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($cp, $cp));
    return $st->fetchAll();
};
// Oldest first: the ones that have waited longest.
$waiting = $rows(
    "(SELECT 'booking' AS kind, b.id, TIMESTAMPDIFF(SECOND, b.created_at, NOW()) AS secs, u.full_name AS who, v.model AS what
      FROM bookings b JOIN areas a ON a.id = b.area_id JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id
      WHERE b.status = 'pending'$cond ORDER BY b.id LIMIT 6)
     UNION ALL
     (SELECT 'request' AS kind, r.id, TIMESTAMPDIFF(SECOND, r.created_at, NOW()) AS secs, u.full_name AS who, s.title AS what
      FROM service_requests r JOIN areas a ON a.id = r.area_id JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id
      WHERE r.status = 'pending'$cond ORDER BY r.id LIMIT 6)
     ORDER BY secs DESC LIMIT 6");
$coming = $rows(
    "(SELECT 'booking' AS kind, b.id, b.start_at AS at, u.full_name AS who, v.model AS what
      FROM bookings b JOIN areas a ON a.id = b.area_id JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id
      WHERE b.status = 'approved' AND b.start_at >= NOW() AND b.start_at < NOW() + INTERVAL 7 DAY$cond ORDER BY b.start_at LIMIT 6)
     UNION ALL
     (SELECT 'request' AS kind, r.id, r.scheduled_at AS at, u.full_name AS who, s.title AS what
      FROM service_requests r JOIN areas a ON a.id = r.area_id JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id
      WHERE r.status = 'approved' AND r.scheduled_at >= NOW() AND r.scheduled_at < NOW() + INTERVAL 7 DAY$cond ORDER BY r.scheduled_at LIMIT 6)
     ORDER BY at LIMIT 6");

$kind = [
    'booking' => ['calendar', 'Vehicle booking',  'booking.php'],
    'request' => ['wrench',   'Service request',  'request.php'],
];
$list = function (array $items, callable $right) use ($kind): void {
    echo '<div class="feed">';
    if (!$items) echo '<p class="muted">Nothing to show yet.</p>';
    foreach ($items as $it) {
        [$ic, $label, $page] = $kind[$it['kind']];
        echo '<a class="item" href="' . $page . '?id=' . (int)$it['id'] . '"><span class="ic">' . icon($ic) . '</span>'
           . '<span><span class="t">' . e($label) . ' #' . (int)$it['id'] . '</span><br><span class="d">' . e($it['who'] . ' - ' . $it['what']) . '</span></span>'
           . '<time>' . e($right($it)) . '</time></a>';
    }
    echo '</div>';
};

manager_header('Dashboard', 'dashboard.php');
if ($pTotal === 0) {
    echo '<p class="note">No providers live in ' . e($m['city']) . ' yet. An admin creates provider accounts; once one exists you can add their vehicles and services here.</p>';
}
echo '<div class="stats">'
   . stat_card('calendar',  $pb + $pr, 'Pending approval', plural($pb, 'booking') . ', ' . plural($pr, 'request'), true, 'approvals.php?tab=' . ($pb === 0 && $pr > 0 ? 'requests' : 'bookings') . '&status=pending')
   . stat_card('calendar',  $upcoming, 'Starting in 7 days', 'approved and scheduled', false, 'approvals.php?status=approved')
   . stat_card('briefcase', $pActive, 'Active providers', 'of ' . plural($pTotal, 'provider'), false, 'providers.php')
   . stat_card('car',       $vAvail, 'Vehicles available', 'of ' . plural($vTotal, 'vehicle'), false, 'vehicles.php')
   . stat_card('wrench',    $sAvail, 'Services available', 'of ' . plural($sTotal, 'service'), false, 'services.php')
   . stat_card('chart',     peso($paid), 'Payments this month', 'received in ' . $m['city'], false)
   . '</div>';
?>
<div class="two">
  <section class="panel">
    <div class="panel-head"><h3>Waiting for approval</h3><a class="viewall" href="approvals.php">Open queue &rarr;</a></div>
    <?php $list($waiting, fn($it) => time_ago((int)$it['secs'])); ?>
  </section>
  <section class="panel">
    <div class="panel-head"><h3>Coming up this week</h3><a class="viewall" href="approvals.php?status=approved">View all &rarr;</a></div>
    <?php $list($coming, fn($it) => date('M j, g:i A', strtotime($it['at']))); ?>
  </section>
</div>
<?php
page_footer();
