<?php
/**
 * Usuários de TESTE (alunos, equipe e solicitações pendentes) para demonstrar o portal.
 * Pode rodar mais de uma vez: quem já existe é ignorado. Não use em produção.
 */
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\DB;

$now = time();
$dt = static fn(int $hoursAgo) => date('Y-m-d H:i:s', $now - $hoursAgo * 3600);
$mods = static function (string $system, ?array $names = null): array {
    $q = DB::table('modules')->select('module_id', 'name')->where('system_id', $system)->get();
    return array_map(fn($m) => (int) $m['module_id'], array_filter($q, fn($m) => $names === null || in_array($m['name'], $names, true)));
};

$users = [
    ['Juliana Ferreira Lima', 'Mercado Bom Preço Ltda', '12.345.678/0001-95', 'juliana@mercadobompreco.com.br', 'juliana.lima', 'fusion-web', 'pendente', 2, null, []],
    ['Ricardo Almeida Souza', 'Auto Peças Souza ME', '45.871.234/0001-42', 'ricardo@autopecassouza.com.br', 'ricardo.souza', 'fusion-desktop', 'pendente', 5, null, []],
    ['Patrícia Gomes', 'Ateliê Patrícia Gomes (MEI)', '529.982.247-25', 'patricia.gomes@email.com', 'patricia', 'avante', 'pendente', 26, null, []],
    ['Carlos Mendes', 'Farmácia Vida Ltda', '77.665.544/0001-05', 'carlos@farmaciavida.com.br', 'carlos', 'e-trade', 'aprovado', 72, fn() => $mods('e-trade'), []],
    ['Juliano Martins', 'Distribuidora Martins Ltda', '11.444.777/0001-61', 'juliano@distmartins.com.br', 'juliano', 'fusion-web', 'aprovado', 120,
        fn() => $mods('fusion-web', ['Primeiros passos', 'Vendas e faturamento', 'Emissão de NF-e e NFC-e']), []],
    ['Marcos Vieira', 'InfotecSoft · Equipe de treinamento', '390.533.447-05', 'marcos@infotecsoft.com.br', 'marcos', 'fusion-web', 'aprovado', 200,
        fn() => array_merge($mods('fusion-web'), $mods('fusion-web-2')), ['can_manage_modules' => 1, 'can_manage_videos' => 1]],
    ['Teste Cadastro Recusado', 'Empresa Teste', '111.444.777-35', 'teste@email.com', 'teste', 'fusion-web', 'recusado', 96, null, []],
];

$created = 0;
foreach ($users as [$name, $company, $doc, $email, $username, $system, $status, $hours, $access, $perms]) {
    if (DB::table('users')->where('username', $username)->first()) {
        continue;
    }
    $id = (int) DB::table('users')->insert([
        'name' => $name, 'company_name' => $company, 'document' => $doc, 'email' => $email, 'username' => $username,
        'password' => password_hash('123456', PASSWORD_DEFAULT), 'role' => 'user', 'status' => $status,
        'requested_system_id' => $system, 'created_at' => $dt($hours),
        'decided_at' => $status === 'pendente' ? null : $dt($hours - 2),
    ]);
    if ($access) {
        foreach ($access() as $mid) {
            DB::table('user_module_access')->insert(['user_id' => $id, 'module_id' => $mid]);
        }
    }
    if ($perms) {
        DB::table('user_permissions')->insert(['user_id' => $id, 'updated_at' => date('Y-m-d H:i:s')] + $perms);
    }
    $created++;
    echo "Criado: $username ($status)\n";
}
echo $created ? "$created usuário(s) de teste criados. Senha de todos: 123456\n" : "Usuários de teste já existiam.\n";
