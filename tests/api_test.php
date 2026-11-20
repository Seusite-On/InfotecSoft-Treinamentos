<?php
/**
 * Teste completo da API (segurança + funcionalidades), feito contra um servidor rodando.
 * Uso:  php tests/api_test.php http://127.0.0.1:8099 [senha_admin]
 * ATENÇÃO: cria/edita/apaga dados. Rode só em um banco de TESTE.
 */
declare(strict_types=1);

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$ADMIN_PW = $argv[2] ?? '123';
$pass = 0; $fail = 0; $fails = [];

function check(string $name, bool $ok, string $info = ''): void
{
    global $pass, $fail, $fails;
    if ($ok) { $pass++; echo "  [OK]   $name\n"; }
    else { $fail++; $fails[] = $name; echo "  [FALHA] $name" . ($info ? "  -> $info" : '') . "\n"; }
}

final class Client
{
    public string $csrf = '';
    private string $jar;
    public function __construct(private string $base, public string $ip = '')
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }
    /** @return array{0:int,1:array,2:string,3:array} status, json, body, headers */
    public function req(string $method, string $path, $body = null, array $opt = []): array
    {
        $ch = curl_init($this->base . $path);
        $hdr = ['Accept: application/json'];
        if (!($opt['nocsrf'] ?? false) && $this->csrf) $hdr[] = 'X-CSRF-TOKEN: ' . $this->csrf;
        if (isset($opt['headers'])) $hdr = array_merge($hdr, $opt['headers']);
        if (is_array($body) && !($opt['multipart'] ?? false)) { $body = json_encode($body); $hdr[] = 'Content-Type: application/json'; }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) {
                $p = explode(':', $h, 2); if (count($p) === 2) $respHeaders[strtolower(trim($p[0]))][] = trim($p[1]); return strlen($h);
            },
        ]);
        if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = json_decode($out, true);
        return [$code, is_array($j) ? $j : [], $out, $respHeaders];
    }
    public function state(): array { [, $j] = $this->req('GET', '/api/state'); $this->csrf = $j['csrf'] ?? $this->csrf; return $j; }
    public function login(string $u, string $p): array { $this->state(); $r = $this->req('POST', '/api/login', ['username' => $u, 'password' => $p]); $this->state(); return $r; }
}

$uniq = substr(bin2hex(random_bytes(3)), 0, 6);
echo "Testando $BASE\n";

/* ---------------- 1. Página e cabeçalhos ---------------- */
echo "\n1) Página e cabeçalhos de segurança\n";
$g = new Client($BASE);
[$c, , $body, $h] = $g->req('GET', '/');
check('GET / responde 200', $c === 200, "HTTP $c");
check('Página não mostra usuário/senha de teste', !str_contains($body, 'Administrador de teste') && !str_contains($body, 'Protótipo visual'));
foreach (['x-content-type-options', 'x-frame-options', 'referrer-policy', 'content-security-policy', 'permissions-policy'] as $k) {
    check("Cabeçalho $k presente", isset($h[$k]));
}
check('CSP sem curinga https: em scripts', isset($h['content-security-policy']) && !preg_match("/script-src[^;]*\\shttps:(\\s|;|$)/", $h['content-security-policy'][0]));
check('Não expõe X-Powered-By', !isset($h['x-powered-by']));
$cookie = implode(' ', $h['set-cookie'] ?? []);
check('Cookie de sessão HttpOnly + SameSite', str_contains($cookie, 'HttpOnly') && stripos($cookie, 'SameSite=Lax') !== false, $cookie);
if (str_starts_with($BASE, 'https')) {
    check('Cookie de sessão Secure', stripos($cookie, 'secure') !== false, $cookie);
    check('HSTS ativo', isset($h['strict-transport-security']));
}
[$c] = $g->req('HEAD', '/');
check('HEAD / responde 200', $c === 200, "HTTP $c");
[$c] = $g->req('GET', '/rota-que-nao-existe');
check('Rota inexistente -> 404', $c === 404, "HTTP $c");
foreach (['/.env', '/../.env', '/.htaccess', '/index.php/../../.env', '/storage/security/rate_limits.json', '/database/migrations/001_initial_schema.sql', '/composer.json'] as $p) {
    [$c, , $b] = $g->req('GET', $p);
    check("Arquivo protegido $p não vaza", !str_contains($b, 'DB_PASSWORD') && !str_contains($b, 'CREATE TABLE') && !str_contains($b, '"require"'), "HTTP $c");
}

/* ---------------- 2. Estado público ---------------- */
echo "\n2) Dados públicos (sem login)\n";
$s = $g->state();
check('/api/state sem login: me = null', array_key_exists('me', $s) && $s['me'] === null);
check('/api/state sem login: não envia aulas nem usuários', empty($s['lessons']) && empty($s['users']));
check('/api/state sem login: números reais (stats)', isset($s['stats']['videos'], $s['stats']['modules'], $s['stats']['systems']));
check('/api/state sem login: CSRF gerado', strlen($s['csrf'] ?? '') === 64);

/* ---------------- 3. CSRF e acesso sem login ---------------- */
echo "\n3) CSRF e rotas protegidas\n";
[$c] = $g->req('POST', '/api/login', ['username' => 'admin', 'password' => 'x'], ['nocsrf' => true]);
check('POST sem token CSRF -> 419', $c === 419, "HTTP $c");
[$c] = $g->req('POST', '/api/login', ['username' => 'admin', 'password' => 'x'], ['nocsrf' => true, 'headers' => ['X-CSRF-TOKEN: ' . str_repeat('a', 64)]]);
check('POST com token CSRF falso -> 419', $c === 419, "HTTP $c");
foreach ([['POST', '/api/modules'], ['POST', '/api/lessons'], ['POST', '/api/users/1/status'], ['POST', '/api/users/1/access'], ['POST', '/api/password']] as [$m, $p]) {
    [$c] = $g->req($m, $p, ['x' => 1]);
    check("$m $p sem login -> 401", $c === 401, "HTTP $c");
}
foreach (['/media/video/1', '/media/thumb/1', '/media/video/..%2F..%2F.env'] as $p) {
    [$c] = $g->req('GET', $p);
    check("GET $p sem login -> 401", $c === 401, "HTTP $c");
}

/* ---------------- 4. Login ---------------- */
echo "\n4) Login e proteção contra força bruta\n";
$bf = new Client($BASE);
$bfUser = 'naoexiste' . $uniq;
[$c, $j] = $bf->login($bfUser, 'errada123');
check('Login inválido -> 401 com mensagem genérica', $c === 401 && ($j['code'] ?? '') === 'invalid', "HTTP $c");
for ($i = 0; $i < 4; $i++) $bf->login($bfUser, 'errada123');
[$c, $j] = $bf->login($bfUser, 'errada123');
check('6ª tentativa errada do mesmo usuário -> 429 (bloqueio 15 min)', $c === 429, "HTTP $c");
[$c] = $bf->login('', '');
check('Login vazio -> 422', in_array($c, [422, 429], true), "HTTP $c");
$t0 = microtime(true); $bf2 = new Client($BASE); $bf2->login('outro' . $uniq, 'x1234567'); $tNo = microtime(true) - $t0;
check('Usuário inexistente também calcula hash (tempo > 20ms)', $tNo > 0.02, round($tNo * 1000) . 'ms');

/* ---------------- 5. Admin + troca obrigatória de senha ---------------- */
echo "\n5) Administrador e troca obrigatória de senha fraca\n";
$adm = new Client($BASE);
[$c, $j] = $adm->login('admin', $ADMIN_PW);
check('Admin entra', $c === 200, "HTTP $c " . ($j['message'] ?? ''));
$weakAdmin = !empty($j['must_change']);
$st = $adm->state();
if ($ADMIN_PW === '123') {
    check('Senha inicial 123 marca must_change', $weakAdmin && !empty($st['me']['must_change']));
    [$c, $j] = $adm->req('POST', '/api/modules', ['system' => 'fusion-web', 'name' => 'Bloqueado ' . $uniq, 'icon' => 'book']);
    check('Com senha fraca, ações administrativas bloqueadas (403)', $c === 403 && ($j['code'] ?? '') === 'must_change', "HTTP $c");
    [$c] = $adm->req('POST', '/api/password', ['current' => '123', 'new' => 'abc12']);
    check('Nova senha curta recusada', $c === 422, "HTTP $c");
    [$c] = $adm->req('POST', '/api/password', ['current' => 'errada', 'new' => 'Admin' . $uniq . '9']);
    check('Senha atual errada recusada', $c === 422, "HTTP $c");
    $ADMIN_PW = 'Admin' . $uniq . '9';
    [$c] = $adm->req('POST', '/api/password', ['current' => '123', 'new' => $ADMIN_PW]);
    check('Troca de senha do admin', $c === 200, "HTTP $c");
    $st = $adm->state();
    check('must_change some após a troca', empty($st['me']['must_change']));
    $old = new Client($BASE); [$c] = $old->login('admin', '123');
    check('Senha antiga 123 não funciona mais', $c === 401, "HTTP $c");
    echo "     (senha de teste do admin agora: $ADMIN_PW)\n";
}
check('Admin recebe lista de usuários e aulas', isset($st['users'], $st['lessons']) && count($st['lessons']) > 0);

/* ---------------- 6. Módulos ---------------- */
echo "\n6) Módulos (criar, renomear, mover, excluir)\n";
[$c, $j] = $adm->req('POST', '/api/modules', ['system' => 'fusion-web', 'name' => 'Teste ' . $uniq, 'icon' => 'book']);
$modId = (int) ($j['id'] ?? 0);
check('Criar módulo', $c === 200 && $modId > 0, "HTTP $c " . ($j['message'] ?? ''));
[$c] = $adm->req('POST', '/api/modules', ['system' => 'fusion-web', 'name' => 'Teste ' . $uniq, 'icon' => 'book']);
check('Nome de módulo duplicado recusado', $c >= 400, "HTTP $c");
[$c] = $adm->req('POST', '/api/modules', ['system' => 'nao-existe', 'name' => 'Xyz ' . $uniq]);
check('Sistema inválido recusado', $c >= 400, "HTTP $c");
[$c] = $adm->req('POST', "/api/modules/$modId", ['name' => 'Teste renomeado ' . $uniq, 'icon' => 'video']);
check('Renomear módulo', $c === 200, "HTTP $c");
[$c] = $adm->req('POST', "/api/modules/$modId/move", ['dir' => -1]);
check('Mover módulo', $c === 200, "HTTP $c");

/* ---------------- 7. Aulas / vídeos ---------------- */
echo "\n7) Aulas e vídeos\n";
[$c, $j] = $adm->req('POST', '/api/lessons', ['module_id' => $modId, 'source' => 'youtube', 'youtube_id' => 'abc', 'title' => 'Aula X']);
check('Link do YouTube inválido recusado', $c === 422, "HTTP $c");
[$c, $j] = $adm->req('POST', '/api/lessons', ['module_id' => $modId, 'source' => 'youtube', 'youtube_id' => 'dQw4w9WgXcQ', 'title' => '<img src=x onerror=alert(1)> Aula ' . $uniq, 'duration' => '03:10']);
$lesYt = (int) ($j['id'] ?? $j['lesson']['id'] ?? 0);
check('Publicar aula com YouTube', $c === 200, "HTTP $c " . ($j['message'] ?? ''));
$tmp = tempnam(sys_get_temp_dir(), 'v');
file_put_contents($tmp, "<?php system('id'); ?>");
[$c, $j] = $adm->req('POST', '/api/lessons', ['module_id' => (string) $modId, 'source' => 'file', 'title' => 'Arquivo malicioso', 'file' => new CURLFile($tmp, 'video/mp4', 'shell.php')], ['multipart' => true]);
check('Upload .php recusado', $c >= 400, "HTTP $c");
[$c, $j] = $adm->req('POST', '/api/lessons', ['module_id' => (string) $modId, 'source' => 'file', 'title' => 'Falso vídeo', 'file' => new CURLFile($tmp, 'video/mp4', 'video.mp4')], ['multipart' => true]);
check('Arquivo que não é vídeo (com extensão .mp4) recusado', $c >= 400, "HTTP $c");
// MP4 mínimo válido (cabeçalho ftyp)
$mp4 = pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isomiso2' . pack('N', 8) . 'free' . str_repeat("\0", 2048);
file_put_contents($tmp, $mp4);
[$c, $j] = $adm->req('POST', '/api/lessons', ['module_id' => (string) $modId, 'source' => 'file', 'title' => 'Vídeo de teste ' . $uniq, 'file' => new CURLFile($tmp, 'video/mp4', 'aula.mp4')], ['multipart' => true]);
check('Upload de vídeo MP4 aceito', $c === 200, "HTTP $c " . ($j['message'] ?? ''));
@unlink($tmp);
$st = $adm->state();
$mine = array_values(array_filter($st['lessons'], fn($l) => (int) $l['module_id'] === $modId));
$fileLesson = null; foreach ($mine as $l) if (!empty($l['file'])) $fileLesson = $l;
check('Aulas novas aparecem no estado', count($mine) >= 2, (string) count($mine));
if ($fileLesson) {
    [$c, , $b, $hh] = $adm->req('GET', '/media/video/' . $fileLesson['id']);
    check('Admin assiste o vídeo enviado', $c === 200 && strlen($b) === strlen($mp4), "HTTP $c");
    [$c, , , $hh] = $adm->req('GET', '/media/video/' . $fileLesson['id'], null, ['headers' => ['Range: bytes=0-99']]);
    check('Vídeo aceita Range (avançar/voltar)', $c === 206 && ($hh['content-length'][0] ?? '') === '100', "HTTP $c");
}

/* ---------------- 8. Cadastro de aluno ---------------- */
echo "\n8) Cadastro e aprovação\n";
$alu = new Client($BASE);
$alu->state();
$cpf = function (): string { $n = []; for ($i = 0; $i < 9; $i++) $n[] = random_int(0, 9);
    for ($t = 9; $t < 11; $t++) { $s = 0; for ($i = 0; $i < $t; $i++) $s += $n[$i] * ($t + 1 - $i); $n[] = (($s * 10) % 11) % 10; } return implode('', $n); };
$base = ['nome' => 'Aluno Teste', 'emp' => 'Ignorado', 'doc' => '10340101000165', 'sys' => 'fusion-web', 'email' => "aluno$uniq@teste.com", 'user' => "aluno$uniq"];
[$c, $j] = $alu->req('POST', '/api/register', $base + ['pw' => 'abc123']);
check('Cadastro com senha de 6 caracteres recusado (mín. 8)', $c === 422 && isset($j['errors']['pw']), "HTTP $c");
[$c, $j] = $alu->req('POST', '/api/register', array_merge($base, ['user' => 'admin', 'pw' => 'Senha' . $uniq . '1']));
check('Não deixa cadastrar o usuário "admin"', $c === 422 && isset($j['errors']['user']), "HTTP $c");
[$c, $j] = $alu->req('POST', '/api/register', array_merge($base, ['doc' => '11111111111111', 'pw' => 'Senha' . $uniq . '1']));
check('CNPJ inválido recusado', $c === 422 && isset($j['errors']['doc']), "HTTP $c");
[$c, $j] = $alu->req('POST', '/api/register', array_merge($base, ['doc' => '11222333000181', 'pw' => 'Senha' . $uniq . '1']));
check('CNPJ de empresa não cadastrada no portal recusado', $c === 422 && str_contains($j['errors']['doc'] ?? '', 'não encontrada'), "HTTP $c");
[$c, $j] = $alu->req('GET', '/api/companies/10340101000165');
check('Consulta de CNPJ identifica a InfotecSoft', $c === 200 && ($j['name'] ?? '') === 'InfotecSoft', "HTTP $c");
[$c] = $alu->req('GET', '/api/companies/11222333000181');
check('Consulta de CNPJ não cadastrado -> 404', $c === 404, "HTTP $c");
$ALU_PW = 'Senha ' . $uniq . '1';   // com espaço: senha não pode ser "aparada"
[$c, $j] = $alu->req('POST', '/api/register', array_merge($base, ['nome' => '<script>alert(1)</script> Silva', 'pw' => $ALU_PW]));
$aluId = (int) ($j['user']['id'] ?? 0);
check('Cadastro válido -> 201 (pendente)', $c === 201 && $aluId > 0, "HTTP $c " . ($j['message'] ?? ''));
check('Empresa do cadastro vem do CNPJ (InfotecSoft)', ($j['user']['empresa'] ?? '') === 'InfotecSoft', $j['user']['empresa'] ?? '');
[$c, $j] = $alu->login("aluno$uniq", $ALU_PW);
check('Aluno pendente não entra (403)', $c === 403 && ($j['code'] ?? '') === 'pending', "HTTP $c");
[$c] = $adm->req('POST', "/api/users/$aluId/status", ['status' => 'aprovado']);
check('Admin aprova o aluno', $c === 200, "HTTP $c");
[$c] = $adm->req('POST', "/api/users/$aluId/access", ['modules' => []]);
check('Admin define acesso do aluno (nenhum módulo)', $c === 200, "HTTP $c");
[$c, $j] = $alu->login("aluno$uniq", $ALU_PW);
check('Aluno aprovado entra (senha com espaço preservada)', $c === 200 && empty($j['must_change']), "HTTP $c " . ($j['message'] ?? ''));
$st = $alu->state();
check('Aluno não recebe lista de usuários', empty($st['users']));
$visible = array_filter($st['lessons'] ?? [], fn($l) => !empty($l['yt']) || !empty($l['file']));
check('Aluno sem módulos não vê nenhum vídeo', count($visible) === 0, (string) count($visible));
if ($fileLesson) {
    [$c] = $alu->req('GET', '/media/video/' . $fileLesson['id']);
    check('Aluno sem acesso ao módulo não baixa o vídeo (403)', $c === 403, "HTTP $c");
}

/* ---------------- 9. Escalada de privilégio ---------------- */
echo "\n9) Tentativas de escalar privilégio\n";
foreach ([['/api/modules', ['system' => 'fusion-web', 'name' => 'Hack ' . $uniq]], ["/api/modules/$modId/delete", []], ['/api/lessons', ['module_id' => $modId, 'source' => 'youtube', 'youtube_id' => 'dQw4w9WgXcQ', 'title' => 'hack']],
          ["/api/users/$aluId/status", ['status' => 'aprovado']], ["/api/users/$aluId/access", ['modules' => [$modId], 'adm' => ['usuarios', 'videos']]], ['/api/users/1/access', ['modules' => []]]] as [$p, $b]) {
    [$c] = $alu->req('POST', $p, $b);
    check("Aluno: POST $p -> 403", $c === 403, "HTTP $c");
}
// aluno vira equipe só com "usuarios"
[$c] = $adm->req('POST', "/api/users/$aluId/access", ['modules' => [], 'adm' => ['usuarios']]);
check('Admin dá permissão "usuários" ao aluno', $c === 200, "HTTP $c");
$alu->state();
[$c] = $alu->req('POST', "/api/users/$aluId/access", ['modules' => [$modId]]);
check('Equipe não altera as próprias permissões', $c === 403, "HTTP $c");
[$c] = $alu->req('POST', "/api/users/$aluId/access", ['modules' => [], 'adm' => ['usuarios', 'videos', 'modulos', 'aprovacoes']]);
check('Equipe não dá permissões administrativas', $c === 403, "HTTP $c");
[$c] = $alu->req('POST', '/api/users/1/access', ['modules' => []]);
check('Equipe não mexe no administrador principal', $c === 404, "HTTP $c");
[$c] = $adm->req('POST', "/api/users/$aluId/access", ['modules' => [$modId], 'adm' => []]);
check('Admin libera o módulo de teste e tira a permissão', $c === 200, "HTTP $c");
$st = $alu->state();
$visible = array_filter($st['lessons'] ?? [], fn($l) => !empty($l['yt']) || !empty($l['file']));
check('Aluno passa a ver os vídeos do módulo liberado', count($visible) >= 2, (string) count($visible));
if ($fileLesson) {
    [$c] = $alu->req('GET', '/media/video/' . $fileLesson['id']);
    check('Aluno com acesso assiste o vídeo', $c === 200, "HTTP $c");
}
// recusar => sessão do aluno cai na hora
[$c] = $adm->req('POST', "/api/users/$aluId/status", ['status' => 'recusado']);
check('Admin bloqueia o aluno', $c === 200, "HTTP $c");
$st = $alu->state();
check('Aluno bloqueado perde a sessão imediatamente', ($st['me'] ?? null) === null);
if ($fileLesson) {
    [$c] = $alu->req('GET', '/media/video/' . $fileLesson['id']);
    check('Aluno bloqueado não acessa mais o vídeo', $c === 401, "HTTP $c");
}
[$c] = $adm->req('POST', '/api/users/1/status', ['status' => 'recusado']);
check('Ninguém bloqueia o administrador principal', $c >= 400, "HTTP $c");

/* ---------------- 9b. Reset de senha, edição de cadastro e aulas ---------------- */
echo "\n9b) Reset de senha, edição de cadastro e gestão de aulas\n";
[$c] = $adm->req('POST', "/api/users/$aluId/status", ['status' => 'aprovado']);
check('Admin reaprova o aluno', $c === 200, "HTTP $c");
[$c, $j] = $adm->req('POST', "/api/users/$aluId/reset-password");
check('Admin reseta a senha para 12345678', $c === 200 && ($j['password'] ?? '') === '12345678', "HTTP $c");
$alu2 = new Client($BASE);
[$c] = $alu2->login("aluno$uniq", $ALU_PW);
check('Senha antiga do aluno deixa de funcionar', $c === 401, "HTTP $c");
[$c, $j] = $alu2->login("aluno$uniq", '12345678');
$st = $alu2->state();
check('Login com 12345678 exige troca de senha', $c === 200 && !empty($j['must_change']) && !empty($st['me']['must_change']), "HTTP $c");
[$c] = $alu2->req('POST', '/api/password', ['current' => '12345678', 'new' => '12345678']);
check('Não aceita manter 12345678', $c === 422, "HTTP $c");
$ALU_PW = 'Nova' . $uniq . '77';
[$c] = $alu2->req('POST', '/api/password', ['current' => '12345678', 'new' => $ALU_PW]);
$st = $alu2->state();
check('Aluno cria nova senha e a exigência some', $c === 200 && empty($st['me']['must_change']), "HTTP $c");
$alu3 = new Client($BASE); [$c, $j] = $alu3->login("aluno$uniq", $ALU_PW);
check('Novo login normal, sem exigência', $c === 200 && empty($j['must_change']), "HTTP $c");
[$c, $j] = $adm->req('POST', "/api/users/$aluId/profile", ['nome' => 'Aluno Editado', 'email' => "editado$uniq@teste.com", 'usuario' => "edit$uniq", 'cnpj' => '10340101000165', 'sistema' => 'avante']);
check('Admin edita o cadastro completo', $c === 200, "HTTP $c " . ($j['message'] ?? ''));
$st = $adm->state(); $eu = null; foreach ($st['users'] as $u) if ((int) $u['id'] === $aluId) $eu = $u;
check('Cadastro editado salvo (nome, e-mail, usuário, sistema)', $eu && $eu['nome'] === 'Aluno Editado' && $eu['usuario'] === "edit$uniq" && $eu['sistema'] === 'avante' && $eu['empresa'] === 'InfotecSoft');
[$c, $j] = $adm->req('POST', "/api/users/$aluId/profile", ['nome' => 'X', 'email' => 'invalido', 'usuario' => 'admin', 'cnpj' => '11222333000181']);
check('Edição com dados inválidos recusada com erros por campo', $c === 422 && isset($j['errors']['nome'], $j['errors']['email'], $j['errors']['usuario'], $j['errors']['cnpj']), "HTTP $c");
[$c] = $adm->req('POST', '/api/users/1/reset-password');
check('Ninguém reseta a senha do administrador principal', $c === 404, "HTTP $c");
[$c] = $alu3->req('POST', "/api/users/$aluId/reset-password");
check('Aluno não reseta senhas (403)', $c === 403, "HTTP $c");
// aulas: criar 2, mover, excluir
[$c, $j1] = $adm->req('POST', '/api/lessons', ['module_id' => $modId, 'source' => 'youtube', 'youtube_id' => 'dQw4w9WgXcQ', 'title' => 'Ordem A ' . $uniq]);
[$c2, $j2] = $adm->req('POST', '/api/lessons', ['module_id' => $modId, 'source' => 'youtube', 'youtube_id' => 'dQw4w9WgXcQ', 'title' => 'Ordem B ' . $uniq]);
$la = (int) ($j1['id'] ?? 0); $lb = (int) ($j2['id'] ?? 0);
check('Publica duas aulas novas', $la && $lb, "HTTP $c/$c2");
[$c] = $adm->req('POST', "/api/lessons/$lb/move", ['dir' => -1]);
$st = $adm->state(); $pos = []; foreach ($st['lessons'] as $l) if (in_array((int) $l['id'], [$la, $lb], true)) $pos[(int) $l['id']] = $l['position'];
check('Mover aula para cima troca a ordem', $c === 200 && ($pos[$lb] ?? 99) < ($pos[$la] ?? 0), json_encode($pos));
[$c] = $alu3->req('POST', "/api/lessons/$la/delete");
check('Aluno não exclui aula (403)', $c === 403, "HTTP $c");
[$c] = $adm->req('POST', "/api/lessons/$la/delete");
$st = $adm->state(); $still = array_filter($st['lessons'], fn($l) => (int) $l['id'] === $la);
check('Admin exclui a aula inteira', $c === 200 && !$still, "HTTP $c");
$adm->req('POST', "/api/lessons/$lb/delete");

// painel de últimos acessos
$st = $adm->state(); $acc = $st['access'] ?? null;
check('Admin recebe a lista de últimos acessos', is_array($acc) && count($acc) > 0);
check('Login com sucesso registrado (entrou)', is_array($acc) && (bool) array_filter($acc, fn($x) => $x['ev'] === 'entrou' && (int) $x['uid'] === $aluId && $x['nome'] === 'Aluno Editado'));
check('Senha errada registrada', is_array($acc) && (bool) array_filter($acc, fn($x) => $x['ev'] === 'senha_errada'));
check('Bloqueio por excesso registrado', is_array($acc) && (bool) array_filter($acc, fn($x) => $x['ev'] === 'bloqueado'));
$st = $alu3->state();
check('Aluno não recebe a lista de acessos', !isset($st['access']));

// sugestões de melhoria
[$c, $j] = $alu3->req('POST', '/api/suggestions', ['category' => 'conteudo', 'message' => 'curta']);
check('Sugestão muito curta recusada', $c === 422, "HTTP $c");
[$c, $j] = $alu3->req('POST', '/api/suggestions', ['category' => 'conteudo', 'message' => '<b>Aula</b> sobre MDF-e no Fusion Web, por favor ' . $uniq]);
$sgId = $j['id'] ?? '';
check('Aluno envia sugestão', $c === 201 && $sgId !== '', "HTTP $c");
$st = $adm->state(); $sg = null; foreach ($st['suggestions'] ?? [] as $x) if ($x['id'] === $sgId) $sg = $x;
check('Admin vê a sugestão com o nome de quem enviou', $sg && $sg['status'] === 'nova' && $sg['nome'] === 'Aluno Editado');
[$c] = $alu3->req('POST', "/api/suggestions/$sgId/status", ['status' => 'feita']);
check('Aluno não muda o status de sugestões (403)', $c === 403, "HTTP $c");
[$c] = $adm->req('POST', "/api/suggestions/$sgId/status", ['status' => 'feita', 'note' => 'Aula publicada!']);
$st = $alu3->state(); $mySg = array_values(array_filter($st['suggestions'] ?? [], fn($x) => $x['id'] === $sgId));
check('Aluno vê a resposta e o status "feita"', $c === 200 && $mySg && $mySg[0]['status'] === 'feita' && $mySg[0]['note'] === 'Aula publicada!', "HTTP $c");
check('Aluno só vê as próprias sugestões', count(array_filter($st['suggestions'] ?? [], fn($x) => !$x['mine'])) === 0);
[$c] = $adm->req('POST', "/api/suggestions/$sgId/delete");
check('Admin exclui a sugestão', $c === 200, "HTTP $c");

/* ---------------- 9b. Pontuação e relatório ---------------- */
echo "\n9b) Pontuação por vídeo e relatório\n";
$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
[$c] = $adm->req('POST', "/api/users/$aluId/access", ['modules' => [$modId], 'adm' => []]);
check('Libera o módulo de teste para o aluno (sem cargo)', $c === 200, "HTTP $c");
$vl = null; foreach ($mine as $l) if ($l['source'] !== 'none') { $vl = $l; break; }
$st = $alu3->state();
check('Estado traz pontos zerados e lista de assistidas', ($st['me']['pontos'] ?? null) === 0 && ($st['me']['assistidas'] ?? null) === []);
[$c, $j] = $alu3->req('POST', "/api/lessons/{$vl['id']}/progress", ['event' => 'done']);
check('Concluir sem começar não dá pontos', $c === 422 && ($j['code'] ?? '') === 'not_started', "HTTP $c");
[$c] = $alu3->req('POST', "/api/lessons/{$vl['id']}/progress", ['event' => 'start']);
check('Começar a assistir registra o início', $c === 200, "HTTP $c");
[$c, $j] = $alu3->req('POST', "/api/lessons/{$vl['id']}/progress", ['event' => 'done']);
check('Pular direto para o fim não dá pontos (tempo mínimo)', $c === 422 && ($j['code'] ?? '') === 'too_fast', "HTTP $c");
$pdo->prepare('UPDATE lesson_views SET started_at = NOW() - INTERVAL 2 HOUR WHERE user_id = ? AND lesson_id = ?')->execute([$aluId, $vl['id']]);
[$c, $j] = $alu3->req('POST', "/api/lessons/{$vl['id']}/progress", ['event' => 'done']);
check('Assistir até o fim dá 10 pontos', $c === 200 && ($j['points'] ?? 0) === 10 && ($j['total'] ?? 0) === 10, "HTTP $c " . json_encode($j));
[$c, $j] = $alu3->req('POST', "/api/lessons/{$vl['id']}/progress", ['event' => 'done']);
check('Assistir de novo não soma pontos em dobro', $c === 200 && !empty($j['already']) && ($j['total'] ?? 0) === 10, "HTTP $c");
$st = $alu3->state();
check('Estado mostra 10 pontos e o vídeo como assistido', ($st['me']['pontos'] ?? 0) === 10 && in_array($vl['id'], $st['me']['assistidas'] ?? [], true));
$other = $pdo->query("SELECT l.lesson_id FROM lessons l WHERE l.module_id <> $modId AND l.video_source = 'youtube' LIMIT 1")->fetchColumn();
[$c] = $alu3->req('POST', "/api/lessons/$other/progress", ['event' => 'start']);
check('Não pontua em aula de módulo sem acesso (403)', $c === 403, "HTTP $c");
[$c] = $alu3->req('POST', "/api/lessons/{$vl['id']}/progress", ['event' => 'hack']);
check('Evento inválido recusado', $c === 422, "HTTP $c");
[$c] = $alu3->req('GET', '/api/reports/ranking');
check('Aluno sem permissão não vê o relatório (403)', $c === 403, "HTTP $c");
[$c, $j] = $adm->req('GET', '/api/reports/ranking?dias=30');
$me = array_values(array_filter($j['rows'] ?? [], fn($r) => $r['id'] === (string) $aluId));
check('Admin vê o aluno no ranking com 10 pontos e 1 vídeo', $c === 200 && $me && $me[0]['pontos'] === 10 && $me[0]['videos'] === 1, "HTTP $c");
check('Relatório traz os vídeos mais assistidos', !empty($j['top']), "HTTP $c");
[$c, $j] = $adm->req('GET', '/api/reports/ranking?sistema=sistema-inexistente');
check('Filtro por sistema funciona', $c === 200 && ($j['rows'] ?? null) === [], "HTTP $c");
[$c] = $adm->req('POST', "/api/users/$aluId/access", ['modules' => [$modId], 'adm' => ['relatorios']]);
[$c] = $alu3->req('GET', '/api/reports/ranking');
check('Com a permissão "relatorios", o usuário vê o relatório', $c === 200, "HTTP $c");
$st = $adm->state(); $yl = array_values(array_filter($st['lessons'], fn($l) => $l['yt'] === '5R5_ArgyMyA'));
check('Vídeos trazem a data de publicação do YouTube', $yl && $yl[0]['published'] === '2026-05-14' || ($yl && preg_match('/^\d{4}-\d\d-\d\d$/', $yl[0]['published'])), json_encode($yl[0]['published'] ?? null));

/* ---------------- 10. Limpeza + logout ---------------- */
echo "\n10) Limpeza e saída\n";
foreach ($mine as $l) { $adm->req('POST', '/api/lessons/' . $l['id'] . '/remove-video'); }
[$c, $j] = $adm->req('POST', "/api/modules/$modId/delete");
check('Excluir módulo de teste', $c === 200, "HTTP $c " . ($j['message'] ?? ''));
[$c] = $adm->req('POST', '/api/logout');
check('Logout', $c === 200, "HTTP $c");
$st = $adm->state();
check('Após logout, sessão encerrada', ($st['me'] ?? null) === null);
[$c] = $adm->req('POST', '/api/modules', ['system' => 'fusion-web', 'name' => 'Depois ' . $uniq]);
check('Após logout, ações exigem login (401)', $c === 401, "HTTP $c");

/* cadastro: limite por rede (5 por hora) */
echo "\n11) Limite de cadastros por rede\n";
$sp = new Client($BASE); $sp->state(); $last = 0;
for ($i = 0; $i < 6; $i++) {
    [$last] = $sp->req('POST', '/api/register', array_merge($base, ['email' => "spam$i$uniq@teste.com", 'user' => "spam$i$uniq", 'pw' => 'Senha' . $uniq . '1']));
}
check('Cadastros em massa da mesma rede bloqueados (429)', $last === 429, "HTTP $last");

echo "\nResultado: $pass OK, $fail falha(s)\n";
if ($fails) { echo "Falhas:\n - " . implode("\n - ", $fails) . "\n"; }
exit($fail ? 1 : 0);
