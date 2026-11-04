<?php
// Connection settings. Put your own values in config.local.php (copy config.example.php),
// or set the DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS environment variables.
// Nothing here needs editing for a default local XAMPP/Laragon setup.
$local = __DIR__ . '/../config.local.php';
if (is_file($local)) {
    require $local;
}
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'booking_and_services');
define('DB_USER', 'root');
define('DB_PASS', '');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,   // real prepared statements: proper types, no quoting tricks
            ]
        );
    }
    return $pdo;
}
