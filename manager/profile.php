<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = $_POST['current'] ?? '';
    $new     = $_POST['new'] ?? '';
    $hash = db_value($pdo, "SELECT password_hash FROM users WHERE id = ?", [$m['id']]);
    if (!password_verify($current, (string)$hash)) {
        $err = 'Your current password is not correct.';
    } elseif (strlen($new) < 8) {
        $err = 'The new password must be at least 8 characters.';
    } elseif ($new !== ($_POST['confirm'] ?? '')) {
        $err = 'The new password and its confirmation do not match.';
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $m['id']]);
        log_action('password.change', 'user', $m['id'], 'Manager changed their own password');
        flash('Password changed.');
        header('Location: profile.php');
        exit;
    }
}
$me = $pdo->prepare("SELECT email, phone FROM users WHERE id = ?");
$me->execute([$m['id']]);
$me = $me->fetch();

manager_header('Profile', '');
if ($err) echo '<p class="err">' . e($err) . '</p>';
render_kv(['Name' => $m['full_name'], 'Email' => $me['email'], 'Phone' => $me['phone'], 'City covered' => $m['city'] . ', ' . $m['province']]);
?>
<h2>Change password</h2>
<form method="post" class="box">
  <?= csrf_field() ?>
  <label>Current password <input type="password" name="current" autocomplete="current-password" required></label>
  <label>New password (at least 8 characters) <input type="password" name="new" autocomplete="new-password" minlength="8" required></label>
  <label>Confirm new password <input type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
  <label>&nbsp;<button>Change password</button></label>
</form>
<?php
page_footer();
