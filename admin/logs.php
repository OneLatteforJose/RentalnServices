<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_admin();
$pdo = db();

$actions = $pdo->query("SELECT DISTINCT action FROM admin_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$action = in_array($_GET['action'] ?? '', $actions, true) ? $_GET['action'] : '';

$where  = $action !== '' ? " WHERE l.action = ?" : '';
$params = $action !== '' ? [$action] : [];
$cnt = $pdo->prepare("SELECT COUNT(*) FROM admin_logs l" . $where);
$cnt->execute($params);
$pg = paginate((int)$cnt->fetchColumn(), 50);

$st = $pdo->prepare("SELECT l.id, l.created_at, a.full_name AS admin, l.action, l.target_type, l.target_id, l.details
                     FROM admin_logs l LEFT JOIN users a ON a.id = l.admin_id" . $where
                    . " ORDER BY l.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}");
$st->execute($params);
$rows = $st->fetchAll();

$pages = ['user' => 'account.php', 'area' => 'area.php', 'booking' => 'booking.php', 'request' => 'request.php'];
foreach ($rows as &$r) {
    $r['target'] = $r['target_type'] && $r['target_id'] ? $r['target_type'] . ' #' . $r['target_id'] : '';
    $r['_href'] = isset($pages[$r['target_type']]) && $r['target_id'] ? $pages[$r['target_type']] . '?id=' . $r['target_id'] : null;
}
unset($r);

page_header('Admin log', 'logs.php');
echo '<p class="muted">Actions taken by admins, newest first. Deleted records stay in the log but their links may no longer open.</p>';
?>
<form method="get" class="box" style="grid-template-columns:minmax(200px,1fr) auto">
  <label>Action
    <select name="action">
      <option value="">All actions</option>
      <?php foreach ($actions as $a): ?>
        <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>&nbsp;<button>Filter</button></label>
</form>
<?php
render_table(
    ['created_at' => 'When', 'admin' => 'Admin', 'action' => 'Action', 'target' => 'Target', 'details' => 'Details'],
    $rows,
    ['target' => fn($r) => $r['_href']]
);
render_pager($pg);
page_footer();
