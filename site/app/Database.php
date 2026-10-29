<?php
declare(strict_types=1);

function db(bool $reconnect = false): PDO
{
    static $pdo = null;
    if ($reconnect) {
        $pdo = null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = $GLOBALS['bmn_config']['db'];
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['name']);
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
