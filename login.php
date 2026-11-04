<?php
require_once __DIR__ . '/includes/site.php';

$signedInAs = $_SESSION['user']['role'] ?? '';
if ($signedInAs === 'admin' || $signedInAs === 'user') {
    header('Location: ' . ($signedInAs === 'admin' ? 'admin/dashboard.php' : 'home.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare("SELECT id, role, full_name, password_hash FROM users WHERE email = ? AND is_active = 1");
    $st->execute([trim($_POST['email'] ?? '')]);
    $user = $st->fetch();
    // Admins and customers can sign in so far; manager and provider sign-in comes with their own sides.
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        if ($user['role'] === 'admin') {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$user['id'], 'role' => 'admin', 'name' => $user['full_name']];
            log_action('login', 'user', (int)$user['id'], 'Admin signed in');
            header('Location: admin/dashboard.php');
            exit;
        }
        if ($user['role'] === 'user') {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$user['id'], 'role' => 'user', 'name' => $user['full_name']];
            header('Location: home.php');
            exit;
        }
        $error = 'Sign-in for ' . $user['role'] . ' accounts is not available yet.';
    } else {
        $error = 'Email or password is incorrect.';
    }
}

public_header('Log in');
?>
<section class="section">
  <div class="wrap loginwrap single">
    <div>
      <h2>Log in</h2>
      <p class="intro">Use your account to book a vehicle or request a service. No account yet? <a href="register.php">Create one</a>.</p>
    </div>
    <?php login_form($error); ?>
  </div>
</section>
<?php public_footer();
