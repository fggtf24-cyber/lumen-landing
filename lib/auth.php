<?php
/**
 * Сессия администратора, CSRF-токен и экранирование вывода.
 */

declare(strict_types=1);

/** Экранирование всего, что выводится в HTML. Защита от XSS. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,                        // куку не достать из JS
        'samesite' => 'Lax',                       // не улетает на сторонние сайты
        'secure'   => !empty($_SERVER['HTTPS']),   // по HTTPS — только по HTTPS
    ]);
    session_start();
}

function isLoggedIn(): bool
{
    startSession();

    return !empty($_SESSION['admin']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/** Вход выполнен: меняем id сессии, иначе возможна фиксация сессии. */
function logIn(): void
{
    startSession();
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
}

function logOut(): void
{
    startSession();
    $_SESSION = [];
    session_destroy();
}

function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/** Сравнение с защитой от атак по времени. */
function checkCsrf($token): bool
{
    startSession();

    return !empty($_SESSION['csrf'])
        && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}

/**
 * Ограничение попыток входа по IP — защита от перебора пароля.
 * Возвращает false, когда лимит исчерпан.
 */
function loginAttemptOk(int $limit = 10): bool
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return true;
    }

    $file = $dir . '/login_' . sha1($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';
    $now  = time();

    $hits = [];
    if (is_readable($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $hits = $decoded;
        }
    }
    $hits = array_values(array_filter($hits, static fn($t): bool => is_int($t) && $t > $now - 900));

    if (count($hits) >= $limit) {
        return false;
    }

    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);

    return true;
}
