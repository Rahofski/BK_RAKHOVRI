<?php
// ============================================================
// index.php  —  Entry point: redirect by auth/role
// ============================================================
require_once __DIR__ . '/bootstrap.php';

echo '<pre>';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . roleHome($_SESSION['role'] ?? ''));
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
