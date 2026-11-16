<?php
/**
 * Garante o administrador principal (usuário "admin").
 * Só cria se ainda não existir — nunca troca a senha de um admin já cadastrado.
 */
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\DB;

$exists = DB::table('users')->where('username', 'admin')->first();
if ($exists) {
    echo "Administrador 'admin' já existe (senha mantida).\n";
    exit(0);
}
$id = DB::table('users')->insert([
    'name' => 'Administrador',
    'company_name' => 'InfotecSoft',
    'email' => 'admin@infotecsoft.com.br',
    'username' => 'admin',
    'password' => password_hash('123', PASSWORD_DEFAULT),
    'role' => 'admin',
    'status' => 'aprovado',
    'decided_at' => date('Y-m-d H:i:s'),
    'created_at' => date('Y-m-d H:i:s'),
]);
echo "Administrador criado: usuário 'admin' / senha '123' (troque a senha depois do primeiro acesso).\n";
