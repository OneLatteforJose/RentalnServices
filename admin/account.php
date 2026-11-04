<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();

$found = function (string $sql, array $params) use ($pdo): bool {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
};

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT u.*, pp.business_name, pp.recommended_by
     FROM users u LEFT JOIN provider_profiles pp ON pp.user_id = u.id
     WHERE u.id = ? AND u.role <> 'admin'"
);
$st->execute([$id]);
$u = $st->fetch();
if (!$u) {
    flash('Account not found.');
    header('Location: accounts.php');
    exit;
}

$err = '';
$back = 'account.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle' && in_array($u['role'], ['manager', 'provider'], true)) {
        $pdo->beginTransaction();
        if (!$u['is_active'] && $u['role'] === 'manager' && $u['area_id'] && manager_conflict($pdo, (int)$u['area_id'], $id)) {
            $pdo->rollBack();
            flash('That city already has an active manager. Deactivate them first.');
        } else {
            $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            $pdo->commit();
            log_action('account.status', 'user', $id, ($u['is_active'] ? 'Deactivated ' : 'Activated ') . $u['role'] . ' account');
            flash('Account status updated.');
        }
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'password') {
        $new = $_POST['new_password'] ?? '';
        if (strlen($new) < 8) {
            $err = 'New password must be at least 8 characters.';
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            $err = 'The two passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
            log_action('account.password_reset', 'user', $id, 'Reset the password of ' . $u['role'] . ' ' . $u['full_name']);
            flash('Password reset. Give the new password to the account owner.');
            header('Location: ' . $back);
            exit;
        }
    }

    if ($action === 'save') {
        $name   = trim($_POST['full_name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $phone  = trim($_POST['phone'] ?? '') ?: null;
        $area   = (int)($_POST['area_id'] ?? 0);
        $street = trim($_POST['street'] ?? '') ?: null;
        $biz    = trim($_POST['business_name'] ?? '') ?: null;
        $rec    = (int)($_POST['recommended_by'] ?? 0) ?: null;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Enter a name and a valid email.';
        } elseif (!$found("SELECT 1 FROM areas WHERE id = ?", [$area])) {
            $err = 'Select a city and barangay.';
        } else {
            if ($rec && ($rec === $id || !$found("SELECT 1 FROM users WHERE id = ? AND role = 'manager'", [$rec]))) {
                $rec = null;
            }
            try {
                $pdo->beginTransaction();
                if ($u['role'] === 'manager' && $u['is_active'] && manager_conflict($pdo, $area, $id)) {
                    $pdo->rollBack();
                    $err = 'That city already has an active manager.';
                } else {
                    $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, area_id = ?, street = ? WHERE id = ?")
                        ->execute([$name, $email, $phone, $area, $street, $id]);
                    if ($u['role'] === 'provider') {
                        $pdo->prepare(
                            "INSERT INTO provider_profiles (user_id, business_name, recommended_by) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE business_name = VALUES(business_name), recommended_by = VALUES(recommended_by)"
                        )->execute([$id, $biz, $rec]);
                    }
                    $pdo->commit();
                    log_action('account.update', 'user', $id, 'Edited ' . $u['role'] . ' account: ' . $name);
                    flash('Account updated.');
                    header('Location: ' . $back);
                    exit;
                }
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = $ex->getCode() === '23000' ? 'That email belongs to another account.' : 'Could not save the changes.';
            }
        }
    }
}

// Summary figures depend on the role.
$scalar = function (string $sql, array $params) use ($pdo): int {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
};
$facts = [];
if ($u['role'] === 'provider') {
    $facts['Vehicles']  = $scalar("SELECT COUNT(*) FROM vehicles WHERE provider_id = ?", [$id]);
    $facts['Services']  = $scalar("SELECT COUNT(*) FROM services WHERE provider_id = ?", [$id]);
} elseif ($u['role'] === 'manager') {
    // "In the manager's city" = areas rows with the same province + city_municipality as the manager's own area.
    $sameCity = "JOIN areas ma ON ma.id = ? AND ma.province = %1\$s.province AND ma.city_municipality = %1\$s.city_municipality";
    $mine = (int)$u['area_id'];
    $facts['Providers in city'] = $scalar("SELECT COUNT(*) FROM users p JOIN areas pa ON pa.id = p.area_id " . sprintf($sameCity, 'pa') . " WHERE p.role = 'provider'", [$mine]);
    $facts['Providers recommended'] = $scalar("SELECT COUNT(*) FROM provider_profiles WHERE recommended_by = ?", [$id]);
    $facts['Pending approvals'] =
        $scalar("SELECT COUNT(*) FROM bookings k JOIN areas ka ON ka.id = k.area_id " . sprintf($sameCity, 'ka') . " WHERE k.status = 'pending'", [$mine])
      + $scalar("SELECT COUNT(*) FROM service_requests r JOIN areas ra ON ra.id = r.area_id " . sprintf($sameCity, 'ra') . " WHERE r.status = 'pending'", [$mine]);
} else {
    $facts['Vehicle bookings'] = $scalar("SELECT COUNT(*) FROM bookings WHERE user_id = ?", [$id]);
    $facts['Service requests'] = $scalar("SELECT COUNT(*) FROM service_requests WHERE user_id = ?", [$id]);
}
$addedBy = null;
if ($u['created_by']) {
    $s = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $s->execute([$u['created_by']]);
    $addedBy = $s->fetchColumn() ?: null;
}

$cities    = city_options($pdo);
$barangays = barangay_options($pdo);
$mgr = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'manager' AND is_active = 1 AND id <> ? ORDER BY full_name");
$mgr->execute([$id]);
$managers  = $mgr->fetchAll();
$currentCity = $u['area_id'] ? city_key_of_area($pdo, (int)$u['area_id']) : null;

// Show posted values again if a save failed, otherwise the stored ones.
$v = fn(string $k) => ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') ? ($_POST[$k] ?? '') : ($u[$k] ?? '');

page_header(ucfirst($u['role']) . ': ' . $u['full_name'], 'accounts.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><a href="accounts.php?role=<?= e($u['role']) ?>">&larr; Back to accounts</a></p>

<div class="grid">
  <div class="card"><div class="n"><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></div><div class="l">Status</div></div>
  <?php foreach ($facts as $label => $value): ?>
    <div class="card"><div class="n"><?= (int)$value ?></div><div class="l"><?= e($label) ?></div></div>
  <?php endforeach; ?>
  <div class="card"><div class="n" style="font-size:1.05rem"><?= e(substr($u['created_at'], 0, 10)) ?></div><div class="l">Created<?= $addedBy ? ' by ' . e($addedBy) : '' ?></div></div>
</div>

<h2>Details</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $id ?>">
  <label>Full name <input name="full_name" required value="<?= e($v('full_name')) ?>"></label>
  <label>Email <input type="email" name="email" required value="<?= e($v('email')) ?>"></label>
  <label>Phone <input name="phone" value="<?= e($v('phone')) ?>"></label>
  <label>City / Municipality
    <select id="city" required>
      <option value="">Select city</option>
      <?php foreach ($cities as $c): ?>
        <option value="<?= e($c['key']) ?>" <?= $currentCity === $c['key'] ? 'selected' : '' ?>><?= e($c['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Barangay
    <select name="area_id" id="brgy" required>
      <option value="">Select barangay</option>
      <?php foreach ($barangays as $b): ?>
        <option value="<?= $b['id'] ?>" data-city="<?= e($b['city']) ?>" <?= (int)$v('area_id') === $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Street / address <input name="street" value="<?= e($v('street')) ?>"></label>
  <?php if ($u['role'] === 'provider'): ?>
    <label>Business name <input name="business_name" value="<?= e($v('business_name')) ?>"></label>
    <label>Recommended by
      <select name="recommended_by">
        <option value="">None</option>
        <?php foreach ($managers as $m): ?>
          <option value="<?= (int)$m['id'] ?>" <?= (int)$v('recommended_by') === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
  <label>&nbsp;<button>Save changes</button></label>
</form>
<script>
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

<h2>Reset password</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= $id ?>">
  <label>New password <input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
  <label>Confirm password <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label>
  <label>&nbsp;<button class="alt">Reset password</button></label>
</form>

<?php if (in_array($u['role'], ['manager', 'provider'], true)): ?>
<h2>Account status</h2>
<form method="post" onsubmit="return confirm('Change this account\'s status?')">
  <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $id ?>">
  <button class="alt"><?= $u['is_active'] ? 'Deactivate this account' : 'Activate this account' ?></button>
</form>
<?php endif;
page_footer();
