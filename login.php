<?php
// ============================================================
// login.php
// ============================================================
require_once __DIR__ . '/bootstrap.php';

// Already logged in → redirect home
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . roleHome($_SESSION['role']));
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $login    = trim($_POST['login']    ?? '');
    $password =      $_POST['password'] ?? '';

    if ($login === '') {
        $errors[] = 'Введите логин или email.';
    }
    if ($password === '') {
        $errors[] = 'Введите пароль.';
    }

    if (empty($errors)) {
        $stmt = getPDO()->prepare(
            'SELECT u.*, r.code AS role_code
               FROM users u
               JOIN roles r ON u.role_id = r.id
              WHERE (u.email = ? OR u.username = ?)
                AND u.status != \'deleted\'
              LIMIT 1'
        );
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if (!$user || $password !== $user['password_hash']) {
            $errors[] = 'Неверный логин или пароль.';
        } elseif ($user['status'] === 'blocked') {
            $errors[] = 'Ваш аккаунт заблокирован. Обратитесь к администратору.';
        } else {
            // Auth OK — write session
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role']    = $user['role_code'];

            // Remember me cookie (30 days)
            if (!empty($_POST['remember'])) {
                setcookie('remember_uid', $user['id'], time() + 30 * 86400, '/', '', false, true);
            }

            header('Location: ' . roleHome($user['role_code']));
            exit;
        }
    }
}

$pageTitle = 'Вход';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <h1 class="auth-title"><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></h1>

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-error"><?= htmlspecialchars($e, ENT_QUOTES) ?></div>
    <?php endforeach; ?>

    <form method="post" action="">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label" for="login">Логин или Email</label>
        <input
          class="form-control"
          type="text"
          id="login"
          name="login"
          value="<?= htmlspecialchars($_POST['login'] ?? '', ENT_QUOTES) ?>"
          autocomplete="username"
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Пароль</label>
        <input
          class="form-control"
          type="password"
          id="password"
          name="password"
          autocomplete="current-password"
          required
        >
      </div>

      <div class="form-group" style="display:flex;align-items:center;gap:.5rem;">
        <input type="checkbox" id="remember" name="remember" value="1"
               style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer;"
               <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
        <label for="remember" style="margin:0;cursor:pointer;">Запомнить меня на 30 дней</label>
      </div>

      <button type="submit" class="btn btn-primary btn-block mt-2">Войти</button>
    </form>

    <p class="auth-footer">
      Нет аккаунта? <a href="<?= BASE_URL ?>/register.php">Зарегистрироваться</a>
    </p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
