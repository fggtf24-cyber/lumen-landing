<?php
/** Вход в админку. */

declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';
$config = require __DIR__ . '/../config.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Сессия устарела, попробуйте ещё раз.';
    } elseif (!loginAttemptOk()) {
        $error = 'Слишком много попыток. Подождите 15 минут.';
    } elseif (password_verify((string) ($_POST['password'] ?? ''), (string) $config['admin_password_hash'])) {
        logIn();
        header('Location: index.php');
        exit;
    } else {
        $error = 'Неверный пароль.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Вход — админка Lumen</title>
<link rel="stylesheet" href="admin.css">
</head>
<body class="admin admin--center">
  <form class="panel panel--narrow" method="post">
    <h1>Админка Lumen</h1>
    <?php if ($error !== ''): ?>
      <p class="msg msg--error"><?= e($error) ?></p>
    <?php endif; ?>
    <label for="password">Пароль</label>
    <input id="password" name="password" type="password" required autofocus autocomplete="current-password">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <button type="submit">Войти</button>
  </form>
</body>
</html>
