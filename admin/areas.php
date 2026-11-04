<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();
$pdo->exec("SET SESSION group_concat_max_len = 65535");
$err = '';

// Split a textarea into unique, non-empty barangay names (max 100 characters each).
function barangay_names(string $text): array {
    $cut = fn($n) => function_exists('mb_substr') ? mb_substr($n, 0, 100) : substr($n, 0, 100);
    return array_values(array_unique(array_map($cut, array_filter(array_map('trim', preg_split('/\R/', $text))))));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // A city only exists through its barangays, so adding a city means adding its first barangays.
    if ($action === 'add') {
        $province = trim($_POST['province'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $names    = barangay_names($_POST['names'] ?? '');
        if ($province === '' || $name === '') {
            $err = 'Province and city/municipality are required.';
        } elseif (!$names) {
            $err = 'Enter at least one barangay name.';
        } else {
            $ins = $pdo->prepare("INSERT IGNORE INTO areas (province, city_municipality, barangay) VALUES (?, ?, ?)");
            $added = 0;
            $pdo->beginTransaction();
            foreach ($names as $n) {
                $ins->execute([$province, $name, $n]);
                $added += $ins->rowCount();
            }
            $pdo->commit();
            $first = $pdo->prepare("SELECT MIN(id) FROM areas WHERE province = ? AND city_municipality = ?");
            $first->execute([$province, $name]);
            log_action('area.add', 'area', (int)$first->fetchColumn(), "Added $added barangay(s) to $name, $province");
            flash($added . ' barangay(s) added to ' . $name . '.');
            header('Location: areas.php');
            exit;
        }
    }
}

// Cities are paginated; the per-city figures below are only computed for the 25 rows shown.
$total = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT 1 FROM areas GROUP BY province, city_municipality) t")->fetchColumn();
$pg = paginate($total, 25);
$cities = $pdo->query(
    "SELECT c.province, c.city_municipality, c.barangay_count, c.barangay_names,
            (SELECT u.full_name FROM users u JOIN areas ua ON ua.id = u.area_id
              WHERE u.role = 'manager' AND u.is_active = 1 AND ua.province = c.province AND ua.city_municipality = c.city_municipality LIMIT 1) AS manager,
            (SELECT COUNT(*) FROM users u JOIN areas ua ON ua.id = u.area_id
              WHERE u.role = 'provider' AND ua.province = c.province AND ua.city_municipality = c.city_municipality) AS providers,
            (SELECT COUNT(*) FROM users u JOIN areas ua ON ua.id = u.area_id
              WHERE u.role = 'user' AND ua.province = c.province AND ua.city_municipality = c.city_municipality) AS customers
     FROM (SELECT province, city_municipality, COUNT(*) AS barangay_count,
                  GROUP_CONCAT(barangay ORDER BY barangay SEPARATOR ', ') AS barangay_names
           FROM areas GROUP BY province, city_municipality ORDER BY province, city_municipality
           LIMIT {$pg['per']} OFFSET {$pg['offset']}) c
     ORDER BY c.province, c.city_municipality"
)->fetchAll();

$posted = ($_POST['action'] ?? '') === 'add';
page_header('Areas', 'areas.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p class="muted">A manager covers one city or municipality. Every barangay is one area; accounts, vehicles, services, bookings and requests all point at a barangay.</p>

<h2>Add a city or municipality</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <label>Province <input name="province" required value="<?= e($posted ? ($_POST['province'] ?? '') : '') ?>"></label>
  <label>City / Municipality <input name="name" required value="<?= e($posted ? ($_POST['name'] ?? '') : '') ?>"></label>
  <label>Barangays (one per line)
    <textarea name="names" rows="4" required><?= e($posted ? ($_POST['names'] ?? '') : '') ?></textarea>
  </label>
  <label>&nbsp;<button>Add</button></label>
</form>
<p class="muted">To add barangays to a city that already exists, enter the same province and city name, or open the city below.</p>

<h2>Cities and coverage</h2>
<?php if (!$cities): ?>
  <p class="muted">Nothing to show yet.</p>
<?php else: ?>
<div class="scroll"><table>
  <thead><tr><th>Province</th><th>City / Municipality</th><th>Manager</th><th>Barangays</th><th>Providers</th><th>Users</th></tr></thead>
  <tbody>
  <?php foreach ($cities as $c): ?>
    <tr>
      <td><?= e($c['province']) ?></td>
      <td><a href="area.php?key=<?= e(urlencode(city_key($c['province'], $c['city_municipality']))) ?>"><?= e($c['city_municipality']) ?></a></td>
      <td><?= $c['manager'] ? e($c['manager']) : '<span class="muted">No manager yet</span>' ?></td>
      <td>
        <details><summary><?= (int)$c['barangay_count'] ?></summary><div style="white-space:normal;max-width:28rem"><?= e($c['barangay_names']) ?></div></details>
      </td>
      <td><?= (int)$c['providers'] ?></td>
      <td><?= (int)$c['customers'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif;
render_pager($pg);
page_footer();
