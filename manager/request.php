<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
[$cond, $cp] = city_filter($m['key'], 'a');

$load = function (bool $lock = false) use ($pdo, $id, $cond, $cp) {
    $st = $pdo->prepare(
        "SELECT r.*, s.status AS service_status
         FROM service_requests r JOIN services s ON s.id = r.service_id JOIN areas a ON a.id = r.area_id
         WHERE r.id = ?$cond" . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute(array_merge([$id], $cp));
    return $st->fetch();
};
if (!$load()) {
    flash('That request was not found in your city.');
    header('Location: approvals.php?tab=requests');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $pdo->beginTransaction();
    $r = $load(true);
    $done = '';
    if (!$r) {
        $err = 'That request was not found in your city.';
    } elseif ($action === 'approve') {
        if ($r['status'] !== 'pending') {
            $err = 'Only pending requests can be approved.';
        } elseif ($r['service_status'] === 'unavailable') {
            $err = 'This service is marked unavailable. Change its status on the Services page first.';
        } else {
            $pdo->prepare("UPDATE service_requests SET status = 'approved', reviewed_by = ? WHERE id = ?")->execute([$m['id'], $id]);
            $done = "Request #$id approved.";
            log_action('request.approve', 'request', $id, "Approved service request #$id");
        }
    } elseif ($action === 'reject') {
        if ($r['status'] !== 'pending') {
            $err = 'Only pending requests can be rejected.';
        } else {
            $pdo->prepare("UPDATE service_requests SET status = 'rejected', reviewed_by = ? WHERE id = ?")->execute([$m['id'], $id]);
            $note = trim($_POST['note'] ?? '');
            $done = "Request #$id rejected.";
            log_action('request.reject', 'request', $id, "Rejected service request #$id" . ($note !== '' ? ': ' . $note : ''));
        }
    } elseif ($action === 'complete') {
        $st = $pdo->prepare("UPDATE service_requests SET status = 'completed' WHERE id = ? AND status = 'approved' AND scheduled_at <= NOW()");
        $st->execute([$id]);
        if ($st->rowCount()) {
            $done = "Request #$id marked completed.";
            log_action('request.complete', 'request', $id, "Completed service request #$id");
        } else {
            $err = 'Only approved requests whose scheduled time has passed can be completed.';
        }
    } else {
        $err = 'Unknown action.';
    }
    if ($done !== '') {
        $pdo->commit();
        flash($done);
        header('Location: request.php?id=' . $id);
        exit;
    }
    $pdo->rollBack();
}

$st = $pdo->prepare(
    "SELECT r.*, u.full_name AS customer, u.email AS customer_email, u.phone AS customer_phone,
            s.category, s.title, s.rate, s.description, s.status AS service_status,
            p.full_name AS provider, p.phone AS provider_phone, a.barangay, mg.full_name AS reviewer
     FROM service_requests r
     JOIN users u ON u.id = r.user_id JOIN services s ON s.id = r.service_id JOIN users p ON p.id = s.provider_id
     JOIN areas a ON a.id = r.area_id LEFT JOIN users mg ON mg.id = r.reviewed_by
     WHERE r.id = ?$cond"
);
$st->execute(array_merge([$id], $cp));
$r = $st->fetch();
$pay = $pdo->prepare("SELECT amount, method, reference_no, status, paid_at FROM payments WHERE service_request_id = ? ORDER BY id");
$pay->execute([$id]);
$payments = $pay->fetchAll();
$due = strtotime($r['scheduled_at']) <= time();

manager_header('Service request #' . $id, 'approvals.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><a href="approvals.php?tab=requests">&larr; Back to approvals</a></p>
<?php
render_kv([
    'Status'      => ['html' => '<span class="badge badge-' . e($r['status']) . '">' . e(ucfirst($r['status'])) . '</span>'],
    'Customer'    => $r['customer'],
    'Contact'     => trim($r['customer_email'] . ' ' . ($r['customer_phone'] ?? '')),
    'Service'     => $r['title'] . ' (' . ucfirst(str_replace('_', ' ', $r['category'])) . ')',
    'Details'     => $r['description'],
    'Provider'    => $r['provider'] . ($r['provider_phone'] ? ' - ' . $r['provider_phone'] : ''),
    'Location'    => area_place($r['barangay'], $m['city'], $r['service_street']),
    'Scheduled'   => fmt_dt($r['scheduled_at']),
    'Rate'        => peso($r['rate']),
    'Total'       => peso($r['total_price']),
    'Requested'   => fmt_dt($r['created_at']),
    'Reviewed by' => $r['reviewer'],
]);
?>
<h2>Decision</h2>
<div class="panel">
<?php if ($r['status'] === 'pending'): ?>
  <div class="actions">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="approve">
      <button>Approve request</button>
    </form>
    <form method="post" onsubmit="return confirm('Reject this request?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reject">
      <label>Note (optional, kept in the admin log) <input name="note" maxlength="150"></label>
      <button class="danger">Reject</button>
    </form>
  </div>
<?php elseif ($r['status'] === 'approved'): ?>
  <?php if ($due): ?>
    <form method="post" class="actions" onsubmit="return confirm('Mark this request as completed?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="complete">
      <button>Mark completed</button>
    </form>
  <?php else: ?>
    <p class="muted" style="margin:0">Approved. It can be marked completed once the scheduled time has passed.</p>
  <?php endif; ?>
<?php else: ?>
  <p class="muted" style="margin:0">This request is <?= e($r['status']) ?> and can no longer be changed.</p>
<?php endif; ?>
</div>
<h2>Payments</h2>
<?php
foreach ($payments as &$p) { $p['amount'] = peso($p['amount']); $p['paid_at'] = fmt_dt($p['paid_at']); $p['status'] = ucfirst($p['status']); }
unset($p);
render_table(['amount' => 'Amount', 'method' => 'Method', 'reference_no' => 'Reference', 'status' => 'Status', 'paid_at' => 'Paid at'], $payments);
page_footer();
