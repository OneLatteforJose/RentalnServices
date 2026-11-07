<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$cp = city_params($m);
$err = '';

// The recommendations table comes from migrations/001_provider_recommendations.sql
$hasRecs = true;
try {
    $pdo->query("SELECT 1 FROM provider_recommendations LIMIT 1");
} catch (PDOException $ex) {
    $hasRecs = false;
}

if ($hasRecs && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name   = trim($_POST['full_name'] ?? '');
    $biz    = trim($_POST['business_name'] ?? '') ?: null;
    $phone  = trim($_POST['phone'] ?? '') ?: null;
    $email  = trim($_POST['email'] ?? '') ?: null;
    $offers = trim($_POST['offers'] ?? '') ?: null;
    $notes  = trim($_POST['notes'] ?? '') ?: null;

    if ($name === '' || strlen($name) > 150) {
        $err = 'Enter the provider\'s name.';
    } elseif ($biz !== null && strlen($biz) > 150) {
        $err = 'The business name is too long.';
    } elseif ($phone !== null && !preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
        $err = 'Enter a valid phone number, or leave it empty.';
    } elseif ($email !== null && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) {
        $err = 'Enter a valid email address, or leave it empty.';
    } elseif ($phone === null && $email === null) {
        $err = 'Give a phone number or an email so the admin can reach them.';
    } elseif ($offers !== null && strlen($offers) > 255) {
        $err = '"What they offer" is too long (255 characters at most).';
    } elseif ($notes !== null && strlen($notes) > 5000) {
        $err = 'The notes are too long.';
    } else {
        $pdo->prepare("INSERT INTO provider_recommendations (manager_id, full_name, business_name, phone, email, offers, notes) VALUES (?,?,?,?,?,?,?)")
            ->execute([$m['id'], $name, $biz, $phone, $email, $offers, $notes]);
        log_action('provider.recommend', 'recommendation', (int)$pdo->lastInsertId(), "Recommended provider $name");
        flash('Thank you. The admin will review your recommendation.');
        header('Location: providers.php');
        exit;
    }
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN areas a ON a.id = u.area_id WHERE u.role = 'provider' AND a.province = ? AND a.city_municipality = ?");
$cnt->execute($cp);
$pg = paginate((int)$cnt->fetchColumn(), 25);
$st = $pdo->prepare(
    "SELECT u.full_name, pp.business_name, u.phone, a.barangay, rm.full_name AS recommended_by,
            (SELECT COUNT(*) FROM vehicles v WHERE v.provider_id = u.id) AS vehicles,
            (SELECT COUNT(*) FROM services s WHERE s.provider_id = u.id) AS services,
            IF(u.is_active, 'Active', 'Deactivated') AS status
     FROM users u JOIN areas a ON a.id = u.area_id
     LEFT JOIN provider_profiles pp ON pp.user_id = u.id LEFT JOIN users rm ON rm.id = pp.recommended_by
     WHERE u.role = 'provider' AND a.province = ? AND a.city_municipality = ? ORDER BY u.full_name LIMIT {$pg['per']} OFFSET {$pg['offset']}"
);
$st->execute($cp);
$providers = $st->fetchAll();

$mine = [];
if ($hasRecs) {
    $st = $pdo->prepare("SELECT full_name, business_name, offers, status, created_at FROM provider_recommendations WHERE manager_id = ? ORDER BY id DESC LIMIT 20");
    $st->execute([$m['id']]);
    $mine = $st->fetchAll();
    foreach ($mine as &$r) { $r['status'] = ucfirst($r['status']); $r['created_at'] = substr($r['created_at'], 0, 10); }
    unset($r);
}

manager_header('Providers', 'providers.php', $m);
if ($err) echo '<p class="err">' . e($err) . '</p>';
echo '<p class="muted">Providers in ' . e($m['city']) . '. The admin creates provider accounts. You can recommend someone for the admin to add.</p>';
render_table(
    ['full_name' => 'Provider', 'business_name' => 'Business', 'phone' => 'Phone', 'barangay' => 'Barangay', 'vehicles' => 'Vehicles',
     'services' => 'Services', 'recommended_by' => 'Recommended by', 'status' => 'Status'],
    $providers
);
render_pager($pg);

echo '<h2>Recommend a provider</h2>';
if (!$hasRecs) {
    echo '<p class="err">Recommendations are not set up yet. Ask the admin to run <code>migrations/001_provider_recommendations.sql</code> on the database.</p>';
} else { ?>
<form method="post" class="box">
  <?= csrf_field() ?>
  <label>Full name <input name="full_name" required maxlength="150" value="<?= e($_POST['full_name'] ?? '') ?>"></label>
  <label>Business name (optional) <input name="business_name" maxlength="150" value="<?= e($_POST['business_name'] ?? '') ?>"></label>
  <label>Phone <input type="tel" name="phone" maxlength="30" value="<?= e($_POST['phone'] ?? '') ?>"></label>
  <label>Email <input type="email" name="email" maxlength="150" value="<?= e($_POST['email'] ?? '') ?>"></label>
  <label style="grid-column:1/-1">What they offer <input name="offers" maxlength="255" placeholder="e.g. 2 vans with driver, plumbing and electrical" value="<?= e($_POST['offers'] ?? '') ?>"></label>
  <label style="grid-column:1/-1">Notes for the admin (optional) <textarea name="notes" rows="3"><?= e($_POST['notes'] ?? '') ?></textarea></label>
  <label>&nbsp;<button>Send recommendation</button></label>
</form>
<?php
    if ($mine) {
        echo '<h2>My recommendations</h2>';
        render_table(['created_at' => 'Sent', 'full_name' => 'Name', 'business_name' => 'Business', 'offers' => 'Offers', 'status' => 'Status'], $mine);
    }
}
page_footer();
