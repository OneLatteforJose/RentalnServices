<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_admin();
$pdo = db();

$feeds  = ['all' => 'All activity', 'booking' => 'Bookings', 'request' => 'Service requests', 'customer' => 'New customers'];
$feedBy = array_key_exists($_GET['feed'] ?? '', $feeds) ? $_GET['feed'] : 'all';

// ---------- the figures ----------
$roles = $pdo->query("SELECT role, COUNT(*) AS total, SUM(is_active) AS active FROM users GROUP BY role")->fetchAll(PDO::FETCH_UNIQUE);
$veh   = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'available'), 0) AS available FROM vehicles")->fetch();
$svc   = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'available'), 0) AS available FROM services")->fetch();
$book  = $pdo->query("SELECT status, COUNT(*) FROM bookings GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$req   = $pdo->query("SELECT status, COUNT(*) FROM service_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$paid  = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'")->fetchColumn();
$rate  = $pdo->query("SELECT ROUND(AVG(rating), 1) AS avg, COUNT(*) AS c FROM reviews")->fetch();

$n   = fn($v): int => (int)$v;
$cnt = fn(array $a, string $k): int => (int)($a[$k] ?? 0);
$pendingB = $cnt($book, 'pending');
$pendingR = $cnt($req, 'pending');
$pending  = $pendingB + $pendingR;

// What the admin sees first: [icon, label, number, small note, link, needs attention?]
$stats = [
    ['user',      'Active managers',  $n($roles['manager']['active'] ?? 0),  'of ' . $n($roles['manager']['total'] ?? 0) . ' managers',   'accounts.php?role=manager',  false],
    ['briefcase', 'Active providers', $n($roles['provider']['active'] ?? 0), 'of ' . $n($roles['provider']['total'] ?? 0) . ' providers', 'accounts.php?role=provider', false],
    ['users',     'Overall users',    $n($roles['user']['total'] ?? 0),      'customer accounts',                                          'accounts.php?role=user',     false],
    ['car',       'Vehicles available', $n($veh['available']),               'of ' . $n($veh['total']) . ' vehicles',                      'catalog.php',                false],
    ['wrench',    'Services available', $n($svc['available']),               'of ' . $n($svc['total']) . ' services',                      'catalog.php?tab=services',   false],
    ['calendar',  'Pending approval', $pending,
        $pendingB . ($pendingB === 1 ? ' booking' : ' bookings') . ', ' . $pendingR . ($pendingR === 1 ? ' request' : ' requests'),
        $pendingB || !$pendingR ? 'activity.php?tab=bookings&status=pending' : 'activity.php?tab=requests&status=pending', $pending > 0],
];

$summary = [
    ['Total bookings',    (string)array_sum($book), 'activity.php?tab=bookings'],
    ['Service requests',  (string)array_sum($req),  'activity.php?tab=requests'],
    ['Payments received', "\u{20B1}" . number_format($paid, $paid == floor($paid) ? 0 : 2), 'activity.php?tab=payments'],
    ['Average rating',    $rate['c'] ? $rate['avg'] . ' (' . $rate['c'] . ($rate['c'] == 1 ? ' review)' : ' reviews)') : 'No reviews yet', 'activity.php?tab=reviews'],
];

$statusRows = [];
foreach (['pending', 'approved', 'completed', 'rejected', 'cancelled'] as $s) {
    $statusRows[] = ['key' => $s, 'status' => ucfirst($s), 'bookings' => $cnt($book, $s), 'requests' => $cnt($req, $s)];
}

// ---------- recent activity (newest first) ----------
$parts = [];
if ($feedBy === 'all' || $feedBy === 'booking') {
    $parts[] = "(SELECT 'booking' AS kind, b.id AS ref, CONCAT(u.full_name, ' booked ', v.model) AS detail, b.created_at
                 FROM bookings b JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id ORDER BY b.created_at DESC LIMIT 8)";
}
if ($feedBy === 'all' || $feedBy === 'request') {
    $parts[] = "(SELECT 'request' AS kind, r.id AS ref, CONCAT(u.full_name, ' requested ', s.title) AS detail, r.created_at
                 FROM service_requests r JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id ORDER BY r.created_at DESC LIMIT 8)";
}
if ($feedBy === 'all' || $feedBy === 'customer') {
    $parts[] = "(SELECT 'customer' AS kind, u.id AS ref, CONCAT(u.full_name, ' created an account') AS detail, u.created_at
                 FROM users u WHERE u.role = 'user' ORDER BY u.created_at DESC LIMIT 8)";
}
$events = $pdo->query(
    "SELECT x.*, TIMESTAMPDIFF(SECOND, x.created_at, NOW()) AS age FROM (" . implode(' UNION ALL ', $parts) . ") x ORDER BY x.created_at DESC LIMIT 8"
)->fetchAll();
$ago = function (int $s): string {
    if ($s < 60) return 'just now';
    if ($s < 3600) return intdiv($s, 60) . 'm ago';
    if ($s < 86400) return intdiv($s, 3600) . 'h ago';
    if ($s < 86400 * 30) return intdiv($s, 86400) . 'd ago';
    return intdiv($s, 86400 * 30) . 'mo ago';
};
$kinds = [
    'booking'  => ['New booking', 'calendar', 'booking.php?id='],
    'request'  => ['New service request', 'wrench', 'request.php?id='],
    'customer' => ['New customer', 'userplus', 'account.php?id='],
];

page_header('Dashboard', 'dashboard.php', ['welcome' => true]);
?>
<div class="stats">
  <?php foreach ($stats as [$ic, $label, $num, $note, $href, $warn]): ?>
    <a class="stat<?= $warn ? ' warn' : '' ?>" href="<?= e($href) ?>">
      <span class="ico"><?= icon($ic) ?></span>
      <span><span class="num"><?= number_format($num) ?></span><span class="lbl"><?= e($label) ?></span><span class="note"><?= e($note) ?></span></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="two">
  <section class="panel">
    <div class="panel-h"><h2>Summary</h2></div>
    <div class="lines">
      <?php foreach ($summary as [$label, $value, $href]): ?>
        <a href="<?= e($href) ?>"><span><?= e($label) ?></span><strong><?= e($value) ?></strong></a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel">
    <div class="panel-h"><h2>By status</h2></div>
    <?php render_table(
        ['status' => 'Status', 'bookings' => 'Bookings', 'requests' => 'Service requests'],
        $statusRows,
        [
            'bookings' => fn($r) => 'activity.php?tab=bookings&status=' . $r['key'],
            'requests' => fn($r) => 'activity.php?tab=requests&status=' . $r['key'],
        ]
    ); ?>
  </section>
</div>

<section class="panel feed">
  <div class="panel-h">
    <h2>Recent activity</h2>
    <form method="get" style="display:flex;gap:.8rem;align-items:center;margin:0">
      <select name="feed" onchange="this.form.submit()" aria-label="Filter activity">
        <?php foreach ($feeds as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= $k === $feedBy ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <a class="view" href="activity.php">View all &rarr;</a>
    </form>
  </div>
  <?php if (!$events): ?>
    <p class="muted" style="margin:.5rem 0">Nothing yet. New bookings, requests and sign-ups will show up here.</p>
  <?php else: ?>
  <ul>
    <?php foreach ($events as $ev): [$label, $ic, $href] = $kinds[$ev['kind']]; ?>
      <li><a href="<?= e($href . (int)$ev['ref']) ?>">
        <span class="fi"><?= icon($ic) ?></span>
        <span><span class="t"><?= e($label) ?></span><br><span class="d"><?= e($ev['detail']) ?></span></span>
        <span class="time"><?= e($ago((int)$ev['age'])) ?></span>
      </a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php page_footer();
