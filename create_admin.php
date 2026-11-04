<?php
// Run once from the command line:  php create_admin.php "Full Name" email@example.com "a-strong-password"
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require_once __DIR__ . '/includes/db.php';

[, $name, $email, $password] = $argv + [null, null, null, null];
if (!$name || !$email || !$password || strlen($password) < 8) {
    exit("Usage: php create_admin.php \"Full Name\" email password(min 8 chars)\n");
}
db()->prepare("INSERT INTO users (role, full_name, email, password_hash) VALUES ('admin', ?, ?, ?)")
    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
echo "Admin created: $email\n";
