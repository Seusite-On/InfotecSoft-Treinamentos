<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DB;
use App\Core\Gate;
use App\Core\RateLimiter;
use App\Repositories\UserRepository;

/**
 * Provas por módulo: perguntas de múltipla escolha corrigidas no servidor.
 * A resposta certa nunca vai para o navegador antes do envio.
 * Pontos da prova só na primeira aprovação.
 */
class QuizController extends Controller
{
    private function quiz(int $moduleId): ?array
    {
        return DB::table('quizzes')->where('module_id', $moduleId)->first();
    }

    private function canSee(int $uid, int $moduleId): bool
    {
        return Gate::allows('modulos') || Gate::allows('videos') || in_array($moduleId, (new UserRepository())->moduleIds($uid), true);
    }

    private function questions(int $quizId): array
    {
        return DB::select('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY position, question_id', [$quizId]);
    }

    /** GET /api/quizzes/{id} — prova do módulo (sem as respostas). */
    public function show(string $id): void
    {
        $me = require_login();
        $uid = (int) $me['user_id'];
        $q = $this->quiz((int) $id);
        if (!$q || (!$q['active'] && !Gate::allows('modulos'))) json_fail('Este módulo ainda não tem prova.', 404);
        if (!$this->canSee($uid, (int) $id)) json_fail('Você não tem acesso a este módulo.', 403);
        $qs = array_map(fn($r) => ['id' => (string) $r['question_id'], 'q' => $r['question'], 'options' => json_decode($r['options'], true) ?: []], $this->questions((int) $q['quiz_id']));
        json_out(['ok' => true, 'quiz' => ['module_id' => (int) $id, 'title' => $q['title'], 'pass_pct' => (int) $q['pass_pct'], 'points' => (int) $q['points'], 'active' => (bool) $q['active'], 'questions' => $qs]]);
    }

    /** POST /api/quizzes/{id}/submit — corrige e registra a tentativa. */
    public function submit(string $id): void
    {
        $me = require_login();
        $uid = (int) $me['user_id'];
        $q = $this->quiz((int) $id);
        if (!$q || !$q['active']) json_fail('Este módulo ainda não tem prova.', 404);
        if (!$this->canSee($uid, (int) $id)) json_fail('Você não tem acesso a este módulo.', 403);
        $rl = new RateLimiter();
        $key = 'quiz:' . $uid;
        if ($rl->tooManyAttempts($key, 30, 3600)) json_fail('Muitas tentativas de prova. Tente de novo mais tarde.', 429);
        $rl->hit($key, 3600);

        $body = json_decode((string) file_get_contents('php://input'), true);
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
        $qs = $this->questions((int) $q['quiz_id']);
        if (!$qs) json_fail('A prova está sem perguntas.', 422);
        $score = 0;
        $res = [];
        foreach ($qs as $r) {
            $k = (string) $r['question_id'];
            $chosen = isset($answers[$k]) && is_numeric($answers[$k]) ? (int) $answers[$k] : -1;
            $ok = $chosen === (int) $r['correct'];
            if ($ok) $score++;
            $res[] = ['id' => $k, 'chosen' => $chosen, 'correct' => (int) $r['correct'], 'ok' => $ok, 'explanation' => $r['explanation'] ?? ''];
        }
        $total = count($qs);
        $passed = $score * 100 >= (int) $q['pass_pct'] * $total;
        $already = DB::select('SELECT 1 FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? AND passed = 1 LIMIT 1', [$q['quiz_id'], $uid]) !== [];
        $points = $passed && !$already ? (int) $q['points'] : 0;
        DB::table('quiz_attempts')->insert(['quiz_id' => $q['quiz_id'], 'user_id' => $uid, 'score' => $score, 'total' => $total, 'passed' => $passed ? 1 : 0, 'points' => $points, 'created_at' => now()]);
        json_out(['ok' => true, 'score' => $score, 'total' => $total, 'passed' => $passed, 'points' => $points, 'already' => $already,
            'pontos_total' => ProgressController::total($uid), 'results' => $res]);
    }

    /** GET /api/quizzes/{id}/edit — prova completa para edição (com respostas). */
    public function edit(string $id): void
    {
        require_login('modulos');
        $m = DB::table('modules')->where('module_id', (int) $id)->first();
        if (!$m) json_fail('Módulo não encontrado.', 404);
        $q = $this->quiz((int) $id);
        json_out(['ok' => true, 'quiz' => $q ? [
            'title' => $q['title'], 'pass_pct' => (int) $q['pass_pct'], 'points' => (int) $q['points'], 'active' => (bool) $q['active'],
            'questions' => array_map(fn($r) => ['q' => $r['question'], 'options' => json_decode($r['options'], true) ?: [], 'correct' => (int) $r['correct'], 'explanation' => $r['explanation'] ?? ''], $this->questions((int) $q['quiz_id'])),
        ] : null]);
    }

    /** POST /api/quizzes/{id} — cria ou substitui a prova do módulo. */
    public function save(string $id): void
    {
        require_login('modulos');
        $m = DB::table('modules')->where('module_id', (int) $id)->first();
        if (!$m) json_fail('Módulo não encontrado.', 404);
        $b = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $title = trim((string) ($b['title'] ?? '')) ?: 'Prova do módulo ' . $m['name'];
        $pass = max(10, min(100, (int) ($b['pass_pct'] ?? 60)));
        $pts = max(0, min(1000, (int) ($b['points'] ?? 30)));
        $qs = is_array($b['questions'] ?? null) ? $b['questions'] : [];
        if (count($qs) < 1 || count($qs) > 30) json_fail('A prova precisa ter de 1 a 30 perguntas.');
        $clean = [];
        foreach ($qs as $i => $x) {
            $text = trim((string) ($x['q'] ?? ''));
            $opts = array_values(array_filter(array_map(fn($o) => mb_substr(trim((string) $o), 0, 200), (array) ($x['options'] ?? [])), fn($o) => $o !== ''));
            $c = (int) ($x['correct'] ?? -1);
            $n = $i + 1;
            if (mb_strlen($text) < 5) json_fail("Pergunta $n: escreva a pergunta.");
            if (count($opts) < 2 || count($opts) > 6) json_fail("Pergunta $n: coloque de 2 a 6 alternativas.");
            if ($c < 0 || $c >= count($opts)) json_fail("Pergunta $n: marque a alternativa certa.");
            $clean[] = [mb_substr($text, 0, 500), json_encode($opts, JSON_UNESCAPED_UNICODE), $c, mb_substr(trim((string) ($x['explanation'] ?? '')), 0, 600)];
        }
        $q = $this->quiz((int) $id);
        $data = ['title' => mb_substr($title, 0, 150), 'pass_pct' => $pass, 'points' => $pts, 'active' => !empty($b['active']) ? 1 : 0, 'updated_at' => now()];
        if ($q) {
            DB::table('quizzes')->where('quiz_id', $q['quiz_id'])->update($data);
            $qid = (int) $q['quiz_id'];
            DB::statement('DELETE FROM quiz_questions WHERE quiz_id = ?', [$qid]);
        } else {
            $qid = (int) DB::table('quizzes')->insert($data + ['module_id' => (int) $id]);
        }
        foreach ($clean as $i => [$t, $o, $c, $e]) {
            DB::table('quiz_questions')->insert(['quiz_id' => $qid, 'position' => $i + 1, 'question' => $t, 'options' => $o, 'correct' => $c, 'explanation' => $e ?: null]);
        }
        json_out(['ok' => true, 'message' => 'Prova salva.']);
    }

    /** POST /api/quizzes/{id}/delete */
    public function destroy(string $id): void
    {
        require_login('modulos');
        DB::statement('DELETE FROM quizzes WHERE module_id = ?', [(int) $id]);
        json_out(['ok' => true, 'message' => 'Prova excluída.']);
    }
}
