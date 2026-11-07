<?php
// Landing page for a signed-in customer. Booking and service requests will be added here.
require_once __DIR__ . '/includes/site.php';

$s = $_SESSION['user'] ?? null;
if (!$s) {
    header('Location: login.php');
    exit;
}
if ($s['role'] === 'admin' || $s['role'] === 'manager') {
    header('Location: ' . ($s['role'] === 'admin' ? 'admin/dashboard.php' : 'manager/dashboard.php'));
    exit;
}

$st = db()->prepare(
    "SELECT u.full_name, u.email, u.phone, u.street, a.barangay, a.city_municipality, a.province
     FROM users u LEFT JOIN areas a ON a.id = u.area_id
     WHERE u.id = ? AND u.role = 'user' AND u.is_active = 1"
);
$st->execute([$s['id'] ?? 0]);
$me = $st->fetch();
if (!$me) {   // account removed or deactivated since signing in
    $_SESSION = [];
    header('Location: login.php');
    exit;
}
$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

public_header('My account');
?>
<section class="section">
  <div class="wrap">
    <?php if ($notice): ?><p class="callout" style="padding:.8rem 1rem;margin-bottom:1.5rem"><?= e($notice) ?></p><?php endif; ?>
    <h2>Welcome, <?= e($me['full_name']) ?></h2>
    <p class="intro">Booking vehicles and requesting services will open here soon. For now, this is your account.</p>
    <dl style="display:grid;grid-template-columns:9rem 1fr;gap:.5rem 1rem;max-width:36rem;margin:0 0 2rem">
      <dt class="muted" style="color:var(--muted)">Email</dt><dd style="margin:0"><?= e($me['email']) ?></dd>
      <dt style="color:var(--muted)">Phone</dt><dd style="margin:0"><?= e($me['phone'] ?: '-') ?></dd>
      <dt style="color:var(--muted)">Address</dt><dd style="margin:0"><?= e(implode(', ', array_filter([$me['street'], $me['barangay'], $me['city_municipality'], $me['province']]))) ?></dd>
    </dl>
    <a class="btn btn-road" href="logout.php">Log out</a>
  </div>
</section>
<?php public_footer();
