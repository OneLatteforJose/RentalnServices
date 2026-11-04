<?php
// Shared pieces for the public pages (landing + login).
require_once __DIR__ . '/auth.php';

const SITE_NAME = 'Sakay at Serbisyo';   // placeholder name: change it here and it updates everywhere

function vehicle_types(): array {
    return [
        'car'   => ['Cars',   'Daily trips, errands and airport rides.'],
        'van'   => ['Vans',   'Families, groups and trips with luggage.'],
        'truck' => ['Trucks', 'Hauling goods, furniture and equipment.'],
    ];
}

// Keys match the services.category ENUM in booking_and_services.sql
function service_categories(): array {
    return [
        'handyman'     => ['Handyman',     'Repairs, mounting and small fixes around the house.'],
        'plumbing'     => ['Plumbing',     'Leaks, clogged drains and fixture installation.'],
        'cleaning'     => ['Cleaning',     'Cleaning for homes and small offices.'],
        'electrical'   => ['Electrical',   'Wiring, outlets and lighting repairs.'],
        'beauty'       => ['Beauty',       'Hair and nails.'],
        'laundry'      => ['Laundry',      'Washing, drying and folding.'],
        'gardening'    => ['Gardening',    'Lawn care, trimming and planting.'],
        'driver'       => ['Driver',       'A driver for a rented vehicle.'],
        'body_massage' => ['Body massage', 'Massage from local practitioners.'],
    ];
}

function public_header(string $title, bool $onHome = false): void {
    $home = $onHome ? '' : 'index.php';
    $user = $_SESSION['user'] ?? null;
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> - <?= e(SITE_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600..800&amp;family=Public+Sans:wght@400;600&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/landing.css">
</head>
<body>
<header class="topbar">
  <div class="wrap">
    <a class="brand" href="index.php"><?= e(SITE_NAME) ?></a>
    <nav class="topnav" aria-label="Main">
      <a href="<?= $home ?>#vehicles">Vehicles</a>
      <a href="<?= $home ?>#services">Services</a>
      <a href="<?= $home ?>#how">How it works</a>
      <a href="<?= $home ?>#partners">Partners</a>
    </nav>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
      <a class="btn btn-road" href="admin/dashboard.php">Dashboard</a>
    <?php elseif (($user['role'] ?? '') === 'user'): ?>
      <a class="btn btn-road" href="home.php">My account</a>
    <?php else: ?>
      <a href="register.php" style="text-decoration:none;font-weight:600">Sign up</a>
      <a class="btn btn-road" href="<?= $onHome ? '#login' : 'login.php' ?>">Log in</a>
    <?php endif; ?>
  </div>
</header>
<?php
}

function login_form(string $error = ''): void {
    ?>
<form method="post" action="login.php" class="formcard">
  <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?= csrf_field() ?>
  <label>Email
    <input type="email" name="email" autocomplete="email" required>
  </label>
  <label>Password
    <input type="password" name="password" autocomplete="current-password" required>
  </label>
  <button class="btn btn-road">Log in</button>
  <p style="margin:0;font-size:.9rem">New here? <a href="register.php">Create an account</a></p>
</form>
<?php
}

function public_footer(): void {
    ?>
<footer class="site">
  <div class="wrap">
    <strong><?= e(SITE_NAME) ?></strong>
    <span>Vehicle rentals and local services, approved by a manager in your area.</span>
  </div>
</footer>
</body>
</html>
<?php
}
