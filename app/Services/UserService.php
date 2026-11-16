<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Gate;
use App\Repositories\ModuleRepository;
use App\Repositories\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $users = new UserRepository(),
        private ModuleRepository $modules = new ModuleRepository(),
    ) {}

    public function changeStatus(int $id, string $status): array
    {
        $u = $this->users->find($id);
        if (!$u || $u['role'] === 'admin') return ['ok' => false, 'status' => 404, 'message' => 'Usuário não encontrado.'];
        if ($id === (int) Auth::id()) return ['ok' => false, 'status' => 403, 'message' => 'Você não pode alterar o próprio cadastro.'];
        if (!in_array($status, ['aprovado', 'recusado'], true)) return ['ok' => false, 'status' => 422, 'message' => 'Status inválido.'];
        $by = Auth::id();
        $this->users->update($id, [
            'status' => $status, 'decided_at' => now(), 'decided_by' => $by, 'updated_at' => now(),
            'revoked' => ($status === 'recusado' && $u['status'] === 'aprovado') ? 1 : 0,
        ]);
        // Ao aprovar pela primeira vez, libera o sistema que a pessoa informou no cadastro (todos os módulos).
        if ($status === 'aprovado' && !$this->users->hasAnyAccess($id)) {
            if (!empty($u['requested_all'])) {
                foreach ((new \App\Repositories\SystemRepository())->all() as $s) $this->users->grantSystem($id, $s['system_id'], $by);
            } elseif ($u['requested_system_id']) {
                $this->users->grantSystem($id, $u['requested_system_id'], $by);
            }
        }
        return ['ok' => true];
    }

    public const RESET_PASSWORD = '12345678';

    /** Quem pode mexer no cadastro de $u: usuário comum = equipe com "usuarios"; equipe = só o admin principal. */
    private function canManage(array $u): ?array
    {
        if ($u['role'] === 'admin') return ['ok' => false, 'status' => 404, 'message' => 'Usuário não encontrado.'];
        if ((int) $u['user_id'] === (int) Auth::id()) return ['ok' => false, 'status' => 403, 'message' => 'Para a sua própria conta use "Meu perfil".'];
        if (!Gate::isAdmin() && Gate::codesFor((int) $u['user_id'])) return ['ok' => false, 'status' => 403, 'message' => 'Somente o administrador principal altera contas da equipe.'];
        return null;
    }

    /** Senha volta para 12345678 e a pessoa é obrigada a criar outra no próximo login. */
    public function resetPassword(int $id): array
    {
        $u = $this->users->find($id);
        if (!$u) return ['ok' => false, 'status' => 404, 'message' => 'Usuário não encontrado.'];
        if ($err = $this->canManage($u)) return $err;
        $this->users->update($id, ['password' => password_hash(self::RESET_PASSWORD, PASSWORD_DEFAULT), 'must_change_password' => 1, 'updated_at' => now()]);
        return ['ok' => true, 'password' => self::RESET_PASSWORD];
    }

    /** Edição completa do cadastro pelo administrador. */
    public function updateProfile(int $id, array $in): array
    {
        $u = $this->users->find($id);
        if (!$u) return ['ok' => false, 'status' => 404, 'message' => 'Usuário não encontrado.'];
        if ($err = $this->canManage($u)) return $err;
        $nome = preg_replace('/\s+/', ' ', trim((string) ($in['nome'] ?? '')));
        $email = trim((string) ($in['email'] ?? ''));
        $user = trim((string) ($in['usuario'] ?? ''));
        $cnpj = preg_replace('/\D/', '', (string) ($in['cnpj'] ?? ''));
        $sys = trim((string) ($in['sistema'] ?? ''));
        $e = [];
        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 120) $e['nome'] = 'Informe o nome (3 a 120 caracteres).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) $e['email'] = 'E-mail inválido.';
        elseif (\App\Core\DB::select('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ?', [$email, $id])) $e['email'] = 'Este e-mail já é usado por outra conta.';
        if (!preg_match('/^[a-zA-Z0-9._-]{3,30}$/', $user)) $e['usuario'] = 'Usuário: 3 a 30 letras, números, ponto, hífen ou _.';
        elseif (strtolower($user) === 'admin' || \App\Core\DB::select('SELECT user_id FROM users WHERE LOWER(username) = LOWER(?) AND user_id <> ?', [$user, $id])) $e['usuario'] = 'Este usuário já está em uso.';
        $company = (new \App\Repositories\CompanyRepository())->findByCnpj($cnpj);
        if (!$company) $e['cnpj'] = 'Empresa não encontrada. Use o CNPJ de uma empresa cadastrada no portal.';
        if ($sys !== '' && $sys !== 'todos' && !(new \App\Repositories\SystemRepository())->exists($sys)) $e['sistema'] = 'Sistema inválido.';
        if ($e) return ['ok' => false, 'status' => 422, 'errors' => $e, 'message' => reset($e)];
        $this->users->update($id, [
            'name' => $nome, 'email' => $email, 'username' => $user, 'company_name' => $company['name'], 'company_id' => (int) $company['company_id'],
            'document' => format_doc($company['cnpj']), 'requested_system_id' => ($sys && $sys !== 'todos') ? $sys : null, 'requested_all' => $sys === 'todos' ? 1 : 0, 'updated_at' => now(),
        ]);
        return ['ok' => true];
    }

    /** $modules = [module_id, ...]; $adm = ['aprovacoes','usuarios','modulos','videos'] (só o admin principal altera). */
    public function saveAccess(int $id, array $moduleIds, ?array $adm): array
    {
        $u = $this->users->find($id);
        if (!$u || $u['role'] === 'admin') return ['ok' => false, 'status' => 404, 'message' => 'Usuário não encontrado.'];
        if ($u['status'] !== 'aprovado') return ['ok' => false, 'status' => 422, 'message' => 'Aprove o cadastro antes de definir as permissões.'];
        $valid = array_column($this->modules->all(), 'module_id');
        $valid = array_map('intval', $valid);
        $ids = array_values(array_intersect(array_map('intval', $moduleIds), $valid));
        if ($adm !== null && !Gate::isAdmin()) return ['ok' => false, 'status' => 403, 'message' => 'Somente o administrador principal altera o acesso administrativo.'];
        if ((int) $id === (int) Auth::id() && !Gate::isAdmin()) return ['ok' => false, 'status' => 403, 'message' => 'Você não pode alterar as próprias permissões.'];
        $this->users->setModuleAccess($id, $ids, Auth::id());
        if ($adm !== null) {
            $cols = [];
            foreach (Gate::MAP as $code => $col) {
                $cols[$col] = in_array($code, $adm, true) ? 1 : 0;
            }
            $this->users->savePermissions($id, $cols, Auth::id());
            Gate::forget($id);
        }
        return ['ok' => true, 'modules' => count($ids)];
    }
}
