<?php
// ============================================================
// includes/header.php
// Requires: bootstrap.php already loaded by the calling page.
// Variables expected: $pageTitle (string)
// ============================================================

$flash   = getFlash();
$curUser = isset($_SESSION['user_id']) ? currentUser() : null;
$role    = $curUser['role_code'] ?? '';
$pageTitleFull = ($pageTitle ?? 'Главная') . ' — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitleFull, ENT_QUOTES) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/layout.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/components.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/auth.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/events.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/wallet.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/pages.css">
</head>
<body>

<header class="site-header">
  <div class="header-inner">
    <a class="site-logo" href="<?= BASE_URL ?>/index.php"><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></a>

    <nav class="site-nav">
      <?php if ($curUser): ?>

        <?php if ($role === 'user'): ?>
          <a href="<?= BASE_URL ?>/user/index.php">События</a>
          <a href="<?= BASE_URL ?>/user/bets.php">Мои ставки</a>
          <a href="<?= BASE_URL ?>/user/wallet.php">Кошелёк</a>

        <?php elseif ($role === 'bookmaker'): ?>
          <a href="<?= BASE_URL ?>/bookmaker/index.php">Мои события</a>
          <a href="<?= BASE_URL ?>/bookmaker/event_create.php">+ Событие</a>

        <?php elseif ($role === 'analyst'): ?>
          <a href="<?= BASE_URL ?>/analyst/index.php">Обзор</a>
          <a href="<?= BASE_URL ?>/analyst/events.php">Риск по событиям</a>
          <a href="<?= BASE_URL ?>/analyst/bets.php">Все ставки</a>

        <?php elseif ($role === 'admin'): ?>
          <a href="<?= BASE_URL ?>/admin/index.php">Дашборд</a>
          <a href="<?= BASE_URL ?>/admin/users.php">Пользователи</a>
          <a href="<?= BASE_URL ?>/admin/sports.php">Виды спорта</a>
          <a href="<?= BASE_URL ?>/admin/teams.php">Команды</a>
          <a href="<?= BASE_URL ?>/admin/markets.php">Рынки</a>
        <?php endif; ?>

        <span class="nav-divider"></span>
        <span class="nav-user">
          <?= htmlspecialchars($curUser['username'], ENT_QUOTES) ?>
          <span class="badge badge-role"><?= htmlspecialchars($curUser['role_name'], ENT_QUOTES) ?></span>
        </span>
        <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline">Выйти</a>

      <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php">Войти</a>
        <a href="<?= BASE_URL ?>/register.php" class="btn btn-sm btn-primary">Регистрация</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="site-main">
  <div class="container">

    <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES) ?>">
        <?= htmlspecialchars($flash['msg'], ENT_QUOTES) ?>
      </div>
    <?php endif; ?>
