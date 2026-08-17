<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(bool $withDatabase = true): PDO
{
    static $pdo = null;
    static $serverPdo = null;

    if ($withDatabase && $pdo instanceof PDO) {
        return $pdo;
    }

    if (!$withDatabase && $serverPdo instanceof PDO) {
        return $serverPdo;
    }

    $dbname = $withDatabase ? ';dbname=' . DB_NAME : '';
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . $dbname . ';charset=utf8mb4';
    $connection = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ($withDatabase) {
        $pdo = $connection;
    } else {
        $serverPdo = $connection;
    }

    return $connection;
}

function database_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM courses LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

