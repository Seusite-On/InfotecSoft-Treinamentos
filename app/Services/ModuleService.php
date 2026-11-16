<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Repositories\LessonRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\SystemRepository;
use App\Repositories\UserRepository;

class ModuleService
{
    public function __construct(
        private ModuleRepository $modules = new ModuleRepository(),
        private SystemRepository $systems = new SystemRepository(),
        private UserRepository $users = new UserRepository(),
        private LessonRepository $lessons = new LessonRepository(),
        private MediaStorage $storage = new MediaStorage(),
    ) {}

    private function cleanName(string $n): string
    {
        return preg_replace('/\s+/', ' ', trim($n));
    }

    private function cleanIcon(string $i): string
    {
        return preg_match('/^[a-z0-9-]{2,30}$/', $i) ? $i : 'book';
    }

    public function create(string $system, string $name, string $icon, bool $autoGrant): array
    {
        $name = $this->cleanName($name);
        if (!$this->systems->exists($system)) return ['ok' => false, 'message' => 'Sistema inválido.'];
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) return ['ok' => false, 'message' => 'Digite um nome com 3 a 80 caracteres.'];
        if ($this->modules->nameTaken($system, $name)) return ['ok' => false, 'message' => 'Já existe um módulo com esse nome neste sistema.'];
        $id = $this->modules->create(['system_id' => $system, 'name' => $name, 'icon' => $this->cleanIcon($icon),
            'position' => $this->modules->nextPosition($system), 'created_by' => Auth::id(), 'created_at' => now()]);
        $granted = $autoGrant ? $this->users->grantModuleToSystemUsers($id, $system, Auth::id()) : 0;
        return ['ok' => true, 'id' => $id, 'granted' => $granted];
    }

    public function update(int $id, string $name, string $icon): array
    {
        $m = $this->modules->find($id);
        if (!$m) return ['ok' => false, 'status' => 404, 'message' => 'Módulo não encontrado.'];
        $name = $this->cleanName($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) return ['ok' => false, 'message' => 'Digite um nome com 3 a 80 caracteres.'];
        if ($this->modules->nameTaken($m['system_id'], $name, $id)) return ['ok' => false, 'message' => 'Já existe um módulo com esse nome.'];
        $this->modules->update($id, ['name' => $name, 'icon' => $this->cleanIcon($icon), 'updated_at' => now()]);
        return ['ok' => true, 'renamed' => $name !== $m['name']];
    }

    public function move(int $id, int $dir): array
    {
        $m = $this->modules->find($id);
        if (!$m) return ['ok' => false, 'status' => 404, 'message' => 'Módulo não encontrado.'];
        $ids = array_map(fn($r) => (int) $r['module_id'], $this->modules->bySystem($m['system_id']));
        $i = array_search($id, $ids, true);
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($ids)) return ['ok' => true];
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $this->modules->renumber($m['system_id'], $ids);
        return ['ok' => true];
    }

    public function delete(int $id): array
    {
        $m = $this->modules->find($id);
        if (!$m) return ['ok' => false, 'status' => 404, 'message' => 'Módulo não encontrado.'];
        $n = $this->modules->videoCount($id);
        if ($n > 0) return ['ok' => false, 'status' => 409, 'message' => "“{$m['name']}” tem {$n} vídeo(s) publicado(s). Mova ou exclua os vídeos antes."];
        $files = $this->lessons->filesOfModule($id);
        $pdo = DB::connection();
        $pdo->beginTransaction();
        try {
            $this->modules->delete($id); // aulas e permissões do módulo saem junto (ON DELETE CASCADE)
            $rest = array_map(fn($r) => (int) $r['module_id'], $this->modules->bySystem($m['system_id']));
            $this->modules->renumber($m['system_id'], $rest);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        foreach ($files as $f) {
            $this->storage->deleteVideo($f['file_path']);
            $this->storage->deleteThumb($f['thumb_path']);
        }
        return ['ok' => true, 'name' => $m['name']];
    }
}
