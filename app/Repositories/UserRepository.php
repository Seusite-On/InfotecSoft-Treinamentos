<?php

namespace App\Repositories;

use App\Core\DB;

class UserRepository
{
    public function findByLogin(string $login): ?array
    {
        $rows = DB::select('SELECT * FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?) ORDER BY (LOWER(username) = LOWER(?)) DESC LIMIT 1', [$login, $login, $login]);
        return $rows[0] ?? null;
    }

    public function find(int $id): ?array
    {
        return DB::table('users')->where('user_id', $id)->first();
    }

    /** Alunos e equipe (o administrador principal não aparece na lista). */
    public function allUsers(): array
    {
        return DB::select("SELECT * FROM users WHERE role = 'user' ORDER BY created_at DESC");
    }

    public function usernameTaken(string $username): bool
    {
        return DB::select('SELECT user_id FROM users WHERE LOWER(username) = LOWER(?)', [$username]) !== [];
    }

    public function emailInUse(string $email): bool
    {
        return DB::select("SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND status <> 'recusado'", [$email]) !== [];
    }

    public function create(array $data): int
    {
        return (int) DB::table('users')->insert($data);
    }

    public function update(int $id, array $data): void
    {
        DB::table('users')->where('user_id', $id)->update($data);
    }

    /** user_id => [system_id => [nome do módulo, ...]] */
    public function accessMap(?int $userId = null): array
    {
        $sql = 'SELECT a.user_id, m.system_id, m.name FROM user_module_access a JOIN modules m ON m.module_id = a.module_id';
        $rows = $userId ? DB::select($sql . ' WHERE a.user_id = ? ORDER BY m.position', [$userId]) : DB::select($sql . ' ORDER BY m.position');
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']][$r['system_id']][] = $r['name'];
        }
        return $out;
    }

    public function moduleIds(int $userId): array
    {
        $own = array_map(fn($r) => (int) $r['module_id'], DB::select('SELECT module_id FROM user_module_access WHERE user_id = ?', [$userId]));
        $u = DB::select("SELECT status FROM users WHERE user_id = ?", [$userId]);
        // sistemas abertos para todos: liberados para qualquer usuário aprovado
        return ($u && $u[0]['status'] === 'aprovado') ? array_values(array_unique(array_merge($own, (new SystemRepository())->publicModuleIds()))) : $own;
    }

    public function hasAnyAccess(int $userId): bool
    {
        return DB::select('SELECT 1 FROM user_module_access WHERE user_id = ? LIMIT 1', [$userId]) !== [];
    }

    public function setModuleAccess(int $userId, array $moduleIds, ?int $by): void
    {
        DB::statement('DELETE FROM user_module_access WHERE user_id = ?', [$userId]);
        foreach (array_unique($moduleIds) as $mid) {
            DB::statement('INSERT INTO user_module_access (user_id, module_id, granted_by) VALUES (?, ?, ?)', [$userId, (int) $mid, $by]);
        }
    }

    public function grantSystem(int $userId, string $systemId, ?int $by): void
    {
        DB::statement('INSERT IGNORE INTO user_module_access (user_id, module_id, granted_by) SELECT ?, module_id, ? FROM modules WHERE system_id = ?', [$userId, $by, $systemId]);
    }

    /** Novo módulo: libera para quem já tem acesso a algum módulo do mesmo sistema. */
    public function grantModuleToSystemUsers(int $moduleId, string $systemId, ?int $by): int
    {
        $users = DB::select('SELECT DISTINCT a.user_id FROM user_module_access a JOIN modules m ON m.module_id = a.module_id WHERE m.system_id = ? AND m.module_id <> ?', [$systemId, $moduleId]);
        foreach ($users as $u) {
            DB::statement('INSERT IGNORE INTO user_module_access (user_id, module_id, granted_by) VALUES (?, ?, ?)', [(int) $u['user_id'], $moduleId, $by]);
        }
        return count($users);
    }

    public function permissionsMap(): array
    {
        $out = [];
        foreach (DB::select('SELECT * FROM user_permissions') as $r) {
            $out[(int) $r['user_id']] = $r;
        }
        return $out;
    }

    public function savePermissions(int $userId, array $cols, ?int $by): void
    {
        $exists = DB::table('user_permissions')->where('user_id', $userId)->first();
        $data = $cols + ['updated_by' => $by, 'updated_at' => date('Y-m-d H:i:s')];
        if ($exists) {
            DB::table('user_permissions')->where('user_id', $userId)->update($data);
        } else {
            DB::table('user_permissions')->insert(['user_id' => $userId] + $data);
        }
    }
}
