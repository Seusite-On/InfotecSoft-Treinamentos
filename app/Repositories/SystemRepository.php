<?php

namespace App\Repositories;

use App\Core\DB;

class SystemRepository
{
    public function all(): array
    {
        return DB::table('systems')->where('active', 1)->orderBy('position', 'ASC')->get();
    }

    public function exists(string $id): bool
    {
        return (bool) DB::table('systems')->where('system_id', $id)->where('active', 1)->first();
    }

    public function find(string $id): ?array
    {
        return DB::table('systems')->where('system_id', $id)->first();
    }

    /** Módulos dos sistemas abertos para todos os usuários aprovados. */
    public function publicModuleIds(): array
    {
        return array_map(fn($r) => (int) $r['module_id'], DB::select('SELECT m.module_id FROM modules m JOIN systems s ON s.system_id = m.system_id WHERE s.is_public = 1 AND s.active = 1'));
    }
}
