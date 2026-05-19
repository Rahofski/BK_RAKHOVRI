<?php
// ============================================================
// register.php  — only for role=user (self-registration)
// ============================================================
require_once __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . roleHome($_SESSION['role']));
    exit;
}

$errors    = [];
$emailTaken = false;
$data   = [
    'email'     => '',
    'username'  => '',
    'full_name' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data['email']     = trim($_POST['email']     ?? '');
    $data['username']  = trim($_POST['username']  ?? '');
    $data['full_name'] = trim($_POST['full_name'] ?? '');
    $password          =      $_POST['password']  ?? '';
    $password2         =      $_POST['password2'] ?? '';

    // --- Validation ---
    if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Введите корректный email.';
    }
    if ($data['username'] === '' || strlen($data['username']) < 3) {
        $errors[] = 'Имя пользователя должно содержать минимум 3 символа.';
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $data['username'])) {
        $errors[] = 'Имя пользователя может содержать только латинские буквы, цифры и "_".';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Пароль должен содержать минимум 6 символов.';
    }
    if ($password !== $password2) {
        $errors[] = 'Пароли не совпадают.';
    }

    if (empty($errors)) {
        $pdo = getPDO();

        // Unique checks
        $s = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $s->execute([$data['email']]);
        if ($s->fetch()) {
            $errors[]   = 'Этот email уже зарегистрирован.';
            $emailTaken = true;
        }

        $s = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $s->execute([$data['username']]);
        if ($s->fetch()) {
            $errors[] = 'Это имя пользователя уже занято.';
        }
    }

    if (empty($errors)) {
        $pdo = getPDO();

        // Get role_id for 'user'
        $s = $pdo->prepare("SELECT id FROM roles WHERE code = 'user' LIMIT 1");
        $s->execute();
        $roleId = $s->fetchColumn();

        $hash = $password;

        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare(
                'INSERT INTO users (role_id, email, username, password_hash, full_name)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $s->execute([$roleId, $data['email'], $data['username'], $hash,
                         $data['full_name'] ?: null]);
            $userId = (int) $pdo->lastInsertId();

            $s = $pdo->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)');
            $s->execute([$userId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Ошибка регистрации. Попробуйте позже.';
        }

        if (empty($errors)) {
            flash('success', 'Аккаунт создан! Войдите в систему.');
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
}

$pageTitle = 'Регистрация';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <h1 class="auth-title">Регистрация</h1>

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-error">
        <?= htmlspecialchars($e, ENT_QUOTES) ?>
        <?php if ($emailTaken && str_contains($e, 'email')): ?>
          <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary btn-sm alert-cta">Войти в аккаунт</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <form method="post" action="">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label" for="email">Email</label>
        <input
          class="form-control"
          type="email"
          id="email"
          name="email"
          value="<?= htmlspecialchars($data['email'], ENT_QUOTES) ?>"
          autocomplete="email"
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="username">Имя пользователя</label>
        <input
          class="form-control"
          type="text"
          id="username"
          name="username"
          value="<?= htmlspecialchars($data['username'], ENT_QUOTES) ?>"
          autocomplete="username"
          pattern="[A-Za-z0-9_]+"
          minlength="3"
          required
        >
        <p class="form-hint">Только латинские буквы, цифры, "_". Минимум 3 символа.</p>
      </div>

      <div class="form-group">
        <label class="form-label" for="full_name">Полное имя <span class="text-muted">(необязательно)</span></label>
        <input
          class="form-control"
          type="text"
          id="full_name"
          name="full_name"
          value="<?= htmlspecialchars($data['full_name'], ENT_QUOTES) ?>"
          autocomplete="name"
        >
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="password">Пароль</label>
          <input
            class="form-control"
            type="password"
            id="password"
            name="password"
            minlength="6"
            autocomplete="new-password"
            required
          >
        </div>
        <div class="form-group">
          <label class="form-label" for="password2">Повтор пароля</label>
          <input
            class="form-control"
            type="password"
            id="password2"
            name="password2"
            minlength="6"
            autocomplete="new-password"
            required
          >
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block mt-2">Создать аккаунт</button>
    </form>

    <p class="auth-footer">
      Уже есть аккаунт? <a href="<?= BASE_URL ?>/login.php">Войти</a>
    </p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
