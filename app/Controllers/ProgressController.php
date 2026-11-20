<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Gate;
use App\Core\Request;
use App\Repositories\UserRepository;

/**
 * Pontuação: cada vídeo assistido até o fim vale pontos (uma vez por aula).
 * O navegador avisa "start" quando o vídeo começa e "done" quando chega perto do fim;
 * o servidor confere se passou tempo suficiente desde o início antes de dar os pontos.
 */
class ProgressController extends Controller
{
    public const POINTS_PER_VIDEO = 10;

    public function track(string $id): void
    {
        $me = require_login();
        $uid = (int) $me['user_id'];
        $l = DB::table('lessons')->where('lesson_id', (int) $id)->first();
        if (!$l || $l['video_source'] === 'none') json_fail('Aula não encontrada.', 404);
        $can = Gate::allows('videos') || Gate::allows('modulos') || in_array((int) $l['module_id'], (new UserRepository())->moduleIds($uid), true);
        if (!$can) json_fail('Você não tem acesso a esta aula.', 403);

        $ev = (new Request())->string('event');
        $row = DB::table('lesson_views')->where('user_id', $uid)->where('lesson_id', (int) $id)->first();

        if ($ev === 'start') {
            if (!$row) {
                DB::table('lesson_views')->insert(['user_id' => $uid, 'lesson_id' => (int) $id, 'started_at' => now(), 'points' => 0]);
            }
            // reabrir a aula não zera o início: o tempo assistido antes continua valendo
            json_out(['ok' => true, 'done' => (bool) ($row['completed_at'] ?? false)]);
        }

        if ($ev === 'done') {
            if ($row && $row['completed_at']) json_out(['ok' => true, 'already' => true, 'points' => 0, 'total' => self::total($uid)]);
            if (!$row) json_fail('Assista o vídeo para ganhar os pontos.', 422, ['code' => 'not_started']);
            // tempo mínimo: metade da duração do vídeo (até 20 min); sem duração conhecida, 30 s
            $sec = self::seconds((string) ($l['duration'] ?? ''));
            $min = $sec > 0 ? min((int) floor($sec * 0.5), 1200) : 30;
            $elapsed = time() - strtotime((string) $row['started_at']);
            if ($elapsed < $min) json_fail('Continue assistindo para ganhar os pontos.', 422, ['code' => 'too_fast', 'wait' => $min - $elapsed]);
            DB::table('lesson_views')->where('user_id', $uid)->where('lesson_id', (int) $id)->update(['completed_at' => now(), 'points' => self::POINTS_PER_VIDEO]);
            json_out(['ok' => true, 'points' => self::POINTS_PER_VIDEO, 'total' => self::total($uid)]);
        }
        json_fail('Evento inválido.', 422);
    }

    public static function total(int $uid): int
    {
        $r = DB::select('SELECT (SELECT COALESCE(SUM(points),0) FROM lesson_views WHERE user_id = ?) + (SELECT COALESCE(SUM(points),0) FROM quiz_attempts WHERE user_id = ?) + (SELECT COALESCE(SUM(post_points),0) FROM lessons WHERE created_by = ?) AS t', [$uid, $uid, $uid]);
        return (int) ($r[0]['t'] ?? 0);
    }

    public static function seconds(string $dur): int
    {
        if (!preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', trim($dur), $m)) return 0;
        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
    }
}
