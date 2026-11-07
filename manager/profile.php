<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$err = '';

$st = $pdo->prepare("SELECT full_name, email, phone, password_hash FROM users WHERE id = ?");
$st->execute([$m['id']]);
$me = $st->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = $_POST['new_password'] ?? '';
    if (!password_verify($_POST['current_password'] ?? '', $me['password_hash'])) {
        $err = 'Your current password is incorrect.';
    } elseif (strlen($new) < 8 || strlen($new) > 72) {
        $err = 'New password must be 8 to 72 characters.';
    } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
        $err = 'The two new passwords do not match.';
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $m['id']]);
        session_regenerate_id(true);
        log_action('manager.password_change', 'user', $m['id'], 'Manager changed their own password');
        flash('Password changed.');
        header('Location: profile.php');
        exit;
    }
}

manager_header('My profile', 'profile.php', $m);
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><strong><?= e($me['full_name']) ?></strong> <span class="muted"><?= e($me['email']) ?></span><br>
<span class="muted">Manager of <?= e($m['city']) ?>, <?= e($m['province']) ?></span></p>
<h2>Change password</h2>
<form method="post" class="box">
  <?= csrf_field() ?>
  <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
  <label>New password <input type="password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password"></label>
  <label>Confirm new password <input type="password" name="confirm_password" minlength="8" maxlength="72" required autocomplete="new-password"></label>
  <label>&nbsp;<button>Change password</button></label>
</form>
<?php page_footer();
