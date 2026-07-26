<?php
/**
 * Подключение к MySQL. Одно соединение на запрос.
 */

declare(strict_types=1);

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = $config['db'];
    $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        // ошибки — исключениями, иначе они молча теряются
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // настоящие подготовленные запросы на стороне MySQL, не эмуляция
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
