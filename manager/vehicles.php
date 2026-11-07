<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$cp = city_params($m);
$err = '';
$types = vehicle_types();

// Active providers of this city (their vehicles are added here), and their own-driver services.
$st = $pdo->prepare(
    "SELECT u.id, u.full_name, a.barangay, pp.business_name FROM users u JOIN areas a ON a.id = u.area_id
     LEFT JOIN provider_profiles pp ON pp.user_id = u.id
     WHERE u.role = 'provider' AND u.is_active = 1 AND a.province = ? AND a.city_municipality = ? ORDER BY u.full_name"
);
$st->execute($cp);
$providers = $st->fetchAll();
$st = $pdo->prepare(
    "SELECT s.id, s.provider_id, s.title FROM services s JOIN areas a ON a.id = s.area_id
     WHERE s.category = 'driver' AND a.province = ? AND a.city_municipality = ? ORDER BY s.title"
);
$st->execute($cp);
$driverServices = $st->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $provider = (int)($_POST['provider_id'] ?? 0);
    $type     = $_POST['type'] ?? '';
    $model    = trim($_POST['model'] ?? '');
    $plate    = strtoupper(trim($_POST['plate_no'] ?? ''));
    $capacity = trim($_POST['capacity'] ?? '') ?: null;
    $rate     = $_POST['rate_per_day'] ?? '';
    $driver   = (int)($_POST['own_driver_id'] ?? 0) ?: null;

    $prov = null;
    foreach ($providers as $p) if ((int)$p['id'] === $provider) $prov = $p;
    $drv = null;
    foreach ($driverServices as $d) if ((int)$d['id'] === $driver) $drv = $d;

    if (!$prov) {
        $err = 'Choose one of the active providers in your city.';
    } elseif (!isset($types[$type])) {
        $err = 'Choose a vehicle type.';
    } elseif ($model === '' || strlen($model) > 100) {
        $err = 'Enter the vehicle model (up to 100 characters).';
    } elseif ($plate === '' || strlen($plate) > 20) {
        $err = 'Enter the plate number (up to 20 characters).';
    } elseif ($capacity !== null && strlen($capacity) > 50) {
        $err = 'Capacity is too long (50 characters at most).';
    } elseif (!is_numeric($rate) || (float)$rate <= 0 || (float)$rate > 99999999.99) {
        $err = 'Enter a daily rate greater than zero.';
    } elseif ($driver && (!$drv || (int)$drv['provider_id'] !== $provider)) {
        $err = 'The own driver must be a driver service of the same provider.';
    } else {
        try {
            $pdo->prepare(
                "INSERT INTO vehicles (provider_id, area_id, type, model, plate_no, capacity, rate_per_day, own_driver_id, status, added_by)
                 SELECT u.id, u.area_id, ?, ?, ?, ?, ?, ?, 'available', ? FROM users u WHERE u.id = ?"
            )->execute([$type, $model, $plate, $capacity, round((float)$rate, 2), $driver, $m['id'], $provider]);
            $vid = (int)$pdo->lastInsertId();
            log_action('vehicle.add', 'vehicle', $vid, "Added vehicle $model ($plate)");
            flash('Vehicle added.');
            header('Location: vehicles.php');
            exit;
        } catch (PDOException $ex) {
            $err = $ex->getCode() === '23000' ? 'A vehicle with that plate number already exists.' : 'Could not save the vehicle.';
        }
    }
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM vehicles v JOIN areas a ON a.id = v.area_id WHERE a.province = ? AND a.city_municipality = ?");
$cnt->execute($cp);
$pg = paginate((int)$cnt->fetchColumn(), 25);
$st = $pdo->prepare(
    "SELECT v.id, v.type, v.model, v.plate_no, v.rate_per_day, v.status, p.full_name AS provider, a.barangay,
            IF(v.own_driver_id IS NULL, 'No', 'Yes') AS own_driver,
            (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id) AS bookings
     FROM vehicles v JOIN areas a ON a.id = v.area_id JOIN users p ON p.id = v.provider_id
     WHERE a.province = ? AND a.city_municipality = ? ORDER BY v.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}"
);
$st->execute($cp);
$rows = $st->fetchAll();
foreach ($rows as &$r) { $r['type'] = ucfirst($r['type']); $r['status'] = ucfirst($r['status']); $r['rate_per_day'] = money($r['rate_per_day']); }
unset($r);

manager_header('Vehicles', 'vehicles.php', $m);
if ($err) echo '<p class="err">' . e($err) . '</p>';
if (!$providers) echo '<p class="muted">There are no active providers in ' . e($m['city']) . ' yet. Recommend one on the Providers page, and the admin will add them.</p>';
else { ?>
<details class="panel" style="margin-bottom:1.25rem" <?= $err ? 'open' : '' ?>>
  <summary style="cursor:pointer;font-weight:600">Add a vehicle</summary>
  <form method="post" class="box" style="margin:.8rem 0 0;border:0;padding:0">
    <?= csrf_field() ?>
    <label>Provider
      <select name="provider_id" required>
        <option value="">Select provider</option>
        <?php foreach ($providers as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)($_POST['provider_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['full_name'] . ($p['business_name'] ? ' - ' . $p['business_name'] : '') . ' (' . $p['barangay'] . ')') ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Type
      <select name="type" required>
        <?php foreach ($types as $k => [$label]): ?><option value="<?= e($k) ?>" <?= ($_POST['type'] ?? '') === $k ? 'selected' : '' ?>><?= e(rtrim($label, 's')) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Model <input name="model" required maxlength="100" value="<?= e($_POST['model'] ?? '') ?>"></label>
    <label>Plate number <input name="plate_no" required maxlength="20" value="<?= e($_POST['plate_no'] ?? '') ?>"></label>
    <label>Capacity (optional) <input name="capacity" maxlength="50" placeholder="e.g. 12 seats" value="<?= e($_POST['capacity'] ?? '') ?>"></label>
    <label>Rate per day (PHP) <input name="rate_per_day" type="number" step="0.01" min="0.01" required value="<?= e($_POST['rate_per_day'] ?? '') ?>"></label>
    <label>Own driver (optional)
      <select name="own_driver_id">
        <option value="">No driver included</option>
        <?php foreach ($driverServices as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (int)($_POST['own_driver_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['title']) ?> (provider #<?= (int)$d['provider_id'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>&nbsp;<button>Add vehicle</button></label>
  </form>
</details>
<?php }
render_table(
    ['id' => '#', 'type' => 'Type', 'model' => 'Model', 'plate_no' => 'Plate', 'provider' => 'Provider', 'barangay' => 'Barangay',
     'rate_per_day' => 'Rate / day', 'own_driver' => 'Own driver', 'status' => 'Status', 'bookings' => 'Bookings'],
    $rows,
    ['model' => fn($r) => 'vehicle.php?id=' . $r['id']]
);
render_pager($pg);
page_footer();
