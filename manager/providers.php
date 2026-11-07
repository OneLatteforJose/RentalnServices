<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
[$cond, $params] = city_filter($m['key'], 'a');

$q = trim($_GET['q'] ?? '');
$where = " WHERE u.role = 'provider'$cond";
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';   // so a typed % or _ is searched literally
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR pp.business_name LIKE ?)";
    array_push($params, $like, $like, $like);
}
$base = "FROM users u JOIN areas a ON a.id = u.area_id LEFT JOIN provider_profiles pp ON pp.user_id = u.id LEFT JOIN users rec ON rec.id = pp.recommended_by";
$pg = paginate((int)db_value($pdo, "SELECT COUNT(*) $base$where", $params), 50);
$st = $pdo->prepare(
    "SELECT u.full_name, pp.business_name, u.email, u.phone, a.barangay, rec.full_name AS recommended_by,
            (SELECT COUNT(*) FROM vehicles v WHERE v.provider_id = u.id) AS vehicles,
            (SELECT COUNT(*) FROM services s WHERE s.provider_id = u.id) AS services,
            IF(u.is_active, 'Active', 'Deactivated') AS status
     $base$where ORDER BY u.full_name LIMIT {$pg['per']} OFFSET {$pg['offset']}"
);
$st->execute($params);

manager_header('Providers', 'providers.php');
?>
<p class="muted">Providers who live in <?= e($m['city']) ?>. An admin creates and deactivates provider accounts; you add and manage their vehicles and services.</p>
<form method="get" class="box">
  <label>Search name, email or business <input name="q" value="<?= e($q) ?>"></label>
  <label>&nbsp;<button>Search</button></label>
</form>
<?php
render_table(['full_name' => 'Provider', 'business_name' => 'Business', 'email' => 'Email', 'phone' => 'Phone', 'barangay' => 'Barangay',
              'recommended_by' => 'Recommended by', 'vehicles' => 'Vehicles', 'services' => 'Services', 'status' => 'Status'], $st->fetchAll());
render_pager($pg);
page_footer();
