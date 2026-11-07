<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$cp = city_params($m);
$err = '';
$cats = service_categories();

$st = $pdo->prepare(
    "SELECT u.id, u.full_name, a.barangay, pp.business_name FROM users u JOIN areas a ON a.id = u.area_id
     LEFT JOIN provider_profiles pp ON pp.user_id = u.id
     WHERE u.role = 'provider' AND u.is_active = 1 AND a.province = ? AND a.city_municipality = ? ORDER BY u.full_name"
);
$st->execute($cp);
$providers = $st->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $provider = (int)($_POST['provider_id'] ?? 0);
    $cat      = $_POST['category'] ?? '';
    $title    = trim($_POST['title'] ?? '');
    $desc     = trim($_POST['description'] ?? '') ?: null;
    $rate     = $_POST['rate'] ?? '';

    $prov = false;
    foreach ($providers as $p) if ((int)$p['id'] === $provider) $prov = true;

    if (!$prov) {
        $err = 'Choose one of the active providers in your city.';
    } elseif (!isset($cats[$cat])) {
        $err = 'Choose a service category.';
    } elseif ($title === '' || strlen($title) > 150) {
        $err = 'Enter a service title (up to 150 characters).';
    } elseif ($desc !== null && strlen($desc) > 5000) {
        $err = 'The description is too long.';
    } elseif (!is_numeric($rate) || (float)$rate <= 0 || (float)$rate > 99999999.99) {
        $err = 'Enter a rate greater than zero.';
    } else {
        $pdo->prepare(
            "INSERT INTO services (provider_id, area_id, category, title, description, rate, status, added_by)
             SELECT u.id, u.area_id, ?, ?, ?, ?, 'available', ? FROM users u WHERE u.id = ?"
        )->execute([$cat, $title, $desc, round((float)$rate, 2), $m['id'], $provider]);
        log_action('service.add', 'service', (int)$pdo->lastInsertId(), "Added service $title");
        flash('Service added.');
        header('Location: services.php');
        exit;
    }
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM services s JOIN areas a ON a.id = s.area_id WHERE a.province = ? AND a.city_municipality = ?");
$cnt->execute($cp);
$pg = paginate((int)$cnt->fetchColumn(), 25);
$st = $pdo->prepare(
    "SELECT s.id, s.category, s.title, s.rate, s.status, p.full_name AS provider, a.barangay,
            (SELECT COUNT(*) FROM service_requests r WHERE r.service_id = s.id) AS requests
     FROM services s JOIN areas a ON a.id = s.area_id JOIN users p ON p.id = s.provider_id
     WHERE a.province = ? AND a.city_municipality = ? ORDER BY s.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}"
);
$st->execute($cp);
$rows = $st->fetchAll();
foreach ($rows as &$r) { $r['category'] = $cats[$r['category']][0] ?? $r['category']; $r['status'] = ucfirst($r['status']); $r['rate'] = money($r['rate']); }
unset($r);

manager_header('Services', 'services.php', $m);
if ($err) echo '<p class="err">' . e($err) . '</p>';
if (!$providers) echo '<p class="muted">There are no active providers in ' . e($m['city']) . ' yet. Recommend one on the Providers page, and the admin will add them.</p>';
else { ?>
<details class="panel" style="margin-bottom:1.25rem" <?= $err ? 'open' : '' ?>>
  <summary style="cursor:pointer;font-weight:600">Add a service</summary>
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
    <label>Category
      <select name="category" required>
        <?php foreach ($cats as $k => [$label]): ?><option value="<?= e($k) ?>" <?= ($_POST['category'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Title <input name="title" required maxlength="150" value="<?= e($_POST['title'] ?? '') ?>"></label>
    <label>Rate (PHP) <input name="rate" type="number" step="0.01" min="0.01" required value="<?= e($_POST['rate'] ?? '') ?>"></label>
    <label style="grid-column:1/-1">Description (optional) <textarea name="description" rows="3"><?= e($_POST['description'] ?? '') ?></textarea></label>
    <label>&nbsp;<button>Add service</button></label>
  </form>
</details>
<?php }
render_table(
    ['id' => '#', 'category' => 'Category', 'title' => 'Service', 'provider' => 'Provider', 'barangay' => 'Barangay', 'rate' => 'Rate',
     'status' => 'Status', 'requests' => 'Requests'],
    $rows,
    ['title' => fn($r) => 'service.php?id=' . $r['id']]
);
render_pager($pg);
page_footer();
