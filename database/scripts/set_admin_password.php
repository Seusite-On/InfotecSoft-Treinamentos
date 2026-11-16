<?php
/**
 * Define a senha do administrador principal (linha de comando).
 * Uso: echo "NovaSenha123" | php database/scripts/set_admin_password.php
 * Chamado pelo definir-senha-admin.bat (a senha é digitada por você e não fica salva em lugar nenhum).
 */
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\DB;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$pw = rtrim((string) fgets(STDIN), "\r\n");
if (!password_is_strong($pw)) { fwrite(STDERR, "ERRO: " . password_rule_message() . "\n"); exit(1); }
if ($pw === '123' || $pw === '12345678') { fwrite(STDERR, "ERRO: escolha uma senha diferente das senhas padrão.\n"); exit(1); }
$n = DB::table('users')->where('role', 'admin')->where('username', 'admin')->update(['password' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
echo "Senha do administrador atualizada.\n";
