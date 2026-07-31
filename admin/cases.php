<?php
/** Кейсы: добавление, правка, порядок, публикация. */

declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/cases.php';
$config = require __DIR__ . '/../config.php';

requireLogin();

$notice  = '';
$error   = '';
$dbError = '';
$cases   = [];
$edit    = null;

/** Однострочные поля: режем переводы строк и лишние пробелы. */
function field(string $key, int $max): string
{
    $value = (string) ($_POST[$key] ?? '');
    $value = str_replace(["\r", "\n", "\0"], ' ', $value);

    return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $max);
}

try {
    $pdo = db($config);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!checkCsrf($_POST['csrf'] ?? null)) {
            $error = 'Сессия устарела, действие не выполнено. Обновите страницу.';
        } else {
            $action = (string) ($_POST['action'] ?? '');
            $id     = (int) ($_POST['id'] ?? 0);

            if ($action === 'save') {
                $title = field('title', 120);
                $body  = mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['body'] ?? ''))), 0, 2000);
                $url   = field('url', 255);

                if ($url !== '' && !preg_match('~^https?://~i', $url)) {
                    $url = 'https://' . $url;
                }
                if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                    $error = 'Ссылка выглядит неправильно. Пример: https://lumensites.ru';
                }
                if ($title === '' || $body === '') {
                    $error = 'Заголовок и описание обязательны.';
                }

                $data = [
                    'title'        => $title,
                    'tag'          => field('tag', 40),
                    'body'         => $body,
                    'url'          => $url,
                    'link_label'   => field('link_label', 120),
                    'image_alt'    => field('image_alt', 255),
                    'metric_value' => field('metric_value', 40),
                    'metric_label' => field('metric_label', 60),
                    'published'    => empty($_POST['published']) ? 0 : 1,
                ];

                // Картинка необязательна при правке: пустое поле — оставить старую.
                $image = null;
                if ($error === '' && !empty($_FILES['image']['name'])) {
                    try {
                        $image = caseStoreImage($_FILES['image']);
                    } catch (CaseImageException $e) {
                        $error = $e->getMessage();
                    }
                }

                if ($error === '') {
                    if ($id > 0) {
                        $old = $pdo->prepare('SELECT * FROM cases WHERE id = :id');
                        $old->execute(['id' => $id]);
                        $old = $old->fetch();

                        $sql = 'UPDATE cases SET title = :title, tag = :tag, body = :body, url = :url,
                                    link_label = :link_label, image_alt = :image_alt,
                                    metric_value = :metric_value, metric_label = :metric_label,
                                    published = :published';
                        if ($image !== null) {
                            $sql .= ', image = :image, image_webp = :image_webp';
                            $data['image']      = $image['image'];
                            $data['image_webp'] = $image['image_webp'];
                        }
                        $sql .= ' WHERE id = :id';
                        $data['id'] = $id;

                        $pdo->prepare($sql)->execute($data);

                        // Старые файлы удаляем только после успешной записи в базу.
                        if ($image !== null && $old) {
                            caseDeleteImages($old);
                        }
                        $notice = 'Кейс обновлён.';
                    } else {
                        $next = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM cases')->fetchColumn();
                        $data['image']      = $image['image'] ?? '';
                        $data['image_webp'] = $image['image_webp'] ?? '';
                        $data['position']   = $next;

                        $pdo->prepare(
                            'INSERT INTO cases (title, tag, body, url, link_label, image, image_webp,
                                                image_alt, metric_value, metric_label, position, published, created_at)
                             VALUES (:title, :tag, :body, :url, :link_label, :image, :image_webp,
                                     :image_alt, :metric_value, :metric_label, :position, :published, NOW())'
                        )->execute($data);
                        $notice = 'Кейс добавлен.';
                    }
                }
            } elseif ($action === 'delete' && $id > 0) {
                $row = $pdo->prepare('SELECT * FROM cases WHERE id = :id');
                $row->execute(['id' => $id]);
                $row = $row->fetch();

                $pdo->prepare('DELETE FROM cases WHERE id = :id')->execute(['id' => $id]);
                if ($row) {
                    caseDeleteImages($row);
                }
                $notice = 'Кейс удалён.';
            } elseif (($action === 'show' || $action === 'hide') && $id > 0) {
                $pdo->prepare('UPDATE cases SET published = :p WHERE id = :id')
                    ->execute(['p' => $action === 'show' ? 1 : 0, 'id' => $id]);
                $notice = $action === 'show' ? 'Кейс опубликован.' : 'Кейс скрыт с сайта.';
            } elseif (($action === 'up' || $action === 'down') && $id > 0) {
                // Меняем местами с соседом по порядку — так строка не «улетает» в конец.
                $dir  = $action === 'up' ? '<' : '>';
                $sort = $action === 'up' ? 'DESC' : 'ASC';

                $cur = $pdo->prepare('SELECT id, position FROM cases WHERE id = :id');
                $cur->execute(['id' => $id]);
                $cur = $cur->fetch();

                if ($cur) {
                    // Имена плейсхолдеров не повторяются: с EMULATE_PREPARES = false
                    // MySQL не принимает один и тот же :name дважды.
                    $nb = $pdo->prepare(
                        "SELECT id, position FROM cases
                         WHERE position $dir :pos1 OR (position = :pos2 AND id $dir :id)
                         ORDER BY position $sort, id $sort LIMIT 1"
                    );
                    $nb->execute(['pos1' => $cur['position'], 'pos2' => $cur['position'], 'id' => $id]);
                    $nb = $nb->fetch();

                    if ($nb) {
                        $swap = $pdo->prepare('UPDATE cases SET position = :p WHERE id = :id');
                        $swap->execute(['p' => (int) $nb['position'], 'id' => (int) $cur['id']]);
                        $swap->execute(['p' => (int) $cur['position'], 'id' => (int) $nb['id']]);
                        // Одинаковые position ломают перестановку — разводим их.
                        if ((int) $nb['position'] === (int) $cur['position']) {
                            $pdo->exec('SET @i := 0');
                            $pdo->exec('UPDATE cases SET position = (@i := @i + 1) ORDER BY position ASC, id ASC');
                        }
                        $notice = 'Порядок изменён.';
                    }
                }
            }
        }
    }

    // Правим конкретный кейс, если пришли по ссылке «Изменить».
    $editId = (int) ($_GET['edit'] ?? 0);
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM cases WHERE id = :id');
        $stmt->execute(['id' => $editId]);
        $edit = $stmt->fetch() ?: null;
    }

    $cases = casesAll($pdo);
} catch (Throwable $e) {
    error_log('Админка (кейсы): ошибка БД — ' . $e->getMessage());
    $dbError = 'База данных недоступна. Проверьте настройки в config.php и что выполнен schema-cases.sql.';
}

$csrf = csrfToken();
$form = $edit ?? [
    'id' => 0, 'title' => '', 'tag' => '', 'body' => '', 'url' => '', 'link_label' => '',
    'image' => '', 'image_webp' => '', 'image_alt' => '', 'metric_value' => '',
    'metric_label' => '', 'published' => 1,
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Кейсы — админка Lumen</title>
<link rel="stylesheet" href="admin.css">
</head>
<body class="admin">
  <header class="topbar">
    <strong>Кейсы</strong>
    <nav class="tabs">
      <a href="index.php">Отзывы</a>
      <a class="is-active" href="cases.php">Кейсы <span class="count"><?= count($cases) ?></span></a>
    </nav>
    <a class="logout" href="logout.php">Выйти</a>
  </header>

  <main class="wrap">
    <?php if ($dbError !== ''): ?>
      <p class="msg msg--error"><?= e($dbError) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <p class="msg msg--error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
      <p class="msg"><?= e($notice) ?></p>
    <?php endif; ?>

    <form class="panel case-form" method="post" enctype="multipart/form-data">
      <h2><?= $edit ? 'Изменить кейс' : 'Новый кейс' ?></h2>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

      <label for="title">Заголовок</label>
      <input id="title" name="title" maxlength="120" required value="<?= e($form['title']) ?>"
             placeholder="Терапевтическая группа">

      <label for="tag">Плашка над заголовком</label>
      <input id="tag" name="tag" maxlength="40" value="<?= e($form['tag']) ?>"
             placeholder="Лендинг">

      <label for="body">Описание</label>
      <textarea id="body" name="body" rows="4" required
                placeholder="Что делали, чем закончилось."><?= e($form['body']) ?></textarea>

      <div class="case-form__row">
        <div>
          <label for="url">Ссылка на сайт</label>
          <input id="url" name="url" maxlength="255" value="<?= e($form['url']) ?>"
                 placeholder="https://lumensites.ru">
        </div>
        <div>
          <label for="link_label">Подпись ссылки</label>
          <input id="link_label" name="link_label" maxlength="120" value="<?= e($form['link_label']) ?>"
                 placeholder="lumensites.ru">
        </div>
      </div>

      <div class="case-form__row">
        <div>
          <label for="metric_value">Цифра результата</label>
          <input id="metric_value" name="metric_value" maxlength="40" value="<?= e($form['metric_value']) ?>"
                 placeholder="−88%">
        </div>
        <div>
          <label for="metric_label">Подпись к цифре</label>
          <input id="metric_label" name="metric_label" maxlength="60" value="<?= e($form['metric_label']) ?>"
                 placeholder="вес изображений">
        </div>
      </div>

      <label for="image">Картинка<?= $edit ? ' — новая заменит текущую' : '' ?></label>
      <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp"<?= $edit ? '' : ' required' ?>>
      <p class="hint">JPEG, PNG или WebP до 6 МБ. Ширину больше 1600 px уменьшим сами,
        заодно сделаем webp-версию — карточка будет грузиться легче.</p>

      <?php if (!empty($form['image'])): ?>
        <img class="case-form__preview" src="../<?= e(caseImageUrl($form['image'])) ?>" alt="">
      <?php endif; ?>

      <label for="image_alt">Описание картинки</label>
      <input id="image_alt" name="image_alt" maxlength="255" value="<?= e($form['image_alt']) ?>"
             placeholder="Первый экран сайта с фотографией ведущих">
      <p class="hint">Видно незрячим посетителям и поисковикам, если картинка не загрузилась.</p>

      <label class="checkbox">
        <input type="checkbox" name="published" value="1"<?= empty($form['published']) ? '' : ' checked' ?>>
        Показывать на сайте
      </label>

      <div class="case-form__actions">
        <button class="ok" type="submit"><?= $edit ? 'Сохранить' : 'Добавить кейс' ?></button>
        <?php if ($edit): ?>
          <a class="cancel" href="cases.php">Отменить</a>
        <?php endif; ?>
      </div>
    </form>

    <?php if (!$cases && $dbError === ''): ?>
      <p class="empty">Кейсов пока нет — добавьте первый.</p>
    <?php endif; ?>

    <?php foreach ($cases as $i => $c): ?>
      <article class="panel case-row<?= $c['published'] ? '' : ' is-hidden' ?>">
        <?php if (!empty($c['image'])): ?>
          <img class="case-row__thumb" src="../<?= e(caseImageUrl($c['image'])) ?>" alt="" loading="lazy">
        <?php else: ?>
          <div class="case-row__thumb case-row__thumb--empty">без картинки</div>
        <?php endif; ?>

        <div class="case-row__body">
          <div class="case-row__meta">
            <strong><?= e($c['title']) ?></strong>
            <?php if ($c['tag'] !== ''): ?><span class="contact"><?= e($c['tag']) ?></span><?php endif; ?>
            <?php if (!$c['published']): ?><span class="badge">скрыт</span><?php endif; ?>
          </div>
          <p class="case-row__text"><?= nl2br(e($c['body'])) ?></p>
          <?php if ($c['url'] !== ''): ?>
            <a class="case-row__link" href="<?= e($c['url']) ?>" target="_blank" rel="noopener nofollow">
              <?= e($c['link_label'] !== '' ? $c['link_label'] : $c['url']) ?> →
            </a>
          <?php endif; ?>

          <form class="review__actions" method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <a class="btn-link" href="?edit=<?= (int) $c['id'] ?>">Изменить</a>
            <?php if ($c['published']): ?>
              <button name="action" value="hide">Скрыть</button>
            <?php else: ?>
              <button name="action" value="show" class="ok">Опубликовать</button>
            <?php endif; ?>
            <button name="action" value="up"<?= $i === 0 ? ' disabled' : '' ?>>Выше</button>
            <button name="action" value="down"<?= $i === count($cases) - 1 ? ' disabled' : '' ?>>Ниже</button>
            <button name="action" value="delete" class="no"
                    onclick="return confirm('Удалить кейс вместе с картинкой? Это навсегда.')">Удалить</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </main>
</body>
</html>
