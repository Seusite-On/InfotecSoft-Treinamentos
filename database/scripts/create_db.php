<?php
/**
 * Cria o banco definido em DB_DATABASE (.env) se ainda não existir.
 * Usa as mesmas credenciais do .env (no Laragon: root, normalmente sem senha).
 */
require_once __DIR__ . '/../../bootstrap.php';

$env = static fn(string $k, string $d = '') => (string) (getenv($k) !== false ? getenv($k) : ($_ENV[$k] ?? $d));
$host = $env('DB_HOST', 'localhost');
$port = $env('DB_PORT', '3306');
$db   = $env('DB_DATABASE', 'treinamentos');
if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
    echo "Nome de banco inválido em DB_DATABASE.\n";
    exit(1);
}
try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $env('DB_USERNAME', 'root'), $env('DB_PASSWORD'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Banco de dados '$db' criado ou já existente.\n";
} catch (PDOException $e) {
    echo "Erro ao criar banco de dados: " . $e->getMessage() . "\n";
    echo "Confira se o MySQL do Laragon está ligado e se DB_USERNAME/DB_PASSWORD no .env estão corretos.\n";
    exit(1);
}
