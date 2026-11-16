<?php

namespace App\Repositories;

use App\Core\DB;

class ModuleRepository
{
    public function all(): array
    {
        return DB::select('SELECT m.* FROM modules m JOIN systems s ON s.system_id = m.system_id WHERE s.active = 1 ORDER BY s.position, m.position, m.module_id');
    }

    public function find(int $id): ?array
    {
        return DB::table('modules')->where('module_id', $id)->first();
    }

    public function bySystem(string $systemId): array
    {
        return DB::table('modules')->where('system_id', $systemId)->orderBy('position', 'ASC')->orderBy('module_id', 'ASC')->get();
    }

    public function nameTaken(string $systemId, string $name, int $exceptId = 0): bool
    {
        $rows = DB::select('SELECT module_id FROM modules WHERE system_id = ? AND LOWER(name) = LOWER(?) AND module_id <> ?', [$systemId, $name, $exceptId]);
        return $rows !== [];
    }

    public function nextPosition(string $systemId): int
    {
        return (int) (DB::select('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM modules WHERE system_id = ?', [$systemId])[0]['p'] ?? 1);
    }

    public function create(array $data): int
    {
        return (int) DB::table('modules')->insert($data);
    }

    public function update(int $id, array $data): void
    {
        DB::table('modules')->where('module_id', $id)->update($data);
    }

    public function delete(int $id): void
    {
        DB::table('modules')->where('module_id', $id)->delete();
    }

    /** Renumera as posições de um sistema (1, 2, 3...) na ordem atual. */
    public function renumber(string $systemId, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $i => $id) {
            DB::statement('UPDATE modules SET position = ? WHERE module_id = ? AND system_id = ?', [$i + 1, (int) $id, $systemId]);
        }
    }

    public function videoCount(int $moduleId): int
    {
        return (int) (DB::select("SELECT COUNT(*) AS n FROM lessons WHERE module_id = ? AND video_source <> 'none'", [$moduleId])[0]['n'] ?? 0);
    }
}
