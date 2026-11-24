<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DB;
use App\Core\Gate;
use App\Core\RateLimiter;
use App\Repositories\UserRepository;

/** Anotações pessoais por aula. Cada usuário só lê, edita e apaga as próprias. */
class NoteController extends Controller
{
    private const MAX_LEN = 2000;
    private const MAX_PER_LESSON = 200;

    private function me(): int
    {
        $me = require_login();
        if (!Gate::allows('anotar')) json_fail('Você não tem permissão para fazer anotações.', 403);
        return (int) $me['user_id'];
    }

    private function lesson(int $uid, int $id): array
    {
        $l = DB::table('lessons')->where('lesson_id', $id)->first();
        if (!$l) json_fail('Aula não encontrada.', 404);
        $can = Gate::allows('videos') || Gate::allows('modulos') || in_array((int) $l['module_id'], (new UserRepository())->moduleIds($uid), true);
        if (!$can) json_fail('Você não tem acesso a esta aula.', 403);
        return $l;
    }

    private function body(): array
    {
        $b = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $text = trim(str_replace("\r\n", "\n", (string) ($b['body'] ?? '')));
        if ($text === '') json_fail('Escreva a anotação.', 422);
        if (mb_strlen($text) > self::MAX_LEN) json_fail('A anotação pode ter até ' . self::MAX_LEN . ' caracteres.', 422);
        $t = isset($b['t']) && is_numeric($b['t']) ? max(0, min(86400, (int) $b['t'])) : null;
        return [$text, $t];
    }

    private static function out(array $r): array
    {
        return ['id' => (string) $r['note_id'], 'lesson_id' => (string) $r['lesson_id'], 'body' => $r['body'], 't' => $r['video_time'] === null ? null : (int) $r['video_time'],
            'criado' => ms($r['created_at']), 'editado' => ms($r['updated_at'])];
    }

    /** GET /api/lessons/{id}/notes */
    public function index(string $id): void
    {
        $uid = $this->me();
        $this->lesson($uid, (int) $id);
        $rows = DB::select('SELECT * FROM lesson_notes WHERE user_id = ? AND lesson_id = ? ORDER BY COALESCE(video_time, 999999), created_at', [$uid, (int) $id]);
        json_out(['ok' => true, 'notes' => array_map([self::class, 'out'], $rows)]);
    }

    /** POST /api/lessons/{id}/notes */
    public function store(string $id): void
    {
        $uid = $this->me();
        $this->lesson($uid, (int) $id);
        $rl = new RateLimiter();
        if ($rl->tooManyAttempts('notes:' . $uid, 300, 3600)) json_fail('Muitas anotações em pouco tempo. Tente de novo mais tarde.', 429);
        $n = (int) (DB::select('SELECT COUNT(*) AS n FROM lesson_notes WHERE user_id = ? AND lesson_id = ?', [$uid, (int) $id])[0]['n'] ?? 0);
        if ($n >= self::MAX_PER_LESSON) json_fail('Limite de ' . self::MAX_PER_LESSON . ' anotações nesta aula.', 422);
        [$text, $t] = $this->body();
        $rl->hit('notes:' . $uid, 3600);
        $nid = (int) DB::table('lesson_notes')->insert(['user_id' => $uid, 'lesson_id' => (int) $id, 'body' => $text, 'video_time' => $t, 'created_at' => now()]);
        json_out(['ok' => true, 'note' => self::out(DB::table('lesson_notes')->where('note_id', $nid)->first())]);
    }

    private function own(int $uid, int $nid): array
    {
        $n = DB::table('lesson_notes')->where('note_id', $nid)->first();
        // anotação de outra pessoa = "não encontrada" (não revela que existe)
        if (!$n || (int) $n['user_id'] !== $uid) json_fail('Anotação não encontrada.', 404);
        return $n;
    }

    /** POST /api/notes/{id} */
    public function update(string $id): void
    {
        $uid = $this->me();
        $this->own($uid, (int) $id);
        [$text, $t] = $this->body();
        DB::table('lesson_notes')->where('note_id', (int) $id)->update(['body' => $text, 'video_time' => $t, 'updated_at' => now()]);
        json_out(['ok' => true, 'note' => self::out(DB::table('lesson_notes')->where('note_id', (int) $id)->first())]);
    }

    /** POST /api/notes/{id}/delete */
    public function destroy(string $id): void
    {
        $uid = $this->me();
        $this->own($uid, (int) $id);
        DB::statement('DELETE FROM lesson_notes WHERE note_id = ? AND user_id = ?', [(int) $id, $uid]);
        json_out(['ok' => true]);
    }
}
