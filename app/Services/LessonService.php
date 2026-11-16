<?php

namespace App\Services;

use App\Core\Auth;
use App\Repositories\LessonRepository;
use App\Repositories\ModuleRepository;

/**
 * Publicação de vídeos. Uma aula pode ter vídeo do YouTube, arquivo enviado ou nenhum vídeo.
 * - "Nova aula": cria uma aula (origin = admin) no módulo escolhido.
 * - "Vincular": coloca o vídeo numa aula que já existe (ex.: aula do catálogo inicial).
 * - Edição (edit_id): se o destino mudar, o vídeo sai da aula antiga.
 */
class LessonService
{
    public function __construct(
        private LessonRepository $lessons = new LessonRepository(),
        private ModuleRepository $modules = new ModuleRepository(),
        private MediaStorage $storage = new MediaStorage(),
    ) {}

    public function save(array $in, ?array $upload): array
    {
        $moduleId = (int) ($in['module_id'] ?? 0);
        $targetId = (int) ($in['lesson_id'] ?? 0);
        $editId = (int) ($in['edit_id'] ?? 0);
        $source = ($in['source'] ?? '') === 'file' ? 'file' : 'youtube';
        $title = trim((string) ($in['title'] ?? ''));
        $desc = trim((string) ($in['description'] ?? ''));
        $dur = trim((string) ($in['duration'] ?? ''));
        $yt = trim((string) ($in['youtube_id'] ?? ''));
        $errors = [];

        if (!$this->modules->find($moduleId)) $errors['vf-cat'] = 'Escolha um módulo válido.';
        if ($dur !== '' && !preg_match('/^(\d{1,2}:)?\d{1,3}:[0-5]\d$/', $dur)) $errors['vf-dur'] = 'Use o formato mm:ss.';
        if ($source === 'youtube' && !preg_match('/^[A-Za-z0-9_-]{11}$/', $yt)) $errors['vf-url'] = 'Informe um link válido do YouTube.';

        $edit = $editId ? $this->lessons->find($editId) : null;
        if ($editId && !$edit) return ['ok' => false, 'status' => 404, 'message' => 'O vídeo que você estava editando não existe mais.'];

        if ($targetId) {
            $target = $this->lessons->find($targetId);
            if (!$target || (int) $target['module_id'] !== $moduleId) return ['ok' => false, 'status' => 404, 'message' => 'Aula de destino não encontrada neste módulo.'];
        } elseif ($edit && $edit['origin'] === 'admin') {
            $target = $edit; // a própria aula criada pelo admin (pode mudar de módulo)
        } else {
            $target = null; // nova aula
        }
        $isNewOrAdmin = !$target || $target['origin'] === 'admin';
        if ($isNewOrAdmin && mb_strlen($title) < 3) $errors['vf-title'] = 'Informe um título com pelo menos 3 caracteres.';

        $hasUpload = $upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $reuse = null; // arquivo já existente que continua valendo
        if ($source === 'file' && !$hasUpload) {
            if ($target && $target['video_source'] === 'file') $reuse = $target;
            elseif ($edit && $edit['video_source'] === 'file') $reuse = $edit;
            else $errors['vf-fi'] = 'Escolha o arquivo de vídeo no seu computador.';
        }
        if ($errors) return ['ok' => false, 'status' => 422, 'errors' => $errors, 'message' => reset($errors)];

        $now = now();
        $data = ['module_id' => $moduleId, 'updated_at' => $now, 'video_updated_at' => $now, 'video_source' => $source];
        if ($isNewOrAdmin) {
            $data['title'] = mb_substr($title, 0, 150);
            $data['description'] = mb_substr($desc, 0, 2000);
            $data['duration'] = $dur ?: null;
        } else { // aula do catálogo: título/descrição em branco mantêm os originais
            if ($title !== '') $data['title'] = mb_substr($title, 0, 150);
            if ($desc !== '') $data['description'] = mb_substr($desc, 0, 2000);
            if ($dur !== '') $data['duration'] = $dur;
        }

        if (!$target) {
            $data += ['position' => $this->lessons->nextPosition($moduleId), 'origin' => 'admin', 'created_by' => Auth::id(), 'created_at' => $now];
            $id = $this->lessons->create($data + ['title' => $data['title'] ?? 'Aula']);
            $target = $this->lessons->find($id);
        } else {
            $id = (int) $target['lesson_id'];
            if ((int) $target['module_id'] !== $moduleId) $data['position'] = $this->lessons->nextPosition($moduleId);
        }
        $old = $target;

        if ($source === 'youtube') {
            $data += ['youtube_id' => $yt, 'file_path' => null, 'file_name' => null, 'file_size' => null, 'file_mime' => null, 'thumb_path' => null];
        } elseif ($hasUpload) {
            $st = $this->storage->storeVideo($upload, $id);
            if (!$st['ok']) {
                if ($old['created_at'] === $now && $old['origin'] === 'admin' && $old['video_source'] === 'none') $this->lessons->delete($id);
                return ['ok' => false, 'status' => 422, 'errors' => ['vf-fi' => $st['message']], 'message' => $st['message']];
            }
            $thumb = $this->storage->storeThumb((string) ($in['thumb'] ?? ''), $id);
            $data += ['youtube_id' => null, 'file_path' => $st['path'], 'file_name' => $st['name'], 'file_size' => $st['size'], 'file_mime' => $st['mime'], 'thumb_path' => $thumb];
        } else { // mantém (ou transfere) o arquivo já enviado
            $data += ['youtube_id' => null, 'file_path' => $reuse['file_path'], 'file_name' => $reuse['file_name'], 'file_size' => $reuse['file_size'],
                'file_mime' => $reuse['file_mime'], 'thumb_path' => $reuse['thumb_path']];
            $newThumb = $this->storage->storeThumb((string) ($in['thumb'] ?? ''), $id);
            if ($newThumb) $data['thumb_path'] = $newThumb;
        }
        $this->lessons->update($id, $data);

        // apaga arquivos que deixaram de ser usados
        $keep = [$data['file_path'] ?? null, $data['thumb_path'] ?? null];
        foreach ([$old, $edit] as $l) {
            if ($l && !in_array($l['file_path'], $keep, true)) $this->storage->deleteVideo($l['file_path']);
            if ($l && !in_array($l['thumb_path'], $keep, true)) $this->storage->deleteThumb($l['thumb_path']);
        }
        // edição que mudou de destino: o vídeo sai da aula antiga
        if ($edit && (int) $edit['lesson_id'] !== $id) {
            if ($edit['origin'] === 'admin') $this->lessons->delete((int) $edit['lesson_id']);
            else $this->lessons->update((int) $edit['lesson_id'], ['video_source' => 'none', 'youtube_id' => null, 'file_path' => null, 'file_name' => null,
                'file_size' => null, 'file_mime' => null, 'thumb_path' => null, 'updated_at' => $now]);
        }
        return ['ok' => true, 'id' => (string) $id, 'updated' => (bool) $editId];
    }

    /** Exclui a aula inteira (com ou sem vídeo). */
    public function deleteLesson(int $id): array
    {
        $l = $this->lessons->find($id);
        if (!$l) return ['ok' => false, 'status' => 404, 'message' => 'Aula não encontrada.'];
        $this->lessons->delete($id);
        $this->storage->deleteVideo($l['file_path']);
        $this->storage->deleteThumb($l['thumb_path']);
        return ['ok' => true];
    }

    /** Sobe (-1) ou desce (+1) a aula dentro do módulo. */
    public function moveLesson(int $id, int $dir): array
    {
        $l = $this->lessons->find($id);
        if (!$l) return ['ok' => false, 'status' => 404, 'message' => 'Aula não encontrada.'];
        $list = \App\Core\DB::select('SELECT lesson_id FROM lessons WHERE module_id = ? ORDER BY position, lesson_id', [(int) $l['module_id']]);
        $ids = array_map(fn($r) => (int) $r['lesson_id'], $list);
        $i = array_search($id, $ids, true);
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($ids)) return ['ok' => true];
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach ($ids as $pos => $lid) $this->lessons->update($lid, ['position' => $pos + 1]);
        return ['ok' => true];
    }

    public function removeVideo(int $id): array
    {
        $l = $this->lessons->find($id);
        if (!$l) return ['ok' => false, 'status' => 404, 'message' => 'Aula não encontrada.'];
        if ($l['origin'] === 'admin') {
            $this->lessons->delete($id);
        } else {
            $this->lessons->update($id, ['video_source' => 'none', 'youtube_id' => null, 'file_path' => null, 'file_name' => null,
                'file_size' => null, 'file_mime' => null, 'thumb_path' => null, 'updated_at' => now()]);
        }
        $this->storage->deleteVideo($l['file_path']);
        $this->storage->deleteThumb($l['thumb_path']);
        return ['ok' => true];
    }
}
