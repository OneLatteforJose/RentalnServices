<?php
require_once __DIR__ . '/db.php';
session_start();

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid or expired form. Go back and try again.');
    }
}

// Call at the top of every admin page. Re-checks the DB so a deactivated admin is locked out immediately.
// A signed-in customer who opens an admin URL is only redirected: their own session is left alone.
function require_admin(): void {
    $isAdminSession = ($_SESSION['user']['role'] ?? '') === 'admin';
    if ($isAdminSession) {
        $st = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin' AND is_active = 1");
        $st->execute([$_SESSION['user']['id'] ?? 0]);
        if ($st->fetchColumn()) {
            return;
        }
        $_SESSION = [];   // the admin was deactivated or removed
    }
    header('Location: ../login.php');
    exit;
}

function flash(string $msg): void {
    $_SESSION['flash'] = $msg;
}

// Best-effort audit trail. Never put passwords or other secrets in $details.
function log_action(string $action, string $targetType = '', ?int $targetId = null, string $details = ''): void {
    try {
        db()->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)")
            ->execute([$_SESSION['user']['id'] ?? null, $action, $targetType ?: null, $targetId, substr($details, 0, 255)]);
    } catch (Throwable $e) {
        // logging must never break the page
    }
}
