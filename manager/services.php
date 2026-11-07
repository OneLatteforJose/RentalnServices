<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
[$cond, $cp] = city_filter($m['key'], 'a');

$categories = array_map(fn($c) => $c[0], service_categories());   // key => label
$statuses   = ['available' => 'Available', 'unavailable' => 'Unavailable'];
$providers  = my_providers($pdo, $m);
$barangays  = my_barangays($pdo, $m);

$editId = (int)($_GET['edit'] ?? $_POST['id'] ?? 0);
$edit = null;
if ($editId) {
    $st = $pdo->prepare("SELECT s.* FROM services s JOIN areas a ON a.id = s.area_id WHERE s.id = ?$cond");
    $st->execute(array_merge([$editId], $cp));
    $edit = $st->fetch();
    if (!$edit) {
        flash('That service was not found in your city.');
        header('Location: services.php');
        exit;
    }
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = [
        'provider_id' => (int)($_POST['provider_id'] ?? 0),
        'area_id'     => (int)($_POST['area_id'] ?? 0),
        'category'    => $_POST['category'] ?? '',
        'title'       => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? '') ?: null,
        'rate'        => $_POST['rate'] ?? '',
        'status'      => $_POST['status'] ?? 'available',
    ];
    if (!in_array($f['provider_id'], array_column($providers, 'id'), true) && !($edit && $f['provider_id'] === (int)$edit['provider_id'])) {
        $err = 'Choose an active provider from your city.';
    } elseif (!in_array($f['area_id'], array_column($barangays, 'id'), true)) {
        $err = 'Choose a barangay in ' . $m['city'] . '.';
    } elseif (!isset($categories[$f['category']])) {
        $err = 'Choose a category.';
    } elseif ($f['title'] === '' || strlen($f['title']) > 150) {
        $err = 'Enter a title (up to 150 characters).';
    } elseif (!is_numeric($f['rate']) || $f['rate'] <= 0 || $f['rate'] > 99999999) {
        $err = 'Enter a rate above zero.';
    } elseif (!isset($statuses[$f['status']])) {
        $err = 'Choose a status.';
    } elseif ($edit && $f['status'] === 'unavailable' && db_value($pdo,
            "SELECT 1 FROM service_requests WHERE service_id = ? AND status IN ('pending','approved') AND scheduled_at > NOW() LIMIT 1", [$editId])) {
        $err = 'This service still has pending or upcoming approved requests, so it cannot be marked unavailable yet.';
    } elseif ($edit && $edit['category'] === 'driver' && $f['category'] !== 'driver' && db_value($pdo,
            "SELECT 1 FROM vehicles WHERE own_driver_id = ? UNION ALL SELECT 1 FROM bookings WHERE driver_service_id = ? LIMIT 1", [$editId, $editId])) {
        $err = 'This driver service is used by a vehicle or booking, so its category cannot change.';
    } else {
        try {
            if ($edit) {
                $pdo->prepare("UPDATE services SET provider_id=?, area_id=?, category=?, title=?, description=?, rate=?, status=? WHERE id=?")
                    ->execute([$f['provider_id'], $f['area_id'], $f['category'], $f['title'], $f['description'], $f['rate'], $f['status'], $editId]);
                log_action('service.update', 'service', $editId, 'Edited service ' . $f['title']);
                flash('Service updated.');
            } else {
                $pdo->prepare("INSERT INTO services (provider_id, area_id, category, title, description, rate, status, added_by) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$f['provider_id'], $f['area_id'], $f['category'], $f['title'], $f['description'], $f['rate'], $f['status'], $m['id']]);
                log_action('service.add', 'service', (int)$pdo->lastInsertId(), 'Added service ' . $f['title']);
                flash('Service added.');
            }
            header('Location: services.php');
            exit;
        } catch (PDOException $ex) {
            $err = 'Could not save the service.';
        }
    }
}

$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$v = function (string $k, $default = '') use ($posted, $edit) {
    return $posted ? ($_POST[$k] ?? $default) : ($edit[$k] ?? $default);
};

$status = $_GET['status'] ?? '';
if (!isset($statuses[$status])) $status = '';
$category = $_GET['category'] ?? '';
if (!isset($categories[$category])) $category = '';
$where = " WHERE 1 = 1$cond";
$params = $cp;
if ($status !== '')   { $where .= " AND s.status = ?";   $params[] = $status; }
if ($category !== '') { $where .= " AND s.category = ?"; $params[] = $category; }
$pg = paginate((int)db_value($pdo, "SELECT COUNT(*) FROM services s JOIN areas a ON a.id = s.area_id$where", $params), 50);
$st = $pdo->prepare(
    "SELECT s.id, s.category, s.title, s.rate, s.status, a.barangay, p.full_name AS provider,
            (SELECT COUNT(*) FROM service_requests r WHERE r.service_id = s.id) AS requests
     FROM services s JOIN areas a ON a.id = s.area_id JOIN users p ON p.id = s.provider_id$where
     ORDER BY s.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}"
);
$st->execute($params);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    $r['category'] = $categories[$r['category']] ?? $r['category'];
    $r['status'] = ucfirst($r['status']);
    $r['rate'] = peso($r['rate']);
}
unset($r);

manager_header($edit ? 'Edit service #' . $editId : 'Services', 'services.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
if (!$providers && !$edit) echo '<p class="note">There is no active provider in ' . e($m['city']) . ' yet, so services cannot be added. An admin creates provider accounts.</p>';
?>
<h2><?= $edit ? 'Service details' : 'Add a service' ?></h2>
<form method="post" class="box">
  <?= csrf_field() ?><?php if ($edit): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>
  <label>Provider
    <select name="provider_id" required>
      <option value="">Select provider</option>
      <?php foreach ($providers as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= (int)$v('provider_id') === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['full_name'] . ($p['business_name'] ? ' - ' . $p['business_name'] : '')) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Barangay (where it is offered)
    <select name="area_id" required>
      <option value="">Select barangay</option>
      <?php foreach ($barangays as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= (int)$v('area_id') === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['barangay']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Category
    <select name="category" required>
      <option value="">Select category</option>
      <?php foreach ($categories as $k => $label): ?><option value="<?= $k ?>" <?= $v('category') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>Title <input name="title" maxlength="150" required value="<?= e($v('title')) ?>"></label>
  <label>Rate (PHP) <input name="rate" type="number" min="1" step="0.01" required value="<?= e($v('rate')) ?>"></label>
  <label>Status
    <select name="status">
      <?php foreach ($statuses as $k => $label): ?><option value="<?= $k ?>" <?= $v('status', 'available') === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
    </select>
  </label>
  <label style="grid-column:1/-1">Description <textarea name="description" rows="3"><?= e($v('description')) ?></textarea></label>
  <label>&nbsp;<span style="display:flex;gap:.5rem"><button><?= $edit ? 'Save changes' : 'Add service' ?></button><?php if ($edit): ?><a class="viewall" style="align-self:center" href="services.php">Cancel</a><?php endif; ?></span></label>
</form>

<?php if (!$edit): ?>
<h2>Services in <?= e($m['city']) ?></h2>
<div class="tabs">
  <a href="?" class="<?= ($status === '' && $category === '') ? 'on' : '' ?>">All</a>
  <?php foreach ($statuses as $k => $label): ?><a href="?status=<?= $k ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $label ?></a><?php endforeach; ?>
</div>
<?php
render_table(['id' => '#', 'title' => 'Service', 'category' => 'Category', 'provider' => 'Provider', 'barangay' => 'Barangay', 'rate' => 'Rate', 'status' => 'Status', 'requests' => 'Requests'],
             $rows, ['title' => fn($r) => 'services.php?edit=' . $r['id']]);
render_pager($pg);
endif;
page_footer();
