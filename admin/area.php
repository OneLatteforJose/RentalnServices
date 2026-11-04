<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();

$scalar = function (string $sql, array $params) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
};

// A city is identified by ?key=Province|City. Log links use ?id=<an areas id> instead, so resolve either one.
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if ($key === '' && isset($_GET['id'])) {
    $key = city_key_of_area($pdo, (int)$_GET['id']) ?? '';
}
$parts = parse_city_key($key);
if (!$parts || !$scalar("SELECT 1 FROM areas WHERE province = ? AND city_municipality = ? LIMIT 1", $parts)) {
    flash('City not found.');
    header('Location: areas.php');
    exit;
}
[$province, $cityName] = $parts;
$cityArgs = [$province, $cityName];
$pdo->exec("SET SESSION group_concat_max_len = 65535");

$err = '';
$back = fn() => 'area.php?key=' . urlencode(city_key($province, $cityName));

function barangay_names_list(string $text): array {
    $cut = fn($n) => function_exists('mb_substr') ? mb_substr($n, 0, 100) : substr($n, 0, 100);
    return array_values(array_unique(array_map($cut, array_filter(array_map('trim', preg_split('/\R/', $text))))));
}

// Rows that point at the whole city (a city in use cannot be removed).
$cityUse = function () use ($scalar, $cityArgs): array {
    $count = fn(string $table) => (int)$scalar(
        "SELECT COUNT(*) FROM $table t JOIN areas a ON a.id = t.area_id WHERE a.province = ? AND a.city_municipality = ?", $cityArgs);
    return [
        'Accounts'         => $count('users'),
        'Vehicles'         => $count('vehicles'),
        'Services'         => $count('services'),
        'Bookings'         => $count('bookings'),
        'Service requests' => $count('service_requests'),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $bid = (int)($_POST['bid'] ?? 0);
    $inCity = fn(int $areaId) => (bool)$scalar("SELECT 1 FROM areas WHERE id = ? AND province = ? AND city_municipality = ?", array_merge([$areaId], $cityArgs));

    if ($action === 'rename_city') {
        $newProvince = trim($_POST['province'] ?? '');
        $newName     = trim($_POST['name'] ?? '');
        if ($newProvince === '' || $newName === '') {
            $err = 'Province and city/municipality are required.';
        } elseif ($scalar("SELECT 1 FROM areas WHERE province = ? AND city_municipality = ?
                           AND NOT (province = ? AND city_municipality = ?) LIMIT 1", array_merge([$newProvince, $newName], $cityArgs))) {
            // Without this check the UPDATE would silently merge two different cities into one.
            $err = 'Another city with that name already exists in that province.';
        } else {
            try {
                $pdo->prepare("UPDATE areas SET province = ?, city_municipality = ? WHERE province = ? AND city_municipality = ?")
                    ->execute(array_merge([$newProvince, $newName], $cityArgs));
                $anyId = (int)$scalar("SELECT MIN(id) FROM areas WHERE province = ? AND city_municipality = ?", [$newProvince, $newName]);
                log_action('area.update', 'area', $anyId, "Renamed $cityName, $province to $newName, $newProvince");
                flash('City updated.');
                header('Location: area.php?key=' . urlencode(city_key($newProvince, $newName)));
                exit;
            } catch (PDOException $ex) {
                $err = 'Could not save the city.';
            }
        }
    }

    if ($action === 'rename_barangay') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $err = 'A barangay name cannot be empty.';
        } elseif (!$inCity($bid)) {
            $err = 'Barangay not found.';
        } else {
            try {
                $pdo->prepare("UPDATE areas SET barangay = ? WHERE id = ?")->execute([$name, $bid]);
                log_action('barangay.update', 'area', $bid, "Renamed barangay #$bid to $name in $cityName");
                flash('Barangay updated.');
                header('Location: ' . $back());
                exit;
            } catch (PDOException $ex) {
                $err = $ex->getCode() === '23000' ? 'This city already has a barangay with that name.' : 'Could not save the barangay.';
            }
        }
    }

    if ($action === 'delete_barangay') {
        if (!$inCity($bid)) {
            $err = 'Barangay not found.';
        } else {
            $inUse = (int)$scalar("SELECT " . area_usage_sql('?'), array_fill(0, 5, $bid));
            if ($inUse > 0) {
                $err = "That barangay is used by $inUse record(s) and cannot be removed.";
            } else {
                $pdo->prepare("DELETE FROM areas WHERE id = ?")->execute([$bid]);
                log_action('barangay.delete', 'area', $bid, "Removed barangay #$bid from $cityName");
                flash('Barangay removed.');
                header('Location: ' . $back());
                exit;
            }
        }
    }

    if ($action === 'add_barangays') {
        $names = barangay_names_list($_POST['names'] ?? '');
        if (!$names) {
            $err = 'Enter at least one barangay name.';
        } else {
            $ins = $pdo->prepare("INSERT IGNORE INTO areas (province, city_municipality, barangay) VALUES (?, ?, ?)");
            $added = 0;
            $pdo->beginTransaction();
            foreach ($names as $n) {
                $ins->execute([$province, $cityName, $n]);
                $added += $ins->rowCount();
            }
            $pdo->commit();
            log_action('barangay.add', 'area', (int)$scalar("SELECT MIN(id) FROM areas WHERE province = ? AND city_municipality = ?", $cityArgs),
                       "Added $added barangay(s) to $cityName");
            flash($added . ' barangay(s) added.');
            header('Location: ' . $back());
            exit;
        }
    }

    if ($action === 'delete_city') {
        $use = array_sum($cityUse());
        if ($use > 0) {
            $err = "This city is used by $use record(s) and cannot be removed.";
        } else {
            try {
                $firstId = (int)$scalar("SELECT MIN(id) FROM areas WHERE province = ? AND city_municipality = ?", $cityArgs);
                $pdo->prepare("DELETE FROM areas WHERE province = ? AND city_municipality = ?")->execute($cityArgs);
                log_action('area.delete', 'area', $firstId, "Removed city $cityName, $province");
                flash('City removed.');
                header('Location: areas.php');
                exit;
            } catch (PDOException $ex) {
                $err = 'Could not remove the city.';
            }
        }
    }
}

$use = $cityUse();
$total = array_sum($use);
$st = $pdo->prepare("SELECT a.id, a.barangay, (" . area_usage_sql('a.id') . ") AS in_use FROM areas a
                     WHERE a.province = ? AND a.city_municipality = ? ORDER BY a.barangay");
$st->execute($cityArgs);
$barangays = $st->fetchAll();
$manager = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN areas a ON a.id = u.area_id
                          WHERE u.role = 'manager' AND u.is_active = 1 AND a.province = ? AND a.city_municipality = ? LIMIT 1");
$manager->execute($cityArgs);
$manager = $manager->fetch();

$posted = fn(string $a) => ($_POST['action'] ?? '') === $a;
page_header($cityName . ', ' . $province, 'areas.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><a href="areas.php">&larr; Back to areas</a></p>

<div class="grid">
  <div class="card"><div class="n" style="font-size:1.05rem"><?= $manager ? e($manager['full_name']) : 'None' ?></div><div class="l">Active manager</div></div>
  <div class="card"><div class="n"><?= count($barangays) ?></div><div class="l">Barangays</div></div>
  <?php foreach ($use as $label => $n): ?>
    <div class="card"><div class="n"><?= $n ?></div><div class="l"><?= e($label) ?></div></div>
  <?php endforeach; ?>
</div>

<h2>City details</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="rename_city"><input type="hidden" name="key" value="<?= e(city_key($province, $cityName)) ?>">
  <label>Province <input name="province" required value="<?= e($posted('rename_city') ? ($_POST['province'] ?? '') : $province) ?>"></label>
  <label>City / Municipality <input name="name" required value="<?= e($posted('rename_city') ? ($_POST['name'] ?? '') : $cityName) ?>"></label>
  <label>&nbsp;<button>Save</button></label>
</form>

<h2>Barangays</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="add_barangays"><input type="hidden" name="key" value="<?= e(city_key($province, $cityName)) ?>">
  <label>Add barangays (one per line) <textarea name="names" rows="3"></textarea></label>
  <label>&nbsp;<button>Add</button></label>
</form>
<?php if (!$barangays): ?>
  <p class="muted">Nothing to show yet.</p>
<?php else: ?>
<div class="scroll"><table>
  <thead><tr><th>Barangay</th><th>Used by</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($barangays as $b): ?>
    <tr>
      <td colspan="3">
        <form method="post" style="margin:0;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
          <?= csrf_field() ?><input type="hidden" name="key" value="<?= e(city_key($province, $cityName)) ?>"><input type="hidden" name="bid" value="<?= (int)$b['id'] ?>">
          <input name="name" value="<?= e($b['barangay']) ?>" required aria-label="Barangay name" style="min-width:14rem">
          <span class="muted" style="min-width:7rem"><?= (int)$b['in_use'] ?> record(s)</span>
          <button name="action" value="rename_barangay">Save name</button>
          <button name="action" value="delete_barangay" class="alt" <?= $b['in_use'] ? 'disabled title="In use, cannot be removed"' : 'onclick="return confirm(\'Remove this barangay?\')"' ?>>Remove</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<h2>Remove city</h2>
<?php if ($total > 0): ?>
  <p class="muted">This city is used by <?= $total ?> record(s) (accounts, vehicles, services, bookings or requests), so it cannot be removed. Move or deactivate those first.</p>
<?php else: ?>
  <form method="post" onsubmit="return confirm('Remove this city and its barangays?')">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete_city"><input type="hidden" name="key" value="<?= e(city_key($province, $cityName)) ?>">
    <button class="alt">Remove this city and its barangays</button>
  </form>
<?php endif;
page_footer();
