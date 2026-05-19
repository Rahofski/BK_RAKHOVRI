<?php
require_once __DIR__ . '/../bootstrap.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    flash('error', 'Необходима авторизация.');
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

csrf_verify();

$pdo    = getPDO();
$userId = (int) $_SESSION['user_id'];
$amount = (float) ($_POST['amount'] ?? 0);

if ($amount < 1 || $amount > 100000) {
    flash('error', 'Некорректная сумма. Допустимо от 1 до 100 000 VCOIN.');
    header('Location: ' . BASE_URL . '/user/wallet.php');
    exit;
}
$amount = round($amount, 2);

$stmt = $pdo->prepare('SELECT id FROM wallets WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$walletId = $stmt->fetchColumn();

if (!$walletId) {
    flash('error', 'Кошелёк не найден.');
    header('Location: ' . BASE_URL . '/user/wallet.php');
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE wallets SET balance = balance + ? WHERE id = ?'
    )->execute([$amount, $walletId]);

    $pdo->prepare(
        'INSERT INTO wallet_transactions (wallet_id, type, amount, description)
         VALUES (?, \'deposit\', ?, ?)'
    )->execute([$walletId, $amount, 'Пополнение на ' . number_format($amount, 2) . ' VCOIN']);

    $pdo->commit();
    flash('success', 'Кошелёк пополнен на ' . number_format($amount, 2) . ' VCOIN.');
} catch (\Throwable $e) {
    $pdo->rollBack();
    flash('error', 'Ошибка при пополнении. Попробуйте ещё раз.');
}

header('Location: ' . BASE_URL . '/user/wallet.php');
exit;
