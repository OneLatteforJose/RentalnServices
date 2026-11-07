<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
[$cond, $cp] = city_filter($m['key'], 'a');

// The booking, only if its area is in this manager's city. $lock = SELECT ... FOR UPDATE inside a transaction.
$load = function (bool $lock = false) use ($pdo, $id, $cond, $cp) {
    $st = $pdo->prepare(
        "SELECT b.*, v.status AS vehicle_status, v.own_driver_id
         FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN areas a ON a.id = b.area_id
         WHERE b.id = ?$cond" . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute(array_merge([$id], $cp));
    return $st->fetch();
};
if (!$load()) {
    flash('That booking was not found in your city.');
    header('Location: approvals.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $pdo->beginTransaction();
    $b = $load(true);      // locks the booking and its vehicle row, so two managers' clicks cannot both win
    $done = '';
    if (!$b) {
        $err = 'That booking was not found in your city.';
    } elseif ($action === 'approve') {
        if ($b['status'] !== 'pending') {
            $err = 'Only pending bookings can be approved.';
        } elseif ($b['vehicle_status'] === 'unavailable') {
            $err = 'This vehicle is marked unavailable. Change its status on the Vehicles page first.';
        } elseif ($clash = db_value($pdo, "SELECT id FROM bookings WHERE vehicle_id = ? AND status = 'approved' AND id <> ? AND start_at < ? AND end_at > ? LIMIT 1",
                                    [$b['vehicle_id'], $id, $b['end_at'], $b['start_at']])) {
            $err = "These dates overlap approved booking #$clash for the same vehicle.";
        } else {
            $driver = $b['driver_service_id'] ? (int)$b['driver_service_id'] : null;
            if ($b['with_driver'] && !$driver) {
                if ($b['own_driver_id']) {
                    $driver = (int)$b['own_driver_id'];   // the vehicle comes with its own driver
                } else {
                    $driver = (int)($_POST['driver_service_id'] ?? 0);
                    $ok = $driver && db_value($pdo,
                        "SELECT 1 FROM services s JOIN areas a ON a.id = s.area_id WHERE s.id = ? AND s.category = 'driver' AND s.status = 'available'$cond",
                        array_merge([$driver], $cp));
                    if (!$ok) $err = 'Choose an available driver from your city for this booking.';
                }
            }
            if (!$err) {
                $pdo->prepare("UPDATE bookings SET status = 'approved', reviewed_by = ?, driver_service_id = ? WHERE id = ?")->execute([$m['id'], $driver, $id]);
                $done = "Booking #$id approved.";
                log_action('booking.approve', 'booking', $id, "Approved booking #$id");
            }
        }
    } elseif ($action === 'reject') {
        if ($b['status'] !== 'pending') {
            $err = 'Only pending bookings can be rejected.';
        } else {
            $pdo->prepare("UPDATE bookings SET status = 'rejected', reviewed_by = ? WHERE id = ?")->execute([$m['id'], $id]);
            $note = trim($_POST['note'] ?? '');
            $done = "Booking #$id rejected.";
            log_action('booking.reject', 'booking', $id, "Rejected booking #$id" . ($note !== '' ? ': ' . $note : ''));
        }
    } elseif ($action === 'complete') {
        $st = $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ? AND status = 'approved' AND start_at <= NOW()");
        $st->execute([$id]);
        if ($st->rowCount()) {
            $done = "Booking #$id marked completed.";
            log_action('booking.complete', 'booking', $id, "Completed booking #$id");
        } else {
            $err = 'Only approved bookings that have already started can be completed.';
        }
    } else {
        $err = 'Unknown action.';
    }
    if ($done !== '') {
        $pdo->commit();
        flash($done);
        header('Location: booking.php?id=' . $id);
        exit;
    }
    $pdo->rollBack();
}

$st = $pdo->prepare(
    "SELECT b.*, u.full_name AS customer, u.email AS customer_email, u.phone AS customer_phone,
            v.type, v.model, v.plate_no, v.rate_per_day, v.status AS vehicle_status, v.own_driver_id,
            p.full_name AS provider, p.phone AS provider_phone,
            va.barangay AS vehicle_barangay, a.barangay AS pickup_barangay,
            ds.title AS driver_title, dp.full_name AS driver_provider, mg.full_name AS reviewer
     FROM bookings b
     JOIN users u ON u.id = b.user_id JOIN vehicles v ON v.id = b.vehicle_id JOIN users p ON p.id = v.provider_id
     JOIN areas va ON va.id = v.area_id JOIN areas a ON a.id = b.area_id
     LEFT JOIN services ds ON ds.id = b.driver_service_id LEFT JOIN users dp ON dp.id = ds.provider_id
     LEFT JOIN users mg ON mg.id = b.reviewed_by
     WHERE b.id = ?$cond"
);
$st->execute(array_merge([$id], $cp));
$b = $st->fetch();

$clashes = $pdo->prepare("SELECT id, start_at, end_at FROM bookings WHERE vehicle_id = ? AND status = 'approved' AND id <> ? AND start_at < ? AND end_at > ? ORDER BY start_at");
$clashes->execute([$b['vehicle_id'], $id, $b['end_at'], $b['start_at']]);
$clashes = $clashes->fetchAll();
$pay = $pdo->prepare("SELECT amount, method, reference_no, status, paid_at FROM payments WHERE booking_id = ? ORDER BY id");
$pay->execute([$id]);
$payments = $pay->fetchAll();
$days = max(1, (int)ceil((strtotime($b['end_at']) - strtotime($b['start_at'])) / 86400));
$started = strtotime($b['start_at']) <= time();
$needsDriver = $b['with_driver'] && !$b['driver_service_id'] && !$b['own_driver_id'];
$drivers = $needsDriver && $b['status'] === 'pending' ? my_driver_services($pdo, $m) : [];

manager_header('Booking #' . $id, 'approvals.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><a href="approvals.php?tab=bookings">&larr; Back to approvals</a></p>

<?php if ($b['status'] === 'pending' && $clashes): ?>
  <p class="note"><b>Date clash.</b> This vehicle already has an approved booking in these dates:
    <?php foreach ($clashes as $c): ?><a href="booking.php?id=<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?></a> (<?= e(fmt_dt($c['start_at'])) ?> to <?= e(fmt_dt($c['end_at'])) ?>) <?php endforeach; ?>
    It cannot be approved unless one of them changes.</p>
<?php endif; ?>

<?php
$driverText = $b['with_driver']
    ? ($b['driver_title'] ? $b['driver_title'] . ' (' . $b['driver_provider'] . ')' : ($b['own_driver_id'] ? "The vehicle's own driver" : 'Needed, not chosen yet'))
    : 'No driver';
render_kv([
    'Status'          => ['html' => '<span class="badge badge-' . e($b['status']) . '">' . e(ucfirst($b['status'])) . '</span>'],
    'Customer'        => $b['customer'],
    'Contact'         => trim($b['customer_email'] . ' ' . ($b['customer_phone'] ?? '')),
    'Vehicle'         => $b['model'] . ' (' . ucfirst($b['type']) . ', ' . $b['plate_no'] . ')',
    'Provider'        => $b['provider'] . ($b['provider_phone'] ? ' - ' . $b['provider_phone'] : ''),
    'Pickup'          => area_place($b['pickup_barangay'], $m['city'], $b['pickup_street']),
    'Trip'            => fmt_dt($b['start_at']) . ' to ' . fmt_dt($b['end_at']) . ' (' . $days . ' day' . ($days > 1 ? 's' : '') . ')',
    'Driver'          => $driverText,
    'Rate'            => peso($b['rate_per_day']) . ' per day',
    'Total'           => peso($b['total_price']),
    'Requested'       => fmt_dt($b['created_at']),
    'Reviewed by'     => $b['reviewer'],
]);
?>

<h2>Decision</h2>
<div class="panel">
<?php if ($b['status'] === 'pending'): ?>
  <div class="actions">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="approve">
      <?php if ($needsDriver): ?>
        <label>Driver for this trip
          <select name="driver_service_id" required>
            <option value="">Choose a driver</option>
            <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['provider'] . ' - ' . $d['title']) ?></option><?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <button <?= ($needsDriver && !$drivers) ? 'disabled' : '' ?>>Approve booking</button>
    </form>
    <form method="post" onsubmit="return confirm('Reject this booking?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reject">
      <label>Note (optional, kept in the admin log) <input name="note" maxlength="150"></label>
      <button class="danger">Reject</button>
    </form>
  </div>
  <?php if ($needsDriver && !$drivers): ?><p class="muted" style="margin-bottom:0">No available driver service in your city. Add one on the Services page first.</p><?php endif; ?>
<?php elseif ($b['status'] === 'approved'): ?>
  <?php if ($started): ?>
    <form method="post" class="actions" onsubmit="return confirm('Mark this booking as completed?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="complete">
      <button>Mark completed</button>
    </form>
  <?php else: ?>
    <p class="muted" style="margin:0">Approved. It can be marked completed once the trip has started.</p>
  <?php endif; ?>
<?php else: ?>
  <p class="muted" style="margin:0">This booking is <?= e($b['status']) ?> and can no longer be changed.</p>
<?php endif; ?>
</div>

<h2>Payments</h2>
<?php
foreach ($payments as &$p) { $p['amount'] = peso($p['amount']); $p['paid_at'] = fmt_dt($p['paid_at']); $p['status'] = ucfirst($p['status']); }
unset($p);
render_table(['amount' => 'Amount', 'method' => 'Method', 'reference_no' => 'Reference', 'status' => 'Status', 'paid_at' => 'Paid at'], $payments);
page_footer();
