<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_admin();
$pdo = db();
$me = $_SESSION['user']['id'];
$err = '';

$st = $pdo->prepare("SELECT full_name, email, password_hash FROM users WHERE id = ?");
$st->execute([$me]);
$admin = $st->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = $_POST['new_password'] ?? '';
    if (!password_verify($_POST['current_password'] ?? '', $admin['password_hash'])) {
        $err = 'Your current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $err = 'New password must be at least 8 characters.';
    } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
        $err = 'The two new passwords do not match.';
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $me]);
        session_regenerate_id(true);
        log_action('admin.password_change', 'user', $me, 'Admin changed their own password');
        flash('Password changed.');
        header('Location: profile.php');
        exit;
    }
}

page_header('My profile', 'profile.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p><strong><?= e($admin['full_name']) ?></strong> <span class="muted"><?= e($admin['email']) ?></span></p>
<h2>Change password</h2>
<form method="post" class="box">
  <?= csrf_field() ?>
  <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
  <label>New password <input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
  <label>Confirm new password <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label>
  <label>&nbsp;<button>Change password</button></label>
</form>
<?php page_footer();
