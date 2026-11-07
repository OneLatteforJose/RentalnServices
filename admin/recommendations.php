<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_admin();
$pdo = db();

$ready = true;
try {
    $pdo->query("SELECT 1 FROM provider_recommendations LIMIT 1");
} catch (PDOException $ex) {
    $ready = false;
}

$statuses = ['pending', 'added', 'dismissed'];
$status = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : 'pending';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $to = ['added' => 'added', 'dismiss' => 'dismissed'][$_POST['action'] ?? ''] ?? null;
    if ($to) {
        $upd = $pdo->prepare("UPDATE provider_recommendations SET status = ?, handled_by = ?, handled_at = NOW() WHERE id = ? AND status = 'pending'");
        $upd->execute([$to, $_SESSION['user']['id'], $id]);
        if ($upd->rowCount()) {
            log_action('recommendation.' . $to, 'recommendation', $id, 'Marked recommendation #' . $id . ' as ' . $to);
            flash('Recommendation marked as ' . $to . '.');
        }
    }
    header('Location: recommendations.php?status=' . urlencode($status));
    exit;
}

$rows = [];
$pg = null;
if ($ready) {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM provider_recommendations WHERE status = ?");
    $cnt->execute([$status]);
    $pg = paginate((int)$cnt->fetchColumn(), 25);
    $st = $pdo->prepare(
        "SELECT r.*, m.full_name AS manager, a.city_municipality AS city
         FROM provider_recommendations r JOIN users m ON m.id = r.manager_id LEFT JOIN areas a ON a.id = m.area_id
         WHERE r.status = ? ORDER BY r.created_at " . ($status === 'pending' ? 'ASC' : 'DESC') . " LIMIT {$pg['per']} OFFSET {$pg['offset']}"
    );
    $st->execute([$status]);
    $rows = $st->fetchAll();
}

page_header('Provider recommendations', 'partners.php');
if (!$ready) {
    echo '<p class="err">Recommendations are not set up yet. Run <code>migrations/001_provider_recommendations.sql</code> on the database.</p>';
    page_footer();
    exit;
}
echo '<p class="muted">Managers recommend people for their city. To add one, create the provider account on the Accounts page (choose the manager under "Recommended by"), then mark the recommendation as added.</p>';
echo '<div class="tabs">';
foreach ($statuses as $s) echo '<a href="?status=' . $s . '" class="' . ($status === $s ? 'on' : '') . '">' . ucfirst($s) . '</a>';
echo '</div>';
if (!$rows) {
    echo '<p class="muted">Nothing to show yet.</p>';
} else { ?>
<div class="scroll"><table>
  <thead><tr><th>Sent</th><th>Manager</th><th>Provider</th><th>Contact</th><th>Offers</th><th>Notes</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e(substr($r['created_at'], 0, 10)) ?></td>
      <td><?= e($r['manager']) ?><br><span class="muted"><?= e($r['city']) ?></span></td>
      <td><strong><?= e($r['full_name']) ?></strong><?= $r['business_name'] ? '<br><span class="muted">' . e($r['business_name']) . '</span>' : '' ?></td>
      <td><?= e(implode(' / ', array_filter([$r['phone'], $r['email']]))) ?></td>
      <td style="white-space:normal;max-width:16rem"><?= e($r['offers']) ?></td>
      <td style="white-space:normal;max-width:18rem"><?= e($r['notes']) ?></td>
      <td>
        <?php if ($r['status'] === 'pending'): ?>
          <form method="post" style="margin:0;display:flex;gap:.4rem">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button name="action" value="added" onclick="return confirm('Mark as added? Create the account first on the Accounts page.')">Mark added</button>
            <button name="action" value="dismiss" class="alt" onclick="return confirm('Dismiss this recommendation?')">Dismiss</button>
          </form>
        <?php else: echo e(ucfirst($r['status'])); endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php }
render_pager($pg);
page_footer();
