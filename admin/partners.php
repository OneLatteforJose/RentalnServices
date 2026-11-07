<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_admin();
$pdo = db();

$mp = paginate((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager'")->fetchColumn(), 25, 'mpage');
$pp = paginate((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'provider'")->fetchColumn(), 25, 'ppage');

$providers = $pdo->query(
    "SELECT u.full_name, pp.business_name, m.full_name AS recommended_by,
            a.city_municipality AS city, a.barangay,
            (SELECT COUNT(*) FROM vehicles v WHERE v.provider_id = u.id) AS vehicles,
            (SELECT COUNT(*) FROM services s WHERE s.provider_id = u.id) AS services,
            IF(u.is_active, 'Active', 'Deactivated') AS status
     FROM users u
     LEFT JOIN provider_profiles pp ON pp.user_id = u.id
     LEFT JOIN users m ON m.id = pp.recommended_by
     LEFT JOIN areas a ON a.id = u.area_id
     WHERE u.role = 'provider' ORDER BY a.city_municipality, u.full_name
     LIMIT {$pp['per']} OFFSET {$pp['offset']}"
)->fetchAll();

// "Same city" = same province + city_municipality in areas. Counted only for the page being shown.
$managers = $pdo->query(
    "SELECT u.full_name, a.city_municipality AS city, a.province,
            (SELECT COUNT(*) FROM users p JOIN areas pa ON pa.id = p.area_id
              WHERE p.role = 'provider' AND pa.province = a.province AND pa.city_municipality = a.city_municipality) AS providers,
            (SELECT COUNT(*) FROM provider_profiles pr WHERE pr.recommended_by = u.id) AS recommended,
            (SELECT COUNT(*) FROM bookings bk JOIN areas ba ON ba.id = bk.area_id
              WHERE bk.status = 'pending' AND ba.province = a.province AND ba.city_municipality = a.city_municipality)
          + (SELECT COUNT(*) FROM service_requests r JOIN areas ra ON ra.id = r.area_id
              WHERE r.status = 'pending' AND ra.province = a.province AND ra.city_municipality = a.city_municipality) AS pending,
            IF(u.is_active, 'Active', 'Deactivated') AS status
     FROM users u LEFT JOIN areas a ON a.id = u.area_id
     WHERE u.role = 'manager' ORDER BY a.province, a.city_municipality
     LIMIT {$mp['per']} OFFSET {$mp['offset']}"
)->fetchAll();

page_header('Partnerships', 'partners.php', ['actions' => '<a class="btn-soft" href="recommendations.php">Recommendations</a>']);
echo '<h2>Managers</h2>';
render_table(
    ['full_name' => 'Manager', 'city' => 'City / Municipality', 'province' => 'Province', 'providers' => 'Providers in city',
     'recommended' => 'Providers recommended', 'pending' => 'Pending approvals', 'status' => 'Status'],
    $managers
);
render_pager($mp);
echo '<h2>Providers</h2>';
render_table(
    ['full_name' => 'Provider', 'business_name' => 'Business', 'city' => 'City / Municipality', 'barangay' => 'Barangay',
     'recommended_by' => 'Recommended by', 'vehicles' => 'Vehicles', 'services' => 'Services', 'status' => 'Status'],
    $providers
);
render_pager($pp);
page_footer();
