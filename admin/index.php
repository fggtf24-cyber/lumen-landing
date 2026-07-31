<?php
/** Модерация отзывов: очередь, одобрение, отклонение. */

declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/db.php';
$config = require __DIR__ . '/../config.php';

requireLogin();

$notice  = '';
$dbError = '';

$allowed = ['pending', 'approved', 'rejected'];
$status  = (string) ($_GET['status'] ?? 'pending');
if (!in_array($status, $allowed, true)) {
    $status = 'pending';
}

$reviews = [];
$counts  = [];

// Всё общение с базой — под одним catch: наружу отдаём короткое сообщение,
// подробности только в лог сервера. Стек и SQL пользователю не показываем.
try {
    // --- действие модератора ---
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!checkCsrf($_POST['csrf'] ?? null)) {
            $notice = 'Сессия устарела, действие не выполнено.';
        } else {
            $id     = (int) ($_POST['id'] ?? 0);
            $action = (string) ($_POST['action'] ?? '');
            $map    = ['approve' => 'approved', 'reject' => 'rejected', 'unpublish' => 'pending'];

            if ($id > 0 && isset($map[$action])) {
                $stmt = db($config)->prepare(
                    'UPDATE reviews SET status = :status, moderated_at = NOW() WHERE id = :id'
                );
                $stmt->execute(['status' => $map[$action], 'id' => $id]);
                $notice = 'Готово.';
            }
        }
    }

    // --- список ---
    $stmt = db($config)->prepare(
        'SELECT id, name, contact, body, status, created_at, moderated_at
         FROM reviews WHERE status = :status
         ORDER BY created_at DESC LIMIT 200'
    );
    $stmt->execute(['status' => $status]);
    $reviews = $stmt->fetchAll();

    foreach (db($config)->query('SELECT status, COUNT(*) AS n FROM reviews GROUP BY status') as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
} catch (Throwable $e) {
    error_log('Админка: ошибка БД — ' . $e->getMessage());
    $dbError = 'База данных недоступна. Проверьте настройки в config.php и что выполнен schema.sql.';
}

$titles = ['pending' => 'На модерации', 'approved' => 'Опубликованные', 'rejected' => 'Отклонённые'];
$csrf   = csrfToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Отзывы — админка Lumen</title>
<link rel="stylesheet" href="admin.css">
</head>
<body class="admin">
  <header class="topbar">
    <strong>Отзывы</strong>
    <nav class="tabs">
      <?php foreach ($titles as $key => $title): ?>
        <a class="<?= $key === $status ? 'is-active' : '' ?>" href="?status=<?= e($key) ?>">
          <?= e($title) ?> <span class="count"><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
      <a class="tabs__section" href="cases.php">Кейсы →</a>
    </nav>
    <a class="logout" href="logout.php">Выйти</a>
  </header>

  <main class="wrap">
    <?php if ($dbError !== ''): ?>
      <p class="msg msg--error"><?= e($dbError) ?></p>
    <?php endif; ?>

    <?php if ($notice !== ''): ?>
      <p class="msg"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if (!$reviews && $dbError === ''): ?>
      <p class="empty">Здесь пусто.</p>
    <?php endif; ?>

    <?php foreach ($reviews as $r): ?>
      <article class="panel review">
        <div class="review__meta">
          <strong><?= e($r['name']) ?></strong>
          <?php if ($r['contact'] !== ''): ?>
            <span class="contact"><?= e($r['contact']) ?></span>
          <?php endif; ?>
          <time><?= e(date('d.m.Y H:i', strtotime((string) $r['created_at']))) ?></time>
        </div>

        <p class="review__body"><?= nl2br(e($r['body'])) ?></p>

        <form class="review__actions" method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <?php if ($r['status'] !== 'approved'): ?>
            <button name="action" value="approve" class="ok">Опубликовать</button>
          <?php endif; ?>
          <?php if ($r['status'] !== 'rejected'): ?>
            <button name="action" value="reject" class="no">Отклонить</button>
          <?php endif; ?>
          <?php if ($r['status'] !== 'pending'): ?>
            <button name="action" value="unpublish">Вернуть на модерацию</button>
          <?php endif; ?>
        </form>
      </article>
    <?php endforeach; ?>
  </main>
</body>
</html>
