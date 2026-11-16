<?php

namespace App\Services;

use App\Core\Auth;
use App\Repositories\SystemRepository;
use App\Repositories\UserRepository;

class AuthService
{
    public function __construct(
        private UserRepository $users = new UserRepository(),
        private SystemRepository $systems = new SystemRepository(),
    ) {}

    /** @return array{ok:bool,status:int,code?:string,message?:string} */
    public function attempt(string $login, string $password): array
    {
        $u = $this->users->findByLogin($login);
        if (!$u || !password_verify($password, (string) $u['password'])) {
            return ['ok' => false, 'status' => 401, 'code' => 'invalid', 'message' => 'Usuário ou senha incorretos. Tente novamente.'];
        }
        if ($u['status'] === 'pendente') {
            return ['ok' => false, 'status' => 403, 'code' => 'pending', 'message' => 'Seu cadastro ainda está aguardando aprovação do administrador.'];
        }
        if ($u['status'] !== 'aprovado') {
            return ['ok' => false, 'status' => 403, 'code' => 'rejected', 'message' => 'Seu cadastro não foi aprovado. Fale com o suporte.'];
        }
        if (password_needs_rehash((string) $u['password'], PASSWORD_DEFAULT)) {
            $this->users->update((int) $u['user_id'], ['password' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        $this->users->update((int) $u['user_id'], ['last_login_at' => now()]);
        Auth::login(['user_id' => (int) $u['user_id'], 'name' => $u['name'], 'username' => $u['username'], 'role' => $u['role'], 'status' => $u['status']]);
        return ['ok' => true, 'status' => 200];
    }

    /** Validação do cadastro. Chaves de erro iguais aos campos da tela (nome, emp, doc, sys, email, user, pw). */
    public function register(array $in): array
    {
        $v = [
            'nome' => preg_replace('/\s+/', ' ', trim((string) ($in['nome'] ?? ''))),
            'emp' => trim((string) ($in['emp'] ?? '')),
            'doc' => preg_replace('/\D/', '', (string) ($in['doc'] ?? '')),
            'sys' => trim((string) ($in['sys'] ?? '')),
            'email' => trim((string) ($in['email'] ?? '')),
            'user' => trim((string) ($in['user'] ?? '')),
            'pw' => (string) ($in['pw'] ?? ''),
        ];
        $e = [];
        if (count(array_filter(explode(' ', $v['nome']), fn($p) => mb_strlen($p) > 1)) < 2) $e['nome'] = 'Informe seu nome e sobrenome.';
        if (mb_strlen($v['emp']) < 2) $e['emp'] = 'Informe o nome da empresa.';
        if ($v['doc'] === '') $e['doc'] = 'Informe o CNPJ da empresa ou seu CPF.';
        elseif (!(strlen($v['doc']) === 11 ? valid_cpf($v['doc']) : (strlen($v['doc']) === 14 && valid_cnpj($v['doc'])))) $e['doc'] = 'CPF ou CNPJ inválido. Confira os números.';
        if (!$this->systems->exists($v['sys'])) $e['sys'] = 'Selecione o sistema que sua empresa utiliza.';
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'Informe um e-mail válido (ex.: nome@empresa.com.br).';
        elseif ($this->users->emailInUse($v['email'])) $e['email'] = 'Já existe uma solicitação com este e-mail.';
        if (!preg_match('/^[a-zA-Z0-9._-]{3,30}$/', $v['user'])) $e['user'] = 'Use de 3 a 30 caracteres: letras, números, ponto, hífen ou _ (sem espaços).';
        elseif (strtolower($v['user']) === 'admin' || $this->users->usernameTaken($v['user'])) $e['user'] = 'Este usuário já está em uso. Escolha outro.';
        if (strlen($v['pw']) < 6) $e['pw'] = 'A senha precisa ter pelo menos 6 caracteres.';
        elseif (!preg_match('/[a-zA-Z]/', $v['pw']) || !preg_match('/\d/', $v['pw'])) $e['pw'] = 'Use letras e números na senha.';
        if ($e) {
            return ['ok' => false, 'errors' => $e];
        }
        $id = $this->users->create([
            'name' => $v['nome'], 'company_name' => $v['emp'], 'document' => format_doc($v['doc']), 'email' => $v['email'],
            'username' => $v['user'], 'password' => password_hash($v['pw'], PASSWORD_DEFAULT), 'role' => 'user', 'status' => 'pendente',
            'requested_system_id' => $v['sys'], 'created_at' => now(),
        ]);
        return ['ok' => true, 'user' => ['id' => (string) $id, 'nome' => $v['nome'], 'empresa' => $v['emp'], 'sistema' => $v['sys'], 'usuario' => $v['user']]];
    }
}
