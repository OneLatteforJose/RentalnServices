<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$cp = city_params($m);
$types = vehicle_types();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$load = function () use ($pdo, $cp, $id) {
    $st = $pdo->prepare(
        "SELECT v.*, p.full_name AS provider, a.barangay FROM vehicles v JOIN areas a ON a.id = v.area_id JOIN users p ON p.id = v.provider_id
         WHERE v.id = ? AND a.province = ? AND a.city_municipality = ?"
    );
    $st->execute(array_merge([$id], $cp));
    return $st->fetch();
};
$v = $load();
if (!$v) {
    flash('Vehicle not found in your city.');
    header('Location: vehicles.php');
    exit;
}

// A vehicle's own driver is one of its provider's driver services.
$st = $pdo->prepare("SELECT id, title FROM services WHERE provider_id = ? AND category = 'driver' ORDER BY title");
$st->execute([$v['provider_id']]);
$drivers = $st->fetchAll();
$st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND status = 'approved' AND end_at >= NOW()");
$st->execute([$id]);
$upcoming = (int)$st->fetchColumn();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type     = $_POST['type'] ?? '';
    $model    = trim($_POST['model'] ?? '');
    $plate    = strtoupper(trim($_POST['plate_no'] ?? ''));
    $capacity = trim($_POST['capacity'] ?? '') ?: null;
    $rate     = $_POST['rate_per_day'] ?? '';
    $driver   = (int)($_POST['own_driver_id'] ?? 0) ?: null;
    $status   = $_POST['status'] ?? '';
    $okStatus = in_array($status, ['available', 'unavailable'], true) || $status === $v['status'];

    if (!isset($types[$type])) {
        $err = 'Choose a vehicle type.';
    } elseif ($model === '' || strlen($model) > 100) {
        $err = 'Enter the vehicle model (up to 100 characters).';
    } elseif ($plate === '' || strlen($plate) > 20) {
        $err = 'Enter the plate number (up to 20 characters).';
    } elseif ($capacity !== null && strlen($capacity) > 50) {
        $err = 'Capacity is too long (50 characters at most).';
    } elseif (!is_numeric($rate) || (float)$rate <= 0 || (float)$rate > 99999999.99) {
        $err = 'Enter a daily rate greater than zero.';
    } elseif ($driver && !in_array($driver, array_map('intval', array_column($drivers, 'id')), true)) {
        $err = 'The own driver must be a driver service of this vehicle\'s provider.';
    } elseif (!$okStatus) {
        $err = 'Choose available or unavailable.';
    } else {
        try {
            $pdo->prepare("UPDATE vehicles SET type = ?, model = ?, plate_no = ?, capacity = ?, rate_per_day = ?, own_driver_id = ?, status = ? WHERE id = ?")
                ->execute([$type, $model, $plate, $capacity, round((float)$rate, 2), $driver, $status, $id]);
            log_action('vehicle.update', 'vehicle', $id, "Edited vehicle $model ($plate)" . ($status !== $v['status'] ? ", now $status" : ''));
            flash('Vehicle updated.');
            header('Location: vehicle.php?id=' . $id);
            exit;
        } catch (PDOException $ex) {
            $err = $ex->getCode() === '23000' ? 'Another vehicle already has that plate number.' : 'Could not save the vehicle.';
        }
    }
}
$f = fn(string $k) => ($_SERVER['REQUEST_METHOD'] === 'POST' && $err) ? ($_POST[$k] ?? '') : ($v[$k] ?? '');

manager_header($v['model'] . ' (' . $v['plate_no'] . ')', 'vehicles.php', $m);
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><a href="vehicles.php">&larr; Back to vehicles</a></p>
<p class="muted">Provider: <strong><?= e($v['provider']) ?></strong>, barangay <?= e($v['barangay']) ?>.
<?php if ($upcoming): ?> This vehicle has <?= $upcoming ?> approved booking<?= $upcoming === 1 ? '' : 's' ?> that have not ended. Marking it unavailable will not cancel them.<?php endif; ?></p>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <label>Type
    <select name="type" required>
      <?php foreach ($types as $k => [$label]): ?><option value="<?= e($k) ?>" <?= $f('type') === $k ? 'selected' : '' ?>><?= e(rtrim($label, 's')) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>Model <input name="model" required maxlength="100" value="<?= e($f('model')) ?>"></label>
  <label>Plate number <input name="plate_no" required maxlength="20" value="<?= e($f('plate_no')) ?>"></label>
  <label>Capacity <input name="capacity" maxlength="50" value="<?= e($f('capacity')) ?>"></label>
  <label>Rate per day (PHP) <input name="rate_per_day" type="number" step="0.01" min="0.01" required value="<?= e($f('rate_per_day')) ?>"></label>
  <label>Own driver
    <select name="own_driver_id">
      <option value="">No driver included</option>
      <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$f('own_driver_id') === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['title']) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>Status
    <select name="status">
      <?php foreach (array_unique(['available', 'unavailable', $v['status']]) as $s): ?>
        <option value="<?= e($s) ?>" <?= $f('status') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>&nbsp;<button>Save changes</button></label>
</form>
<?php page_footer();
