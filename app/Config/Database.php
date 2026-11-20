<?php

namespace App\Config;

use PDO;

class Database
{
    protected static ?PDO $pdo = null;
    protected static array $config = [];

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $cfg = self::resolveConfig();
        $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $cfg['database'] . ';charset=' . $cfg['charset'];
        $options = $cfg['options'] ?? [];
        if (!isset($options[PDO::ATTR_ERRMODE])) {
            $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        }
        if (!isset($options[PDO::ATTR_DEFAULT_FETCH_MODE])) {
            $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
        }
        if (!isset($options[PDO::ATTR_EMULATE_PREPARES])) {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }
        self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], $options);
        // O banco usa o mesmo fuso do PHP (Brasília): NOW() no SQL e now() no PHP dão a mesma hora
        try { self::$pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (\Throwable $e) {}
        return self::$pdo;
    }

    protected static function resolveConfig(): array
    {
        $cfg = self::$config;
        $cfg['host'] = $cfg['host'] ?? self::env('DB_HOST', 'localhost');
        $cfg['port'] = (string)($cfg['port'] ?? self::env('DB_PORT', '3306'));
        $cfg['database'] = $cfg['database'] ?? self::env('DB_DATABASE', '');
        $cfg['username'] = $cfg['username'] ?? self::env('DB_USERNAME', '');
        $cfg['password'] = $cfg['password'] ?? self::env('DB_PASSWORD', '');
        $cfg['charset'] = $cfg['charset'] ?? self::env('DB_CHARSET', 'utf8mb4');
        return $cfg;
    }

    protected static function env(string $key, $default = null)
    {
        $v = getenv($key);
        if ($v !== false) {
            return $v;
        }
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        return $default;
    }

    public static function testConnection(): bool
    {
        try {
            $pdo = self::connection();
            $pdo->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }
}