<?php
// ============================================================
// includes/csrf.php  —  CSRF Token Helpers
// ============================================================

/**
 * Generate (or return existing) CSRF token for the session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden CSRF input field.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Verify the CSRF token submitted via POST.
 * Terminates with 403 on failure.
 */
function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Неверный CSRF-токен. Обновите страницу и попробуйте снова.');
    }
}
