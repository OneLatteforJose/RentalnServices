<?php
require_once __DIR__ . '/includes/site.php';

$landing = ['admin' => 'admin/dashboard.php', 'manager' => 'manager/dashboard.php', 'user' => 'home.php'];
$signedInAs = $_SESSION['user']['role'] ?? '';
if (isset($landing[$signedInAs])) {
    header('Location: ' . $landing[$signedInAs]);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare("SELECT id, role, full_name, password_hash FROM users WHERE email = ? AND is_active = 1");
    $st->execute([trim($_POST['email'] ?? '')]);
    $user = $st->fetch();
    // Admins, managers and customers can sign in so far; provider sign-in comes with its own side.
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        if (isset($landing[$user['role']])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$user['id'], 'role' => $user['role'], 'name' => $user['full_name']];
            if ($user['role'] !== 'user') {
                log_action('login', 'user', (int)$user['id'], ucfirst($user['role']) . ' signed in');
            }
            header('Location: ' . $landing[$user['role']]);
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
