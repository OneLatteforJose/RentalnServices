<?php
// Helpers for the single `areas` table (province + city/municipality + barangay).
// A "city" is not its own table: it is every areas row that shares the same province and city_municipality.
// The unique index (province, city_municipality, barangay) already serves lookups by city, so no extra index is needed.

const CITY_SEP = '|';

function city_key(string $province, string $city): string {
    return $province . CITY_SEP . $city;
}

// 'Nueva Vizcaya|Solano' => ['Nueva Vizcaya', 'Solano'], or null when the text is not a valid key.
function parse_city_key(?string $key): ?array {
    $parts = explode(CITY_SEP, (string)$key, 2);
    return (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') ? $parts : null;
}

// SQL condition + parameters that limit a query to one city, using the areas row aliased as $alias.
// An empty or invalid key means "all cities": the returned condition is empty.
function city_filter(?string $key, string $alias = 'a'): array {
    $p = parse_city_key($key);
    return $p ? [" AND $alias.province = ? AND $alias.city_municipality = ?", $p] : ['', []];
}

// Every city that has at least one barangay, for dropdowns: [['key' => ..., 'label' => 'City, Province'], ...]
function city_options(PDO $pdo): array {
    $rows = $pdo->query("SELECT province, city_municipality FROM areas GROUP BY province, city_municipality ORDER BY province, city_municipality")->fetchAll();
    return array_map(fn($r) => [
        'key'   => city_key($r['province'], $r['city_municipality']),
        'label' => $r['city_municipality'] . ', ' . $r['province'],
    ], $rows);
}

// Every barangay with its city key, for the city -> barangay dropdown pair. value = areas.id
function barangay_options(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, province, city_municipality, barangay FROM areas ORDER BY province, city_municipality, barangay")->fetchAll();
    return array_map(fn($r) => [
        'id'   => (int)$r['id'],
        'city' => city_key($r['province'], $r['city_municipality']),
        'name' => $r['barangay'],
    ], $rows);
}

// The city key an areas row belongs to, or null if the row does not exist.
function city_key_of_area(PDO $pdo, int $areaId): ?string {
    $st = $pdo->prepare("SELECT province, city_municipality FROM areas WHERE id = ?");
    $st->execute([$areaId]);
    $r = $st->fetch();
    return $r ? city_key($r['province'], $r['city_municipality']) : null;
}

// Does the city of areas row $areaId already have an active manager (ignoring user $exceptUserId)?
// Call it inside a transaction: it first locks the city's areas rows, so two admins creating
// or activating managers for the same city at the same moment cannot both pass the check.
function manager_conflict(PDO $pdo, int $areaId, int $exceptUserId = 0): bool {
    $lock = $pdo->prepare(
        "SELECT a2.id FROM areas a1
         JOIN areas a2 ON a2.province = a1.province AND a2.city_municipality = a1.city_municipality
         WHERE a1.id = ? FOR UPDATE"
    );
    $lock->execute([$areaId]);
    $lock->fetchAll();
    $st = $pdo->prepare(
        "SELECT 1 FROM users u
         JOIN areas ua ON ua.id = u.area_id
         JOIN areas t  ON t.id = ? AND t.province = ua.province AND t.city_municipality = ua.city_municipality
         WHERE u.role = 'manager' AND u.is_active = 1 AND u.id <> ? LIMIT 1"
    );
    $st->execute([$areaId, $exceptUserId]);
    return (bool)$st->fetchColumn();
}

// How many rows reference this single areas row (an area in use cannot be deleted: the foreign keys are RESTRICT).
function area_usage_sql(string $ref): string {
    return "(SELECT COUNT(*) FROM users WHERE area_id = $ref)
          + (SELECT COUNT(*) FROM vehicles WHERE area_id = $ref)
          + (SELECT COUNT(*) FROM services WHERE area_id = $ref)
          + (SELECT COUNT(*) FROM bookings WHERE area_id = $ref)
          + (SELECT COUNT(*) FROM service_requests WHERE area_id = $ref)";
}

function area_place(?string $barangay, ?string $city, ?string $street = null): string {
    return implode(', ', array_filter([$street, $barangay, $city]));
}
