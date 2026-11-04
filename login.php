<?php
require_once __DIR__ . '/includes/site.php';

if (($_SESSION['user']['role'] ?? '') === 'admin') {
    header('Location: admin/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare("SELECT id, role, full_name, password_hash FROM users WHERE email = ? AND is_active = 1");
    $st->execute([trim($_POST['email'] ?? '')]);
    $user = $st->fetch();
    // Only admin sign-in exists so far; other roles will be added with their own sides.
    if ($user && $user['role'] === 'admin' && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$user['id'], 'role' => 'admin', 'name' => $user['full_name']];
        log_action('login', 'user', (int)$user['id'], 'Admin signed in');
        header('Location: admin/dashboard.php');
        exit;
    }
    $error = 'Email or password is incorrect.';
}

public_header('Log in');
?>
<section class="section">
  <div class="wrap loginwrap single">
    <div>
      <h2>Log in</h2>
      <p class="intro">Use your account to book a vehicle or request a service.</p>
    </div>
    <?php login_form($error); ?>
  </div>
</section>
<?php public_footer();
