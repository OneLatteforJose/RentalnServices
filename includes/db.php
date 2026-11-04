<?php
// Connection settings. Put your own values in config.local.php (copy config.example.php),
// or set the DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS environment variables.
// Nothing here needs editing for a default local XAMPP/Laragon setup.
$local = __DIR__ . '/../config.local.php';
if (is_file($local)) {
    require $local;
}
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_PORT') || define('DB_PORT', getenv('DB_PORT') ?: '3306');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'booking_and_services');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

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
