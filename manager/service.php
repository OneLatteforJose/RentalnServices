<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$cp = city_params($m);
$cats = service_categories();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT s.*, p.full_name AS provider, a.barangay FROM services s JOIN areas a ON a.id = s.area_id JOIN users p ON p.id = s.provider_id
     WHERE s.id = ? AND a.province = ? AND a.city_municipality = ?"
);
$st->execute(array_merge([$id], $cp));
$s = $st->fetch();
if (!$s) {
    flash('Service not found in your city.');
    header('Location: services.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cat    = $_POST['category'] ?? '';
    $title  = trim($_POST['title'] ?? '');
    $desc   = trim($_POST['description'] ?? '') ?: null;
    $rate   = $_POST['rate'] ?? '';
    $status = $_POST['status'] ?? '';

    if (!isset($cats[$cat])) {
        $err = 'Choose a service category.';
    } elseif ($title === '' || strlen($title) > 150) {
        $err = 'Enter a service title (up to 150 characters).';
    } elseif ($desc !== null && strlen($desc) > 5000) {
        $err = 'The description is too long.';
    } elseif (!is_numeric($rate) || (float)$rate <= 0 || (float)$rate > 99999999.99) {
        $err = 'Enter a rate greater than zero.';
    } elseif (!in_array($status, ['available', 'unavailable'], true)) {
        $err = 'Choose available or unavailable.';
    } elseif ($s['category'] === 'driver' && $cat !== 'driver') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE own_driver_id = ?");
        $st->execute([$id]);
        if ($st->fetchColumn() > 0) $err = 'This driver is attached to a vehicle, so its category cannot change.';
    }
    if (!$err) {
        $pdo->prepare("UPDATE services SET category = ?, title = ?, description = ?, rate = ?, status = ? WHERE id = ?")
            ->execute([$cat, $title, $desc, round((float)$rate, 2), $status, $id]);
        log_action('service.update', 'service', $id, "Edited service $title" . ($status !== $s['status'] ? ", now $status" : ''));
        flash('Service updated.');
        header('Location: service.php?id=' . $id);
        exit;
    }
}
$f = fn(string $k) => ($_SERVER['REQUEST_METHOD'] === 'POST' && $err) ? ($_POST[$k] ?? '') : ($s[$k] ?? '');

manager_header($s['title'], 'services.php', $m);
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><a href="services.php">&larr; Back to services</a></p>
<p class="muted">Provider: <strong><?= e($s['provider']) ?></strong>, barangay <?= e($s['barangay']) ?>.</p>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <label>Category
    <select name="category" required>
      <?php foreach ($cats as $k => [$label]): ?><option value="<?= e($k) ?>" <?= $f('category') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>Title <input name="title" required maxlength="150" value="<?= e($f('title')) ?>"></label>
  <label>Rate (PHP) <input name="rate" type="number" step="0.01" min="0.01" required value="<?= e($f('rate')) ?>"></label>
  <label>Status
    <select name="status">
      <?php foreach (['available', 'unavailable'] as $o): ?><option value="<?= $o ?>" <?= $f('status') === $o ? 'selected' : '' ?>><?= ucfirst($o) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label style="grid-column:1/-1">Description <textarea name="description" rows="3"><?= e($f('description')) ?></textarea></label>
  <label>&nbsp;<button>Save changes</button></label>
</form>
<?php page_footer();
