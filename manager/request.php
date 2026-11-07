<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$cp = city_params($m);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$load = function () use ($pdo, $cp, $id) {
    $st = $pdo->prepare(
        "SELECT r.*, u.full_name AS customer, u.email AS customer_email, u.phone AS customer_phone,
                s.category, s.title, s.rate, s.status AS service_status, p.full_name AS provider, p.phone AS provider_phone,
                ra.barangay AS service_barangay, ra.city_municipality AS service_city
         FROM service_requests r
         JOIN areas ca ON ca.id = r.area_id
         JOIN users u ON u.id = r.user_id
         JOIN services s ON s.id = r.service_id
         JOIN users p ON p.id = s.provider_id
         LEFT JOIN areas ra ON ra.id = r.area_id
         WHERE r.id = ? AND ca.province = ? AND ca.city_municipality = ?"
    );
    $st->execute(array_merge([$id], $cp));
    return $st->fetch();
};
$r = $load();
if (!$r) {
    flash('Service request not found in your city.');
    header('Location: approvals.php?tab=requests');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $map = ['approve' => ['pending', 'approved'], 'reject' => ['pending', 'rejected'], 'complete' => ['approved', 'completed']];
    if (isset($map[$action])) {
        [$from, $to] = $map[$action];
        try {
            $pdo->beginTransaction();
            if ($action === 'approve' && $r['service_status'] !== 'available') {
                $err = 'This service is marked unavailable. Make it available first, or reject the request.';
                $pdo->rollBack();
            } else {
                $upd = $pdo->prepare("UPDATE service_requests SET status = ?, reviewed_by = COALESCE(reviewed_by, ?) WHERE id = ? AND status = ?");
                $upd->execute([$to, $m['id'], $id, $from]);
                if ($upd->rowCount() === 1) {
                    if ($action === 'reject') {   // paid online, so a rejected request is refunded
                        $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE service_request_id = ? AND status = 'paid'")->execute([$id]);
                    }
                    $pdo->commit();
                    log_action('request.' . $action, 'request', $id, ucfirst($to) . ' service request #' . $id);
                    flash('Service request ' . $to . '.');
                    header('Location: request.php?id=' . $id);
                    exit;
                }
                $pdo->rollBack();
                $err = 'The request changed in the meantime. Reload and try again.';
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'Could not update the request.';
        }
    }
    $r = $load() ?: $r;
}

$pay = $pdo->prepare("SELECT id, amount, method, reference_no, status, paid_at FROM payments WHERE service_request_id = ? ORDER BY id");
$pay->execute([$id]);
$payments = $pay->fetchAll();
$rev = $pdo->prepare("SELECT rating, comment FROM reviews WHERE service_request_id = ?");
$rev->execute([$id]);
$review = $rev->fetch();

manager_header('Service request #' . $id, 'approvals.php', $m);
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><a href="approvals.php?tab=requests">&larr; Back to approvals</a></p>
<div class="grid">
  <div class="card"><div class="n" style="font-size:1.2rem"><?= e(ucfirst($r['status'])) ?></div><div class="l">Status</div></div>
  <div class="card"><div class="n"><?= money($r['total_price']) ?></div><div class="l">Total price</div></div>
  <div class="card"><div class="n" style="font-size:1.05rem"><?= e(substr($r['created_at'], 0, 16)) ?></div><div class="l">Submitted</div></div>
</div>

<?php if ($r['status'] === 'pending'): ?>
<h2>Decide</h2>
<form method="post" class="actions">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <button name="action" value="approve">Approve</button>
  <button name="action" value="reject" class="danger" onclick="return confirm('Reject this request? The online payment will be refunded.')">Reject</button>
</form>
<?php elseif ($r['status'] === 'approved'): ?>
<h2>Next step</h2>
<form method="post" onsubmit="return confirm('Mark this request as completed?')">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <button name="action" value="complete">Mark as completed</button>
</form>
<?php endif; ?>

<h2>Customer</h2>
<?php render_kv(['Name' => $r['customer'], 'Email' => $r['customer_email'], 'Phone' => $r['customer_phone']]); ?>

<h2>Service</h2>
<?php render_kv([
    'Service'   => $r['title'] . ' (' . ucfirst(str_replace('_', ' ', $r['category'])) . ')',
    'Rate'      => money($r['rate']),
    'Provider'  => $r['provider'] . ($r['provider_phone'] ? ' (' . $r['provider_phone'] . ')' : ''),
    'Scheduled' => $r['scheduled_at'],
    'Location'  => implode(', ', array_filter([$r['service_street'], $r['service_barangay'], $r['service_city']])),
]); ?>

<h2>Payments</h2>
<?php render_table(['id' => '#', 'amount' => 'Amount', 'method' => 'Method', 'reference_no' => 'Reference', 'status' => 'Status', 'paid_at' => 'Paid at'], $payments); ?>

<?php if ($review): ?>
<h2>Review</h2>
<?php render_kv(['Rating' => $review['rating'] . ' / 5', 'Comment' => $review['comment']]); endif;
page_footer();
