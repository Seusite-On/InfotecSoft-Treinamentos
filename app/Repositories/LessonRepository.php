<?php

namespace App\Repositories;

use App\Core\DB;

class LessonRepository
{
    public function all(): array
    {
        return DB::select('SELECT * FROM lessons ORDER BY module_id, position, lesson_id');
    }

    public function find(int $id): ?array
    {
        return DB::table('lessons')->where('lesson_id', $id)->first();
    }

    public function nextPosition(int $moduleId): int
    {
        return (int) (DB::select('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM lessons WHERE module_id = ?', [$moduleId])[0]['p'] ?? 1);
    }

    public function create(array $data): int
    {
        return (int) DB::table('lessons')->insert($data);
    }

    public function update(int $id, array $data): void
    {
        DB::table('lessons')->where('lesson_id', $id)->update($data);
    }

    public function delete(int $id): void
    {
        DB::table('lessons')->where('lesson_id', $id)->delete();
    }

    public function filesOfModule(int $moduleId): array
    {
        return DB::select('SELECT file_path, thumb_path FROM lessons WHERE module_id = ?', [$moduleId]);
    }
}
