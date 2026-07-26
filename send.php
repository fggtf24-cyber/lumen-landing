<?php
/**
 * Приём заявок и отзывов с сайта Lumen.
 * Отвечает JSON: {"ok":true} или {"ok":false,"error":"..."}
 *
 * Форма шлёт поле `type`:
 *   lead   — заявка: уходит письмом на почту;
 *   review — отзыв:  пишется в БД со статусом pending и ждёт модерации
 *            в /admin. На сайт попадает только после одобрения.
 *
 * Отправка письма устроена так же, как на shorttermtherapy.ru (первый кейс) —
 * код проверен в бою на Beget.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/lib/Smtp.php';
require __DIR__ . '/lib/db.php';

$config = require __DIR__ . '/config.php';

// Сайт и этот скрипт на одном домене — CORS не нужен и заголовок пустой.
// Если сайт останется на GitHub Pages, а скрипт будет на Beget, укажите
// в config.php 'allowed_origin' => 'https://fggtf24-cyber.github.io'.
if (!empty($config['allowed_origin'])) {
    header('Access-Control-Allow-Origin: ' . $config['allowed_origin']);
    header('Vary: Origin');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }
}

function respond(bool $ok, string $error = '', int $status = 200): void
{
    http_response_code($status);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function clientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function writeLog(array $config, string $line): void
{
    if (empty($config['log_file'])) {
        return;
    }
    $dir = dirname($config['log_file']);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($config['log_file'], '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** Ограничение частоты по IP: файл со списком времён отправки. */
function rateLimitOk(array $config): bool
{
    $limit = (int) ($config['rate_limit_per_hour'] ?? 0);
    if ($limit <= 0) {
        return true;
    }

    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return true; // не смогли создать хранилище — не блокируем отправку
    }

    $file = $dir . '/rate_' . sha1(clientIp()) . '.json';
    $now  = time();

    $hits = [];
    if (is_readable($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $hits = $decoded;
        }
    }
    $hits = array_values(array_filter($hits, static fn($t): bool => is_int($t) && $t > $now - 3600));

    if (count($hits) >= $limit) {
        return false;
    }

    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);

    return true;
}

/** Однострочное поле: режем переводы строк, иначе можно подделать заголовки письма. */
function cleanField(string $value, int $maxLength): string
{
    $value = str_replace(["\r", "\n", "\0"], ' ', $value);

    return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $maxLength);
}

/**
 * Отправка письма: сначала SMTP, при неудаче — встроенная mail().
 * Бросает исключение, если не сработало ничего.
 */
function notify(array $config, string $subject, string $body, ?string $replyTo): void
{
    $recipients = (array) $config['to'];

    $smtpConfigured = !empty($config['smtp']['host'])
        && !empty($config['smtp']['user'])
        && $config['smtp']['password'] !== 'ЗАМЕНИТЕ_НА_ПАРОЛЬ';

    if ($smtpConfigured) {
        try {
            (new Smtp($config['smtp']))->send($recipients, $subject, $body, $replyTo);
            return;
        } catch (Throwable $e) {
            writeLog($config, 'Ошибка SMTP: ' . $e->getMessage());
        }
    } else {
        writeLog($config, 'SMTP не настроен, пробуем mail()');
    }

    if (!empty($config['fallback_to_mail_function'])) {
        $from = $config['smtp']['from'] ?: 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');

        $headers = [
            'From: =?UTF-8?B?' . base64_encode((string) $config['smtp']['from_name']) . "?= <$from>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $sent = @mail(
            implode(', ', $recipients),
            '=?UTF-8?B?' . base64_encode($subject) . '?=',
            $body,
            implode("\r\n", $headers)
        );

        if ($sent) {
            return;
        }
    }

    throw new RuntimeException('письмо не ушло ни по SMTP, ни через mail()');
}

/** Многострочный текст (отзыв, описание задачи): переводы строк оставляем. */
function cleanText(string $value, int $maxLength): string
{
    $value = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $value);

    return mb_substr(trim($value), 0, $maxLength);
}

// ---------------------------------------------------------------- обработка

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'method-not-allowed', 405);
}

// Форма шлёт JSON; на всякий случай понимаем и обычный POST.
$raw   = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

// Ловушка для ботов: поле скрыто от людей. Отвечаем «успехом», чтобы бот не искал обход.
if (trim((string) ($input['company'] ?? $input['website'] ?? '')) !== '') {
    writeLog($config, 'Спам-бот отсеян honeypot-полем, IP ' . clientIp());
    respond(true);
}

$type = ($input['type'] ?? 'lead') === 'review' ? 'review' : 'lead';
$name = cleanField((string) ($input['name'] ?? ''), 100);

if (!rateLimitOk($config)) {
    respond(false, 'too-many-requests', 429);
}

if ($type === 'review') {
    $contact = cleanField((string) ($input['contact'] ?? ''), 150);
    $text    = cleanText((string) ($input['text'] ?? ''), 4000);

    if ($name === '' || mb_strlen($text) < 10) {
        respond(false, 'empty-fields', 422);
    }

    // Отзыв живёт в БД. Публикуется только после одобрения в /admin.
    try {
        $stmt = db($config)->prepare(
            'INSERT INTO reviews (name, contact, body, status, created_at, ip)
             VALUES (:name, :contact, :body, \'pending\', NOW(), :ip)'
        );
        $stmt->execute([
            'name'    => $name,
            'contact' => $contact,
            'body'    => $text,
            'ip'      => clientIp(),
        ]);
    } catch (Throwable $e) {
        writeLog($config, 'Не удалось сохранить отзыв: ' . $e->getMessage());
        respond(false, 'storage-failed', 500);
    }

    writeLog($config, "Отзыв сохранён на модерацию: $name");

    // Письмо — только уведомление «есть что модерировать». Не критично:
    // отзыв уже в базе, поэтому ошибка почты не должна валить ответ.
    $subject = 'Новый отзыв на модерацию — сайт Lumen';
    $body = implode("\n", [
        'На сайте оставили отзыв. Он ждёт модерации и на сайте пока не виден.',
        '',
        'Имя:     ' . $name,
        'Контакт: ' . ($contact !== '' ? $contact : '—') . '  (не публикуется)',
        '',
        'Текст:',
        $text,
        '',
        '—',
        'Одобрить или отклонить: /admin',
        'Отправлено: ' . date('d.m.Y H:i'),
    ]);

    try {
        notify($config, $subject, $body, null);
    } catch (Throwable $e) {
        writeLog($config, 'Отзыв сохранён, но уведомление не ушло: ' . $e->getMessage());
    }

    respond(true);
} else {
    $contact = cleanField((string) ($input['contact'] ?? $input['phone'] ?? ''), 150);
    $message = cleanText((string) ($input['message'] ?? ''), 4000);

    // Имя обязательно + хотя бы один способ связи.
    if ($name === '' || $contact === '') {
        respond(false, 'empty-fields', 422);
    }

    $subject = 'Заявка с сайта Lumen';
    $body = implode("\n", [
        'Новая заявка с сайта Lumen.',
        '',
        'Имя:     ' . $name,
        'Контакт: ' . $contact,
        '',
        'Задача:',
        $message !== '' ? $message : '—',
        '',
        '—',
        'Отправлено: ' . date('d.m.Y H:i'),
        'Страница:   ' . cleanField((string) ($_SERVER['HTTP_REFERER'] ?? '—'), 200),
        'IP:         ' . clientIp(),
    ]);
}

// Заявка: письмо — единственный способ её получить, поэтому ошибка отправки
// это ошибка запроса. Если в контакте почта — подставляем Reply-To.
$replyTo = filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : null;
$logLine = "Заявка: $name / $contact";

try {
    notify($config, $subject, $body, $replyTo);
    writeLog($config, "Отправлено. $logLine");
    respond(true);
} catch (Throwable $e) {
    writeLog($config, 'Не отправлено: ' . $e->getMessage());
}

// Письмо не ушло — содержимое всё равно в логе, чтобы ничего не потерять.
writeLog($config, "НЕ ОТПРАВЛЕНО. $logLine\n" . $body);
respond(false, 'send-failed', 500);
