<?php
// ============================================================
// logout.php
// ============================================================
require_once __DIR__ . '/bootstrap.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

// Clear remember-me cookie
setcookie('remember_uid', '', time() - 3600, '/', '', false, true);

session_destroy();

header('Location: ' . BASE_URL . '/login.php');
exit;
