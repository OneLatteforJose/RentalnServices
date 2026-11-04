<?php
// Customer sign-up. Always creates a role 'user' account: the role is never read from the form.
require_once __DIR__ . '/includes/site.php';
require_once __DIR__ . '/includes/areas.php';

$signedInAs = $_SESSION['user']['role'] ?? '';
if ($signedInAs === 'admin' || $signedInAs === 'user') {
    header('Location: ' . ($signedInAs === 'admin' ? 'admin/dashboard.php' : 'home.php'));
    exit;
}

$pdo = db();
$cities = city_options($pdo);
$barangays = barangay_options($pdo);
$err = '';
$f = ['full_name' => '', 'email' => '', 'phone' => '', 'city' => '', 'area_id' => 0, 'street' => ''];

$len = fn(string $s): int => function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['website'] ?? '') !== '') {   // hidden field that only bots fill in
        exit;
    }
    $f = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'email'     => trim($_POST['email'] ?? ''),
        'phone'     => trim($_POST['phone'] ?? ''),
        'city'      => (string)($_POST['city'] ?? ''),
        'area_id'   => (int)($_POST['area_id'] ?? 0),
        'street'    => trim($_POST['street'] ?? ''),
    ];
    $pass = $_POST['password'] ?? '';

    $areaOk = $pdo->prepare("SELECT 1 FROM areas WHERE id = ?");
    $areaOk->execute([$f['area_id']]);

    if ($f['full_name'] === '' || $len($f['full_name']) > 150) {
        $err = 'Enter your full name.';
    } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || $len($f['email']) > 150) {
        $err = 'Enter a valid email address.';
    } elseif ($f['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{7,30}$/', $f['phone'])) {
        $err = 'Enter a valid phone number, or leave it empty.';
    } elseif (!$areaOk->fetchColumn()) {
        $err = 'Select your city and barangay.';
    } elseif ($f['street'] === '' || $len($f['street']) > 255) {
        $err = 'Enter your street or house address.';
    } elseif (strlen($pass) < 8 || strlen($pass) > 72) {
        $err = 'Password must be 8 to 72 characters.';
    } elseif ($pass !== ($_POST['confirm_password'] ?? '')) {
        $err = 'The two passwords do not match.';
    } else {
        try {
            $pdo->prepare(
                "INSERT INTO users (role, full_name, email, phone, password_hash, area_id, street)
                 VALUES ('user', ?, ?, ?, ?, ?, ?)"
            )->execute([$f['full_name'], $f['email'], $f['phone'] ?: null, password_hash($pass, PASSWORD_DEFAULT), $f['area_id'], $f['street']]);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$pdo->lastInsertId(), 'role' => 'user', 'name' => $f['full_name']];
            flash('Your account is ready.');
            header('Location: home.php');
            exit;
        } catch (PDOException $ex) {
            $err = $ex->getCode() === '23000' ? 'An account with that email already exists. Try logging in instead.' : 'Could not create the account. Please try again.';
        }
    }
}

public_header('Create an account');
?>
<style>
.formcard select{font:inherit;padding:.65rem .75rem;border:1px solid #9fb0a5;border-radius:8px;background:#fff;color:var(--ink)}
.hp{position:absolute;left:-9999px;height:0;overflow:hidden}
</style>
<section class="section">
  <div class="wrap loginwrap single" style="grid-template-columns:minmax(0,560px)">
    <div>
      <h2>Create an account</h2>
      <p class="intro">Sign up to book vehicles or request services in your area. Already registered? <a href="login.php">Log in</a>.</p>
    </div>
    <?php if (!$cities): ?>
      <p class="alert" role="alert">Sign-up is not open yet because no service areas have been added.</p>
    <?php else: ?>
    <form method="post" class="formcard" autocomplete="on">
      <?php if ($err): ?><p class="alert" role="alert"><?= e($err) ?></p><?php endif; ?>
      <?= csrf_field() ?>
      <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
      <label>Full name <input name="full_name" autocomplete="name" required maxlength="150" value="<?= e($f['full_name']) ?>"></label>
      <label>Email <input type="email" name="email" autocomplete="email" required maxlength="150" value="<?= e($f['email']) ?>"></label>
      <label>Phone (optional) <input type="tel" name="phone" autocomplete="tel" maxlength="30" value="<?= e($f['phone']) ?>"></label>
      <label>City / Municipality
        <select name="city" id="city" required>
          <option value="">Select city</option>
          <?php foreach ($cities as $c): ?>
            <option value="<?= e($c['key']) ?>" <?= $f['city'] === $c['key'] ? 'selected' : '' ?>><?= e($c['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Barangay
        <select name="area_id" id="brgy" required>
          <option value="">Select barangay</option>
          <?php foreach ($barangays as $b): ?>
            <option value="<?= $b['id'] ?>" data-city="<?= e($b['city']) ?>" <?= $f['area_id'] === $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Street / house address <input name="street" autocomplete="street-address" required maxlength="255" value="<?= e($f['street']) ?>"></label>
      <label>Password (at least 8 characters) <input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <label>Confirm password <input type="password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <button class="btn btn-road">Create account</button>
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
    <?php endif; ?>
  </div>
</section>
<?php public_footer();
