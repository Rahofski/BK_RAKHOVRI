<?php
// ============================================================
// bootstrap.php  —  Loaded by every page before any output
// ============================================================

require_once __DIR__ . '/config.php';

// Error display based on environment
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    error_reporting(0);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- Remember Me: auto-login via cookie ----
if (empty($_SESSION['user_id']) && !empty($_COOKIE['remember_uid'])) {
    $rememberedId = (int) $_COOKIE['remember_uid'];
    if ($rememberedId > 0) {
        try {
            $stmt = getPDO()->prepare(
                'SELECT u.id, r.code AS role_code
                   FROM users u
                   JOIN roles r ON u.role_id = r.id
                  WHERE u.id = ? AND u.status = \'active\'
                  LIMIT 1'
            );
            $stmt->execute([$rememberedId]);
            $remembered = $stmt->fetch();
            if ($remembered) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $remembered['id'];
                $_SESSION['role']    = $remembered['role_code'];
            } else {
                setcookie('remember_uid', '', time() - 3600, '/', '', false, true);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
