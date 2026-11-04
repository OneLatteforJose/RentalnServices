<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT r.*, u.full_name AS customer, u.email AS customer_email, u.phone AS customer_phone, u.street AS customer_street,
            ua.city_municipality AS customer_city, ua.barangay AS customer_barangay,
            s.category, s.title, s.rate, p.id AS provider_id, p.full_name AS provider, p.phone AS provider_phone,
            ra.province AS service_province, ra.city_municipality AS service_city, ra.barangay AS service_barangay, m.full_name AS reviewer
     FROM service_requests r
     JOIN users u ON u.id = r.user_id
     LEFT JOIN areas ua ON ua.id = u.area_id
     JOIN services s ON s.id = r.service_id
     JOIN users p ON p.id = s.provider_id
     JOIN areas ra ON ra.id = r.area_id
     LEFT JOIN users m ON m.id = r.reviewed_by
     WHERE r.id = ?"
);
$st->execute([$id]);
$r = $st->fetch();
if (!$r) {
    flash('Service request not found.');
    header('Location: activity.php?tab=requests');
    exit;
}

$pay = $pdo->prepare("SELECT id, amount, method, reference_no, status, paid_at FROM payments WHERE service_request_id = ? ORDER BY id");
$pay->execute([$id]);
$payments = $pay->fetchAll();
$rev = $pdo->prepare("SELECT rv.rating, rv.comment, rv.created_at, u.full_name FROM reviews rv JOIN users u ON u.id = rv.user_id WHERE rv.service_request_id = ?");
$rev->execute([$id]);
$review = $rev->fetch();

$place = 'area_place';

page_header('Service request #' . $id, 'activity.php');
?>
<p><a href="activity.php?tab=requests">&larr; Back to activity</a></p>
<div class="grid">
  <div class="card"><div class="n" style="font-size:1.2rem"><?= e(ucfirst($r['status'])) ?></div><div class="l">Status</div></div>
  <div class="card"><div class="n"><?= money($r['total_price']) ?></div><div class="l">Total price</div></div>
  <div class="card"><div class="n" style="font-size:1.05rem"><?= e(substr($r['created_at'], 0, 16)) ?></div><div class="l">Submitted</div></div>
</div>

<h2>Customer</h2>
<?php render_kv([
    'Name'    => account_link((int)$r['user_id'], $r['customer']),
    'Email'   => $r['customer_email'],
    'Phone'   => $r['customer_phone'],
    'Home address' => $place($r['customer_barangay'], $r['customer_city'], $r['customer_street']),
]); ?>

<h2>Service</h2>
<?php render_kv([
    'Service'  => $r['title'] . ' (' . ucfirst(str_replace('_', ' ', $r['category'])) . ')',
    'Rate'     => money($r['rate']),
    'Provider' => account_link((int)$r['provider_id'], $r['provider']),
    'Provider phone' => $r['provider_phone'],
    'Scheduled' => $r['scheduled_at'],
    'Service location' => $place($r['service_barangay'], $r['service_city'], $r['service_street']),
    'Approving city' => $r['service_city'] . ', ' . $r['service_province'],
    'Reviewed by (manager)' => $r['reviewer'],
]); ?>

<h2>Payments</h2>
<?php render_table(['id' => '#', 'amount' => 'Amount', 'method' => 'Method', 'reference_no' => 'Reference', 'status' => 'Status', 'paid_at' => 'Paid at'], $payments); ?>

<h2>Review</h2>
<?php if ($review): ?>
  <?php render_kv(['Rating' => $review['rating'] . ' / 5', 'Comment' => $review['comment'], 'By' => $review['full_name'], 'Date' => $review['created_at']]); ?>
<?php else: ?>
  <p class="muted">No review yet.</p>
<?php endif;
page_footer();
