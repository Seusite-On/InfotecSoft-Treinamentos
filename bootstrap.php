<?php

declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

error_reporting(E_ALL);
ini_set('display_errors', '0');      // a API responde JSON; erros vão para o log (ver handler abaixo)
ini_set('log_errors', '1');

define('BASE_PATH', __DIR__);

/* ---------- Autoload: Composer se existir; senão, PSR-4 próprio (App\ -> app/) ---------- */
$composer = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $file = __DIR__ . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

/* ---------- .env (phpdotenv se instalado; senão, leitor simples com as mesmas regras) ---------- */
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
} elseif (is_file(BASE_PATH . '/.env')) {
    foreach (file(BASE_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line)); // ignora BOM (arquivo salvo pelo Bloco de Notas/PowerShell)
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        if (getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

foreach (glob(__DIR__ . '/app/Helpers/*.php') as $helper) {
    require_once $helper;
}

/* Scripts de linha de comando (migrate, seed) só precisam até aqui */
if (PHP_SAPI === 'cli') {
    return;
}

// Security Headers (mesmo padrão do Stok Net + liberações do player do YouTube e dos vídeos)
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self' https:; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; "
    . "script-src 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: blob: https:; "
    . "font-src 'self' https: data:; connect-src 'self' https:; media-src 'self' blob:; "
    . "frame-src https://www.youtube-nocookie.com https://www.youtube.com;");

// Erros não tratados viram JSON 500 nas rotas /api (e nunca expõem detalhes ao usuário)
set_exception_handler(static function (\Throwable $e): void {
    error_log('[treinamentos] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $msg = 'Erro interno no servidor.';
    if ($e instanceof \PDOException) {
        $msg = 'Não foi possível acessar o banco de dados. Confira se o MySQL do Laragon está ligado e rode o atualizar-banco.bat.';
    }
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
});

$routesFile = __DIR__ . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';
if (is_file($routesFile)) {
    require $routesFile;
}

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('treinamentos_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}
