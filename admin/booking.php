<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT b.*, u.full_name AS customer, u.email AS customer_email, u.phone AS customer_phone, u.street AS customer_street,
            ua.city_municipality AS customer_city, ua.barangay AS customer_barangay,
            v.type, v.model, v.plate_no, v.rate_per_day, p.id AS provider_id, p.full_name AS provider, p.phone AS provider_phone,
            va.city_municipality AS vehicle_city, va.barangay AS vehicle_barangay,
            ba.province AS booking_province, ba.city_municipality AS booking_city, ba.barangay AS booking_barangay,
            ds.title AS driver_title, dp.full_name AS driver_provider, m.full_name AS reviewer
     FROM bookings b
     JOIN users u ON u.id = b.user_id
     LEFT JOIN areas ua ON ua.id = u.area_id
     JOIN vehicles v ON v.id = b.vehicle_id
     JOIN users p ON p.id = v.provider_id
     JOIN areas va ON va.id = v.area_id
     JOIN areas ba ON ba.id = b.area_id
     LEFT JOIN services ds ON ds.id = b.driver_service_id
     LEFT JOIN users dp ON dp.id = ds.provider_id
     LEFT JOIN users m ON m.id = b.reviewed_by
     WHERE b.id = ?"
);
$st->execute([$id]);
$b = $st->fetch();
if (!$b) {
    flash('Booking not found.');
    header('Location: activity.php');
    exit;
}

$pay = $pdo->prepare("SELECT id, amount, method, reference_no, status, paid_at FROM payments WHERE booking_id = ? ORDER BY id");
$pay->execute([$id]);
$payments = $pay->fetchAll();
$rev = $pdo->prepare("SELECT rv.rating, rv.comment, rv.created_at, u.full_name FROM reviews rv JOIN users u ON u.id = rv.user_id WHERE rv.booking_id = ?");
$rev->execute([$id]);
$review = $rev->fetch();

$place = 'area_place';

page_header('Booking #' . $id, 'activity.php');
?>
<p><a href="activity.php?tab=bookings">&larr; Back to activity</a></p>
<div class="grid">
  <div class="card"><div class="n" style="font-size:1.2rem"><?= e(ucfirst($b['status'])) ?></div><div class="l">Status</div></div>
  <div class="card"><div class="n"><?= money($b['total_price']) ?></div><div class="l">Total price</div></div>
  <div class="card"><div class="n" style="font-size:1.05rem"><?= e(substr($b['created_at'], 0, 16)) ?></div><div class="l">Submitted</div></div>
</div>

<h2>Customer</h2>
<?php render_kv([
    'Name'    => account_link((int)$b['user_id'], $b['customer']),
    'Email'   => $b['customer_email'],
    'Phone'   => $b['customer_phone'],
    'Address' => $place($b['customer_barangay'], $b['customer_city'], $b['customer_street']),
]); ?>

<h2>Vehicle</h2>
<?php render_kv([
    'Vehicle'      => ucfirst($b['type']) . ': ' . $b['model'] . ' (' . $b['plate_no'] . ')',
    'Rate per day' => money($b['rate_per_day']),
    'Provider'     => account_link((int)$b['provider_id'], $b['provider']),
    'Provider phone' => $b['provider_phone'],
    'Based in' => $place($b['vehicle_barangay'], $b['vehicle_city']),
]); ?>

<h2>Trip</h2>
<?php render_kv([
    'Start'  => $b['start_at'],
    'End'    => $b['end_at'],
    'Pickup' => $place($b['booking_barangay'], $b['booking_city'], $b['pickup_street']),
    'Approving city' => $b['booking_city'] . ', ' . $b['booking_province'],
    'Driver' => $b['with_driver'] ? ($b['driver_title'] ? $b['driver_provider'] . ' (' . $b['driver_title'] . ')' : 'Requested, not assigned yet') : 'Drive yourself',
    'Reviewed by (manager)' => $b['reviewer'],
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
