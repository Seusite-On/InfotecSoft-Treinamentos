<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Gate;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Repositories\UserRepository;
use App\Services\LessonService;

class LessonController extends Controller
{
    public const POINTS_PER_POST = 5;

    public function save(): void
    {
        $me = require_login();
        $uid = (int) $me['user_id'];
        // Envio de arquivo grande acima do post_max_size chega com $_POST vazio
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
            json_fail('O vídeo é maior que o limite do servidor (' . ini_get('post_max_size') . ').', 413);
        }
        $in = (new Request())->all();
        $staff = Gate::allows('videos');
        if (!$staff && !Gate::allows('postar')) json_fail('Você não tem permissão para postar vídeos.', 403);
        if (!$staff) {
            // Postagem da comunidade: qualquer usuário aprovado publica um vídeo NOVO do YouTube nos módulos que ele acessa.
            if (!empty($_SESSION['must_change'])) json_fail('Crie uma nova senha antes de continuar.', 403, ['code' => 'must_change']);
            $mod = (int) ($in['module_id'] ?? 0);
            if (!in_array($mod, (new UserRepository())->moduleIds($uid), true)) json_fail('Você só pode postar nos módulos liberados para você.', 403);
            $rl = new RateLimiter();
            if ($rl->tooManyAttempts('post:' . $uid, 15, 86400)) json_fail('Limite de 15 postagens por dia atingido. Tente amanhã.', 429);
            $in = ['module_id' => $mod, 'source' => 'youtube', 'youtube_id' => (string) ($in['youtube_id'] ?? ''), 'title' => (string) ($in['title'] ?? ''),
                'description' => (string) ($in['description'] ?? ''), 'duration' => (string) ($in['duration'] ?? ''), 'published' => (string) ($in['published'] ?? '')];
            $_FILES = [];
        }
        $yt = trim((string) ($in['youtube_id'] ?? ''));
        $dup = $yt !== '' && DB::select('SELECT 1 FROM lessons WHERE youtube_id = ? LIMIT 1', [$yt]) !== [];
        if (!$staff && $dup) json_fail('Esse vídeo já está no portal. Procure por ele na busca.', 422, ['errors' => ['vf-url' => 'Esse vídeo já está no portal.']]);
        $r = (new LessonService())->save($in, $_FILES['file'] ?? null);
        if (!$r['ok']) json_out($r, $r['status'] ?? 422);
        if (!$staff) (new RateLimiter())->hit('post:' . $uid, 86400);
        // 5 pontos para quem posta um vídeo que ainda não estava no portal
        $r['points'] = 0;
        if (!empty($r['created']) && ($in['source'] ?? '') !== 'file' && !$dup && !Gate::isAdmin()) {
            DB::table('lessons')->where('lesson_id', (int) $r['id'])->update(['post_points' => self::POINTS_PER_POST]);
            $r['points'] = self::POINTS_PER_POST;
            $r['total'] = ProgressController::total($uid);
        }
        json_out($r);
    }

    public function destroy(string $id): void
    {
        require_login('videos');
        $r = (new LessonService())->deleteLesson((int) $id);
        $r['ok'] ? json_out($r) : json_fail($r['message'], $r['status']);
    }

    public function move(string $id): void
    {
        require_login('videos');
        $r = (new LessonService())->moveLesson((int) $id, (new Request())->int('dir'));
        $r['ok'] ? json_out($r) : json_fail($r['message'], $r['status']);
    }

    public function removeVideo(string $id): void
    {
        require_login('videos');
        $r = (new LessonService())->removeVideo((int) $id);
        $r['ok'] ? json_out($r) : json_fail($r['message'], $r['status']);
    }
}
