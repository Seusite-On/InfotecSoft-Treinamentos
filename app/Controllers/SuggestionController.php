<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DB;
use App\Core\Gate;
use App\Core\RateLimiter;
use App\Core\Request;

/** Sugestões de melhoria do portal (qualquer usuário envia, a equipe acompanha). */
class SuggestionController extends Controller
{
    public const CATEGORIES = ['melhoria', 'problema', 'conteudo', 'outro'];
    public const STATUSES = ['nova', 'analise', 'feita', 'arquivada'];

    public function store(): void
    {
        $me = require_login();
        if (!\App\Core\Gate::allows('sugerir')) json_fail('Você não tem permissão para enviar sugestões.', 403);
        $rl = new RateLimiter();
        $key = 'suggest:' . (int) $me['user_id'];
        if ($rl->tooManyAttempts($key, 10, 3600)) json_fail('Você já enviou várias sugestões agora há pouco. Tente de novo mais tarde.', 429);
        $q = new Request();
        $cat = $q->string('category');
        $msg = trim(preg_replace("/\r\n?/", "\n", (string) $q->input('message', '')));
        if (!in_array($cat, self::CATEGORIES, true)) $cat = 'melhoria';
        if (mb_strlen($msg) < 10) json_fail('Escreva sua sugestão com pelo menos 10 caracteres.', 422, ['field' => 'message']);
        if (mb_strlen($msg) > 2000) json_fail('A sugestão pode ter no máximo 2000 caracteres.', 422, ['field' => 'message']);
        $rl->hit($key, 3600);
        $id = DB::table('suggestions')->insert(['user_id' => (int) $me['user_id'], 'category' => $cat, 'message' => $msg, 'status' => 'nova', 'created_at' => now()]);
        json_out(['ok' => true, 'id' => (string) $id], 201);
    }

    public function status(string $id): void
    {
        require_login('sugestoes');
        $st = (new Request())->string('status');
        if (!in_array($st, self::STATUSES, true)) json_fail('Status inválido.', 422);
        $note = mb_substr(trim((string) (new Request())->input('note', '')), 0, 500);
        if (!DB::table('suggestions')->where('suggestion_id', (int) $id)->first()) json_fail('Sugestão não encontrada.', 404);
        DB::table('suggestions')->where('suggestion_id', (int) $id)->update(['status' => $st, 'admin_note' => $note !== '' ? $note : null, 'updated_at' => now()]);
        json_out(['ok' => true]);
    }

    public function destroy(string $id): void
    {
        require_login('sugestoes');
        DB::table('suggestions')->where('suggestion_id', (int) $id)->delete();
        json_out(['ok' => true]);
    }
}
