<?php
/** Teste rápido: conexão, tabelas e admin. Uso: php tests/smoke.php (sai com 1 se falhar). */
require_once __DIR__ . '/../bootstrap.php';

use App\Core\DB;

$fail = 0;
$check = function (string $label, bool $ok) use (&$fail) { echo ($ok ? '[OK]  ' : '[ERRO] ') . $label . "\n"; if (!$ok) $fail++; };
try {
    DB::select('SELECT 1');
    $check('Conexão com o MySQL', true);
} catch (\Throwable $e) {
    $check('Conexão com o MySQL: ' . $e->getMessage(), false);
    exit(1);
}
foreach (['systems', 'modules', 'lessons', 'users', 'user_permissions', 'user_module_access'] as $t) {
    $check("Tabela $t", DB::select("SHOW TABLES LIKE '$t'") !== []);
}
$check('Administrador cadastrado', (bool) DB::table('users')->where('role', 'admin')->first());
$check('Pasta storage/videos gravável', is_writable(BASE_PATH . '/storage/videos'));
$check('Limite de upload (' . ini_get('upload_max_filesize') . ')', true);
exit($fail ? 1 : 0);
