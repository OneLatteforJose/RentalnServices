<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();
$adminId = $_SESSION['user']['id'];
$err = '';

// Small helper: does this query return a row?
$found = function (string $sql, array $params) use ($pdo): bool {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        // Admin can activate/deactivate managers and providers only.
        $id = (int)($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT role, is_active, area_id FROM users WHERE id = ? AND role IN ('manager','provider') FOR UPDATE");
        $st->execute([$id]);
        $u = $st->fetch();
        if ($u) {
            $activating = !$u['is_active'];
            if ($activating && $u['role'] === 'manager' && $u['area_id'] && manager_conflict($pdo, (int)$u['area_id'], $id)) {
                $pdo->rollBack();
                flash('That city already has an active manager. Deactivate them first.');
            } else {
                $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                $pdo->commit();
                log_action('account.status', 'user', $id, ($activating ? 'Activated ' : 'Deactivated ') . $u['role'] . ' account');
                flash('Account status updated.');
            }
        } else {
            $pdo->rollBack();
        }
        header('Location: accounts.php?role=' . urlencode($_POST['filter'] ?? 'all'));
        exit;
    }

    if ($action === 'add') {
        $role   = $_POST['role'] ?? '';
        $name   = trim($_POST['full_name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $phone  = trim($_POST['phone'] ?? '') ?: null;
        $pass   = $_POST['password'] ?? '';
        $area   = (int)($_POST['area_id'] ?? 0);   // the barangay; its city is what a manager covers
        $street = trim($_POST['street'] ?? '') ?: null;
        $biz    = trim($_POST['business_name'] ?? '') ?: null;
        $rec    = (int)($_POST['recommended_by'] ?? 0) ?: null;

        if (!in_array($role, ['manager', 'provider'], true)) {
            $err = 'Choose manager or provider.';
        } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Enter a name and a valid email.';
        } elseif (strlen($pass) < 8) {
            $err = 'Temporary password must be at least 8 characters.';
        } elseif (!$found("SELECT 1 FROM areas WHERE id = ?", [$area])) {
            $err = 'Select a city and barangay.';
        } else {
            if ($rec && !$found("SELECT 1 FROM users WHERE id = ? AND role = 'manager'", [$rec])) {
                $rec = null;
            }
            try {
                $pdo->beginTransaction();
                if ($role === 'manager' && manager_conflict($pdo, $area)) {
                    $pdo->rollBack();
                    $err = 'That city already has an active manager.';
                } else {
                    $pdo->prepare(
                        "INSERT INTO users (role, full_name, email, phone, password_hash, area_id, street, created_by)
                         VALUES (?,?,?,?,?,?,?,?)"
                    )->execute([$role, $name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), $area, $street, $adminId]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($role === 'provider') {
                        $pdo->prepare("INSERT INTO provider_profiles (user_id, business_name, recommended_by) VALUES (?,?,?)")
                            ->execute([$newId, $biz, $rec]);
                    }
                    $pdo->commit();
                    log_action('account.create', 'user', $newId, "Created $role account: $name");
                    flash(ucfirst($role) . ' account created.');
                    header('Location: accounts.php?role=' . $role);
                    exit;
                }
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = $ex->getCode() === '23000' ? 'That email is already registered.' : 'Could not save the account.';
            }
        }
    }
}

$filter = $_GET['role'] ?? 'all';
$where = " WHERE u.role <> 'admin'";
$params = [];
if (in_array($filter, ['manager', 'provider', 'user'], true)) {
    $where .= " AND u.role = ?";
    $params[] = $filter;
} else {
    $filter = 'all';
}
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';   // so a typed % or _ is searched literally
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = $like;
    $params[] = $like;
}
$cnt = $pdo->prepare("SELECT COUNT(*) FROM users u" . $where);
$cnt->execute($params);
$pg = paginate((int)$cnt->fetchColumn(), 50);
$st = $pdo->prepare(
    "SELECT u.id, u.role, u.full_name, u.email, u.phone, u.street, u.is_active, a.city_municipality AS city, a.barangay
     FROM users u LEFT JOIN areas a ON a.id = u.area_id" . $where . " ORDER BY u.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}"
);
$st->execute($params);
$accounts = $st->fetchAll();

$cities    = city_options($pdo);
$barangays = barangay_options($pdo);
$managers  = $pdo->query("SELECT id, full_name FROM users WHERE role = 'manager' AND is_active = 1 ORDER BY full_name")->fetchAll();

page_header('Accounts', 'accounts.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
if (!$cities) echo '<p class="err">Add at least one city first (Areas page) before creating accounts.</p>';
?>
<h2>Add manager or provider</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <label>Role
    <select name="role" required>
      <option value="manager" <?= ($_POST['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager (covers the whole city of the barangay)</option>
      <option value="provider" <?= ($_POST['role'] ?? '') === 'provider' ? 'selected' : '' ?>>Provider</option>
    </select>
  </label>
  <label>Full name <input name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>"></label>
  <label>Email <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
  <label>Phone <input name="phone" value="<?= e($_POST['phone'] ?? '') ?>"></label>
  <label>City / Municipality
    <select name="city" id="city" required>
      <option value="">Select city</option>
      <?php foreach ($cities as $c): ?>
        <option value="<?= e($c['key']) ?>" <?= ($_POST['city'] ?? '') === $c['key'] ? 'selected' : '' ?>><?= e($c['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Barangay
    <select name="area_id" id="brgy" required>
      <option value="">Select barangay</option>
      <?php foreach ($barangays as $b): ?>
        <option value="<?= $b['id'] ?>" data-city="<?= e($b['city']) ?>" <?= (int)($_POST['area_id'] ?? 0) === $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Street / address <input name="street" value="<?= e($_POST['street'] ?? '') ?>"></label>
  <label>Business name (providers) <input name="business_name" value="<?= e($_POST['business_name'] ?? '') ?>"></label>
  <label>Recommended by (providers)
    <select name="recommended_by">
      <option value="">None</option>
      <?php foreach ($managers as $m): ?>
        <option value="<?= (int)$m['id'] ?>" <?= (int)($_POST['recommended_by'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Temporary password <input type="password" name="password" minlength="8" required></label>
  <label>&nbsp;<button>Create account</button></label>
</form>
<script>
// Show only the barangays that belong to the selected city.
(function () {
  var city = document.getElementById('city'), brgy = document.getElementById('brgy');
  function sync() {
    Array.prototype.forEach.call(brgy.options, function (o) {
      if (!o.value) return;
      var ok = o.getAttribute('data-city') === city.value;
      o.hidden = !ok; o.disabled = !ok;
    });
    if (brgy.selectedOptions[0] && brgy.selectedOptions[0].disabled) brgy.value = '';
  }
  city.addEventListener('change', sync);
  sync();
})();
</script>

<h2>All accounts</h2>
<div class="tabs">
  <?php foreach (['all' => 'All', 'manager' => 'Managers', 'provider' => 'Providers', 'user' => 'Users'] as $k => $label): ?>
    <a href="?role=<?= $k ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="box" style="grid-template-columns:1fr auto">
  <input type="hidden" name="role" value="<?= e($filter) ?>">
  <input name="q" placeholder="Search by name or email" value="<?= e($q) ?>" aria-label="Search accounts">
  <button>Search</button>
</form>
<?php if (!$accounts): ?>
  <p class="muted">Nothing to show yet.</p>
<?php else: ?>
<div class="scroll"><table>
  <thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Phone</th><th>City / Municipality</th><th>Barangay</th><th>Street</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($accounts as $u): ?>
    <tr>
      <td><a href="account.php?id=<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></a></td>
      <td><?= e(ucfirst($u['role'])) ?></td>
      <td><?= e($u['email']) ?></td>
      <td><?= e($u['phone']) ?></td>
      <td><?= e($u['city']) ?></td>
      <td><?= e($u['barangay']) ?></td>
      <td><?= e($u['street']) ?></td>
      <td><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></td>
      <td>
        <?php if (in_array($u['role'], ['manager', 'provider'], true)): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('Change this account\'s status?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <button class="alt"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif;
render_pager($pg);
page_footer();
