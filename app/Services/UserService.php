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
        if (!in_array($status, ['aprovado', 'recusado'], true)) return ['ok' => false, 'status' => 422, 'message' => 'Status inválido.'];
        $by = Auth::id();
        $this->users->update($id, [
            'status' => $status, 'decided_at' => now(), 'decided_by' => $by, 'updated_at' => now(),
            'revoked' => ($status === 'recusado' && $u['status'] === 'aprovado') ? 1 : 0,
        ]);
        // Ao aprovar pela primeira vez, libera o sistema que a pessoa informou no cadastro (todos os módulos).
        if ($status === 'aprovado' && !$this->users->hasAnyAccess($id) && $u['requested_system_id']) {
            $this->users->grantSystem($id, $u['requested_system_id'], $by);
        }
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
        $this->users->setModuleAccess($id, $ids, Auth::id());
        if ($adm !== null) {
            if (!Gate::isAdmin()) return ['ok' => false, 'status' => 403, 'message' => 'Somente o administrador principal altera o acesso administrativo.'];
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
