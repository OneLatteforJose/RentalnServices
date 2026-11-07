<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$cp = city_params($m);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$load = function () use ($pdo, $cp, $id) {
    $st = $pdo->prepare(
        "SELECT b.*, u.full_name AS customer, u.email AS customer_email, u.phone AS customer_phone, u.street AS customer_street,
                ub.barangay AS customer_barangay, ub.city_municipality AS customer_city,
                v.type, v.model, v.plate_no, v.rate_per_day, v.status AS vehicle_status, v.own_driver_id,
                p.full_name AS provider, p.phone AS provider_phone,
                ds.title AS driver_title, dp.full_name AS driver_name
         FROM bookings b
         JOIN areas ca ON ca.id = b.area_id
         JOIN users u ON u.id = b.user_id
         LEFT JOIN areas ub ON ub.id = u.area_id
         JOIN vehicles v ON v.id = b.vehicle_id
         JOIN users p ON p.id = v.provider_id
         LEFT JOIN services ds ON ds.id = b.driver_service_id
         LEFT JOIN users dp ON dp.id = ds.provider_id
         WHERE b.id = ? AND ca.province = ? AND ca.city_municipality = ?"
    );
    $st->execute(array_merge([$id], $cp));
    return $st->fetch();
};
$b = $load();
if (!$b) {
    flash('Booking not found in your city.');
    header('Location: approvals.php');
    exit;
}

// Driver choices: available driver services in this city, plus the vehicle's own driver.
$ds = $pdo->prepare(
    "SELECT s.id, s.title, p.full_name FROM services s JOIN users p ON p.id = s.provider_id JOIN areas sa ON sa.id = s.area_id
     WHERE s.category = 'driver' AND s.status = 'available' AND ((sa.province = ? AND sa.city_municipality = ?) OR s.id = ?)
     ORDER BY (s.id = ?) DESC, p.full_name"
);
$ds->execute(array_merge($cp, [(int)$b['own_driver_id'], (int)$b['own_driver_id']]));
$drivers = $ds->fetchAll();
$validDriver = array_column($drivers, 'id');

$overlap = function (string $col, int $value) use ($pdo, $id, $b): bool {
    $st = $pdo->prepare("SELECT 1 FROM bookings WHERE $col = ? AND id <> ? AND status = 'approved' AND start_at < ? AND end_at > ? LIMIT 1");
    $st->execute([$value, $id, $b['end_at'], $b['start_at']]);
    return (bool)$st->fetchColumn();
};

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $driver = (int)($_POST['driver_service_id'] ?? 0) ?: ((int)$b['driver_service_id'] ?: null);
        try {
            $pdo->beginTransaction();
            // lock the vehicle first, so two managers/tabs cannot approve overlapping bookings together
            $pdo->prepare("SELECT id FROM vehicles WHERE id = ? FOR UPDATE")->execute([$b['vehicle_id']]);
            $cur = $pdo->prepare("SELECT status FROM bookings WHERE id = ? FOR UPDATE");
            $cur->execute([$id]);
            if ($cur->fetchColumn() !== 'pending') {
                $err = 'This booking is no longer pending.';
            } elseif ($b['vehicle_status'] === 'unavailable') {
                $err = 'This vehicle is marked unavailable. Make it available first, or reject the booking.';
            } elseif ($overlap('vehicle_id', (int)$b['vehicle_id'])) {
                $err = 'This vehicle already has an approved booking that overlaps these dates.';
            } elseif ($b['with_driver'] && (!$driver || !in_array($driver, array_map('intval', $validDriver), true))) {
                $err = 'Choose a driver for this booking.';
            } elseif ($b['with_driver'] && $overlap('driver_service_id', $driver)) {
                $err = 'That driver is already booked for these dates. Choose another driver.';
            } else {
                $pdo->prepare("UPDATE bookings SET status = 'approved', reviewed_by = ?, driver_service_id = ? WHERE id = ? AND status = 'pending'")
                    ->execute([$m['id'], $b['with_driver'] ? $driver : null, $id]);
                $pdo->commit();
                log_action('booking.approve', 'booking', $id, 'Approved booking #' . $id);
                flash('Booking approved.');
                header('Location: booking.php?id=' . $id);
                exit;
            }
            $pdo->rollBack();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'Could not approve the booking. Please try again.';
        }
    }

    if ($action === 'reject' || $action === 'complete') {
        $from = $action === 'reject' ? 'pending' : 'approved';
        $to   = $action === 'reject' ? 'rejected' : 'completed';
        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE bookings SET status = ?, reviewed_by = COALESCE(reviewed_by, ?) WHERE id = ? AND status = ?");
            $upd->execute([$to, $m['id'], $id, $from]);
            if ($upd->rowCount() === 1) {
                if ($action === 'reject') {   // the customer paid online, so a rejected booking is refunded
                    $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE booking_id = ? AND status = 'paid'")->execute([$id]);
                }
                $pdo->commit();
                log_action('booking.' . $action, 'booking', $id, ucfirst($to) . ' booking #' . $id);
                flash('Booking ' . $to . '.');
                header('Location: booking.php?id=' . $id);
                exit;
            }
            $pdo->rollBack();
            $err = 'The booking changed in the meantime. Reload and try again.';
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'Could not update the booking.';
        }
    }
    $b = $load() ?: $b;
}

$pay = $pdo->prepare("SELECT id, amount, method, reference_no, status, paid_at FROM payments WHERE booking_id = ? ORDER BY id");
$pay->execute([$id]);
$payments = $pay->fetchAll();
$rev = $pdo->prepare("SELECT rating, comment FROM reviews WHERE booking_id = ?");
$rev->execute([$id]);
$review = $rev->fetch();
$place = fn(?string $street, ?string $brgy, ?string $city) => implode(', ', array_filter([$street, $brgy, $city]));

manager_header('Booking #' . $id, 'approvals.php', $m);
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><a href="approvals.php?tab=bookings">&larr; Back to approvals</a></p>
<div class="grid">
  <div class="card"><div class="n" style="font-size:1.2rem"><?= e(ucfirst($b['status'])) ?></div><div class="l">Status</div></div>
  <div class="card"><div class="n"><?= money($b['total_price']) ?></div><div class="l">Total price</div></div>
  <div class="card"><div class="n" style="font-size:1.05rem"><?= e(substr($b['created_at'], 0, 16)) ?></div><div class="l">Submitted</div></div>
</div>

<?php if ($b['status'] === 'pending'): ?>
<h2>Decide</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <?php if ($b['with_driver']): ?>
    <label>Driver for this trip
      <select name="driver_service_id" required>
        <option value="">Choose a driver</option>
        <?php $pre = (int)($b['driver_service_id'] ?: $b['own_driver_id']); foreach ($drivers as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $pre === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['full_name'] . ' - ' . $d['title']) ?><?= (int)$d['id'] === (int)$b['own_driver_id'] ? ' (vehicle\'s own driver)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
  <div class="actions">
    <button name="action" value="approve">Approve</button>
    <button name="action" value="reject" class="danger" formnovalidate onclick="return confirm('Reject this booking? The online payment will be refunded.')">Reject</button>
  </div>
</form>
<?php elseif ($b['status'] === 'approved'): ?>
<h2>Next step</h2>
<form method="post" onsubmit="return confirm('Mark this booking as completed?')">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <button name="action" value="complete">Mark as completed</button>
</form>
<?php endif; ?>

<h2>Customer</h2>
<?php render_kv([
    'Name' => $b['customer'], 'Email' => $b['customer_email'], 'Phone' => $b['customer_phone'],
    'Address' => $place($b['customer_street'], $b['customer_barangay'], $b['customer_city']),
]); ?>

<h2>Vehicle and trip</h2>
<?php render_kv([
    'Vehicle'  => ucfirst($b['type']) . ': ' . $b['model'] . ' (' . $b['plate_no'] . ')',
    'Rate per day' => money($b['rate_per_day']),
    'Provider' => $b['provider'] . ($b['provider_phone'] ? ' (' . $b['provider_phone'] . ')' : ''),
    'Start'    => $b['start_at'], 'End' => $b['end_at'], 'Pickup' => $b['pickup_street'],
    'Driver'   => $b['with_driver'] ? ($b['driver_name'] ? $b['driver_name'] . ' (' . $b['driver_title'] . ')' : 'Requested, not assigned yet') : 'Drive yourself',
]); ?>

<h2>Payments</h2>
<?php render_table(['id' => '#', 'amount' => 'Amount', 'method' => 'Method', 'reference_no' => 'Reference', 'status' => 'Status', 'paid_at' => 'Paid at'], $payments); ?>

<?php if ($review): ?>
<h2>Review</h2>
<?php render_kv(['Rating' => $review['rating'] . ' / 5', 'Comment' => $review['comment']]); endif;
page_footer();
