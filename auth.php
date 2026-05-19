<?php
// ============================================================
// auth.php  —  Session & Role Helpers
// ============================================================

/**
 * Redirect to login if the user is not authenticated.
 */
function requireAuth(): void
{
    if (empty($_SESSION['user_id'])) {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Необходима авторизация.'];
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

/**
 * Require the authenticated user to have one of the given roles.
 *
 * @param string[] $roles  Allowed role codes, e.g. ['admin', 'bookmaker']
 */
function requireRole(array $roles): void
{
    requireAuth();

    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Доступ запрещён.'];
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

/**
 * Return the current user row (with role_code, role_name) or null.
 */
function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;

    if ($user === null) {
        $stmt = getPDO()->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
               FROM users u
               JOIN roles r ON u.role_id = r.id
              WHERE u.id = ?
                AND u.status = \'active\'
              LIMIT 1'
        );
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;

        // If the user was blocked/deleted while logged in — force logout
        if ($user === null) {
            session_destroy();
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }

    return $user;
}

/**
 * Store a one-time flash message.
 */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

/**
 * Consume and return the stored flash message (or null).
 */
function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/**
 * Return the URL of the default landing page for a given role code.
 */
function roleHome(string $role): string
{
    return match ($role) {
        'user'      => BASE_URL . '/user/index.php',
        'bookmaker' => BASE_URL . '/bookmaker/index.php',
        'analyst'   => BASE_URL . '/analyst/index.php',
        'admin'     => BASE_URL . '/admin/index.php',
        default     => BASE_URL . '/login.php',
    };
}
