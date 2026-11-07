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
$rating = $pdo->query("SELECT ROUND(AVG(rating),1) AS avg, COUNT(*) AS c FROM reviews")->fetch();

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
$statuses = ['pending', 'approved', 'completed', 'rejected', 'cancelled'];

// Recent activity: the newest few rows of each kind, merged. Only the picked kind is queried when a filter is chosen.
$feed = $_GET['feed'] ?? 'all';
if (!in_array($feed, ['all', 'bookings', 'requests', 'customers'], true)) $feed = 'all';
$parts = [];
if ($feed === 'all' || $feed === 'bookings') {
    $parts[] = "(SELECT 'booking' AS kind, b.id, TIMESTAMPDIFF(SECOND, b.created_at, NOW()) AS age, u.full_name AS who, v.model AS what
                 FROM bookings b JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id ORDER BY b.id DESC LIMIT 8)";
}
if ($feed === 'all' || $feed === 'requests') {
    $parts[] = "(SELECT 'request' AS kind, r.id, TIMESTAMPDIFF(SECOND, r.created_at, NOW()) AS age, u.full_name AS who, s.title AS what
                 FROM service_requests r JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id ORDER BY r.id DESC LIMIT 8)";
}
if ($feed === 'all' || $feed === 'customers') {
    $parts[] = "(SELECT 'customer' AS kind, u.id, TIMESTAMPDIFF(SECOND, u.created_at, NOW()) AS age, u.full_name AS who, '' AS what FROM users u WHERE u.role = 'user' ORDER BY u.id DESC LIMIT 8)";
}
$events = $pdo->query(implode(' UNION ALL ', $parts) . " ORDER BY age ASC LIMIT 8")->fetchAll();
$kinds = [
    'booking'  => ['calendar', 'New booking',         fn($r) => $r['who'] . ' booked ' . $r['what'],            fn($r) => 'booking.php?id=' . $r['id']],
    'request'  => ['wrench',   'New service request', fn($r) => $r['who'] . ' requested ' . $r['what'],         fn($r) => 'request.php?id=' . $r['id']],
    'customer' => ['user-plus', 'New customer',       fn($r) => $r['who'] . ' created an account',              fn($r) => 'account.php?id=' . $r['id']],
];

page_header('Dashboard', 'dashboard.php');

if ($noManager) {
    echo '<p class="err">These cities have no active manager, so nobody can approve bookings or requests there: '
       . e(implode(', ', array_map(fn($c) => $c['name'], array_slice($noManager, 0, 10))))
       . (count($noManager) > 10 ? ' and ' . (count($noManager) - 10) . ' more' : '') . '. <a href="accounts.php">Add a manager</a></p>';
}

$m = $roles['manager'] ?? ['total' => 0, 'active' => 0];
$p = $roles['provider'] ?? ['total' => 0, 'active' => 0];
$pb = $cnt($book, 'pending');
$pr = $cnt($req, 'pending');
echo '<div class="stats">'
   . stat_card('user',      (int)$m['active'], 'Active managers',  'of ' . (int)$m['total'] . ' managers', false, 'accounts.php?role=manager')
   . stat_card('briefcase', (int)$p['active'], 'Active providers', 'of ' . (int)$p['total'] . ' providers', false, 'accounts.php?role=provider')
   . stat_card('users',     (int)($roles['user']['total'] ?? 0), 'Overall users', 'customer accounts', false, 'accounts.php?role=user')
   . stat_card('car',       (int)$veh['available'], 'Vehicles available', 'of ' . (int)$veh['total'] . ' vehicles', false, 'catalog.php?tab=vehicles')
   . stat_card('wrench',    (int)$svc['available'], 'Services available', 'of ' . (int)$svc['total'] . ' services', false, 'catalog.php?tab=services')
   . stat_card('calendar',  $pb + $pr, 'Pending approval', $pb . ' bookings, ' . $pr . ' requests', true, 'activity.php?tab=' . ($pb === 0 && $pr > 0 ? 'requests' : 'bookings') . '&status=pending')
   . '</div>';
?>
<div class="two">
  <section class="panel">
    <h3>Summary</h3>
    <div class="rows">
      <div class="row"><span>Total bookings</span><b><?= number_format(array_sum($book)) ?></b></div>
      <div class="row"><span>Service requests</span><b><?= number_format(array_sum($req)) ?></b></div>
      <div class="row"><span>Payments received</span><b><?= e(peso($paid)) ?></b></div>
      <div class="row"><span>Average rating</span><b><?= $rating['c'] ? e($rating['avg'] . ' (' . $rating['c'] . ' reviews)') : 'No reviews yet' ?></b></div>
    </div>
  </section>
  <section class="panel">
    <h3>By status</h3>
    <table class="mini">
      <thead><tr><th>Status</th><th>Bookings</th><th>Service requests</th></tr></thead>
      <tbody>
      <?php foreach ($statuses as $s): ?>
        <tr>
          <td><?= e(ucfirst($s)) ?></td>
          <td><a href="activity.php?tab=bookings&amp;status=<?= $s ?>"><?= $cnt($book, $s) ?></a></td>
          <td><a href="activity.php?tab=requests&amp;status=<?= $s ?>"><?= $cnt($req, $s) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>

<section class="panel">
  <div class="panel-head">
    <h3>Recent activity</h3>
    <form method="get">
      <select name="feed" aria-label="Filter activity" onchange="this.form.submit()">
        <?php foreach (['all' => 'All activity', 'bookings' => 'Bookings', 'requests' => 'Service requests', 'customers' => 'New customers'] as $k => $label): ?>
          <option value="<?= $k ?>" <?= $feed === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button>Go</button></noscript>
      <a class="viewall" href="activity.php">View all &rarr;</a>
    </form>
  </div>
  <div class="feed">
  <?php if (!$events): ?>
    <p class="muted">Nothing to show yet.</p>
  <?php endif; ?>
  <?php foreach ($events as $ev): [$ic, $title, $text, $href] = $kinds[$ev['kind']]; ?>
    <a class="item" href="<?= e($href($ev)) ?>">
      <span class="ic"><?= icon($ic) ?></span>
      <span><span class="t"><?= e($title) ?></span><br><span class="d"><?= e($text($ev)) ?></span></span>
      <time><?= e(time_ago((int)$ev['age'])) ?></time>
    </a>
  <?php endforeach; ?>
  </div>
</section>
<?php
page_footer();
