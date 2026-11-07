<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
[$cond, $cp] = city_filter($m['key'], 'a');

$types    = ['car' => 'Car', 'van' => 'Van', 'truck' => 'Truck'];
$statuses = ['available' => 'Available', 'booked' => 'Booked', 'unavailable' => 'Unavailable'];
$providers = my_providers($pdo, $m);
$barangays = my_barangays($pdo, $m);
$drivers   = my_driver_services($pdo, $m, false);

// Editing: ?edit=<id>, only for vehicles whose area is in this manager's city.
$editId = (int)($_GET['edit'] ?? $_POST['id'] ?? 0);
$edit = null;
if ($editId) {
    $st = $pdo->prepare("SELECT v.* FROM vehicles v JOIN areas a ON a.id = v.area_id WHERE v.id = ?$cond");
    $st->execute(array_merge([$editId], $cp));
    $edit = $st->fetch();
    if (!$edit) {
        flash('That vehicle was not found in your city.');
        header('Location: vehicles.php');
        exit;
    }
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = [
        'provider_id'  => (int)($_POST['provider_id'] ?? 0),
        'area_id'      => (int)($_POST['area_id'] ?? 0),
        'type'         => $_POST['type'] ?? '',
        'model'        => trim($_POST['model'] ?? ''),
        'plate_no'     => strtoupper(trim($_POST['plate_no'] ?? '')),
        'capacity'     => trim($_POST['capacity'] ?? '') ?: null,
        'rate_per_day' => $_POST['rate_per_day'] ?? '',
        'own_driver_id'=> (int)($_POST['own_driver_id'] ?? 0) ?: null,
        'status'       => $_POST['status'] ?? 'available',
    ];
    $driverOf = array_column($drivers, 'provider_id', 'id');   // driver service id => its provider
    if (!in_array($f['provider_id'], array_column($providers, 'id'), true) && !($edit && $f['provider_id'] === (int)$edit['provider_id'])) {
        $err = 'Choose an active provider from your city.';
    } elseif (!in_array($f['area_id'], array_column($barangays, 'id'), true)) {
        $err = 'Choose a barangay in ' . $m['city'] . '.';
    } elseif (!isset($types[$f['type']])) {
        $err = 'Choose a vehicle type.';
    } elseif ($f['model'] === '' || strlen($f['model']) > 100) {
        $err = 'Enter the model (up to 100 characters).';
    } elseif ($f['plate_no'] === '' || strlen($f['plate_no']) > 20) {
        $err = 'Enter the plate number (up to 20 characters).';
    } elseif (!is_numeric($f['rate_per_day']) || $f['rate_per_day'] <= 0 || $f['rate_per_day'] > 99999999) {
        $err = 'Enter a daily rate above zero.';
    } elseif (!isset($statuses[$f['status']])) {
        $err = 'Choose a status.';
    } elseif ($f['own_driver_id'] && (($driverOf[$f['own_driver_id']] ?? 0) !== $f['provider_id'])) {
        $err = "The vehicle's own driver must be a driver service offered by the same provider.";
    } elseif (db_value($pdo, "SELECT 1 FROM vehicles WHERE plate_no = ? AND id <> ? LIMIT 1", [$f['plate_no'], $editId])) {
        $err = 'That plate number is already registered.';
    } elseif ($edit && $f['status'] === 'unavailable' && db_value($pdo,
            "SELECT 1 FROM bookings WHERE vehicle_id = ? AND status IN ('pending','approved') AND end_at > NOW() LIMIT 1", [$editId])) {
        $err = 'This vehicle still has pending or upcoming approved bookings, so it cannot be marked unavailable yet.';
    } else {
        try {
            if ($edit) {
                $pdo->prepare("UPDATE vehicles SET provider_id=?, area_id=?, type=?, model=?, plate_no=?, capacity=?, rate_per_day=?, own_driver_id=?, status=? WHERE id=?")
                    ->execute([$f['provider_id'], $f['area_id'], $f['type'], $f['model'], $f['plate_no'], $f['capacity'], $f['rate_per_day'], $f['own_driver_id'], $f['status'], $editId]);
                log_action('vehicle.update', 'vehicle', $editId, 'Edited vehicle ' . $f['model'] . ' (' . $f['plate_no'] . ')');
                flash('Vehicle updated.');
            } else {
                $pdo->prepare("INSERT INTO vehicles (provider_id, area_id, type, model, plate_no, capacity, rate_per_day, own_driver_id, status, added_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$f['provider_id'], $f['area_id'], $f['type'], $f['model'], $f['plate_no'], $f['capacity'], $f['rate_per_day'], $f['own_driver_id'], $f['status'], $m['id']]);
                log_action('vehicle.add', 'vehicle', (int)$pdo->lastInsertId(), 'Added vehicle ' . $f['model'] . ' (' . $f['plate_no'] . ')');
                flash('Vehicle added.');
            }
            header('Location: vehicles.php');
            exit;
        } catch (PDOException $ex) {
            $err = $ex->getCode() === '23000' ? 'That plate number is already registered.' : 'Could not save the vehicle.';
        }
    }
}

// Form values: what was just posted, else the vehicle being edited, else blanks.
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$v = function (string $k, $default = '') use ($posted, $edit) {
    return $posted ? ($_POST[$k] ?? $default) : ($edit[$k] ?? $default);
};

$status = $_GET['status'] ?? '';
if (!isset($statuses[$status])) $status = '';
$where = " WHERE 1 = 1$cond";
$params = $cp;
if ($status !== '') { $where .= " AND v.status = ?"; $params[] = $status; }
$pg = paginate((int)db_value($pdo, "SELECT COUNT(*) FROM vehicles v JOIN areas a ON a.id = v.area_id$where", $params), 50);
$st = $pdo->prepare(
    "SELECT v.id, v.type, v.model, v.plate_no, v.rate_per_day, v.status, a.barangay, p.full_name AS provider, ds.title AS own_driver,
            (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id) AS bookings
     FROM vehicles v JOIN areas a ON a.id = v.area_id JOIN users p ON p.id = v.provider_id LEFT JOIN services ds ON ds.id = v.own_driver_id$where
     ORDER BY v.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}"
);
$st->execute($params);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    $r['type'] = ucfirst($r['type']);
    $r['status'] = ucfirst($r['status']);
    $r['rate_per_day'] = peso($r['rate_per_day']);
    $r['own_driver'] = $r['own_driver'] ?: '-';
}
unset($r);

manager_header($edit ? 'Edit vehicle #' . $editId : 'Vehicles', 'vehicles.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
if (!$providers && !$edit) echo '<p class="note">There is no active provider in ' . e($m['city']) . ' yet, so vehicles cannot be added. An admin creates provider accounts.</p>';
?>
<h2><?= $edit ? 'Vehicle details' : 'Add a vehicle' ?></h2>
<form method="post" class="box">
  <?= csrf_field() ?><?php if ($edit): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>
  <label>Provider (owner)
    <select name="provider_id" required>
      <option value="">Select provider</option>
      <?php foreach ($providers as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= (int)$v('provider_id') === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['full_name'] . ($p['business_name'] ? ' - ' . $p['business_name'] : '')) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Barangay (where it is based)
    <select name="area_id" required>
      <option value="">Select barangay</option>
      <?php foreach ($barangays as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= (int)$v('area_id') === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['barangay']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Type
    <select name="type" required>
      <option value="">Select type</option>
      <?php foreach ($types as $k => $label): ?><option value="<?= $k ?>" <?= $v('type') === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>Model <input name="model" maxlength="100" required value="<?= e($v('model')) ?>"></label>
  <label>Plate number <input name="plate_no" maxlength="20" required value="<?= e($v('plate_no')) ?>"></label>
  <label>Capacity (e.g. 12 seats) <input name="capacity" maxlength="50" value="<?= e($v('capacity')) ?>"></label>
  <label>Rate per day (PHP) <input name="rate_per_day" type="number" min="1" step="0.01" required value="<?= e($v('rate_per_day')) ?>"></label>
  <label>Comes with its own driver
    <select name="own_driver_id">
      <option value="">No</option>
      <?php foreach ($drivers as $d): ?>
        <option value="<?= (int)$d['id'] ?>" <?= (int)$v('own_driver_id') === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['provider'] . ' - ' . $d['title']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Status
    <select name="status">
      <?php foreach ($statuses as $k => $label): ?><option value="<?= $k ?>" <?= $v('status', 'available') === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>&nbsp;<span style="display:flex;gap:.5rem"><button><?= $edit ? 'Save changes' : 'Add vehicle' ?></button><?php if ($edit): ?><a class="viewall" style="align-self:center" href="vehicles.php">Cancel</a><?php endif; ?></span></label>
</form>

<?php if (!$edit): ?>
<h2>Vehicles in <?= e($m['city']) ?></h2>
<div class="tabs">
  <a href="?" class="<?= $status === '' ? 'on' : '' ?>">All</a>
  <?php foreach ($statuses as $k => $label): ?><a href="?status=<?= $k ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $label ?></a><?php endforeach; ?>
</div>
<?php
render_table(['id' => '#', 'model' => 'Model', 'plate_no' => 'Plate', 'type' => 'Type', 'provider' => 'Provider', 'barangay' => 'Barangay',
              'rate_per_day' => 'Rate / day', 'own_driver' => 'Own driver', 'status' => 'Status', 'bookings' => 'Bookings'],
             $rows, ['model' => fn($r) => 'vehicles.php?edit=' . $r['id']]);
render_pager($pg);
endif;
page_footer();
