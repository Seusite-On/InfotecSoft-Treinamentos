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
                // INSERT IGNORE: duas abas abrindo a mesma aula ao mesmo tempo não dão erro
                DB::statement('INSERT IGNORE INTO lesson_views (user_id, lesson_id, started_at, points) VALUES (?, ?, ?, 0)', [$uid, (int) $id, now()]);
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
            // só a primeira conclusão marca os pontos (atômico: duas requisições juntas não somam em dobro)
            $n = DB::affecting('UPDATE lesson_views SET completed_at = ?, points = ? WHERE user_id = ? AND lesson_id = ? AND completed_at IS NULL', [now(), self::POINTS_PER_VIDEO, $uid, (int) $id]);
            if (!$n) json_out(['ok' => true, 'already' => true, 'points' => 0, 'total' => self::total($uid)]);
            json_out(['ok' => true, 'points' => self::POINTS_PER_VIDEO, 'total' => self::total($uid)]);
        }
        json_fail('Evento inválido.', 422);
    }

    /** Marca as notificações como vistas (o sino zera o contador de vídeos novos). */
    public function notifSeen(): void
    {
        $me = require_login();
        $uid = (int) $me['user_id'];
        DB::table('users')->where('user_id', $uid)->update(['notif_seen_at' => now()]);
        // também grava cada vídeo que estava na lista como lido (não volta a aparecer)
        $body = json_decode((string) file_get_contents('php://input'), true);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($body['ids'] ?? [])), fn($i) => $i > 0))), 0, 500);
        foreach ($ids as $lid) {
            DB::statement('INSERT IGNORE INTO notif_reads (user_id, lesson_id, read_at) SELECT ?, lesson_id, ? FROM lessons WHERE lesson_id = ?', [$uid, now(), $lid]);
        }
        json_out(['ok' => true, 'em' => time() * 1000, 'lidas' => count($ids)]);
    }

    /** Uma notificação lida: o vídeo sai da lista de novos daquele usuário. */
    public function notifRead(string $id): void
    {
        $me = require_login();
        if (!DB::table('lessons')->where('lesson_id', (int) $id)->first()) json_fail('Aula não encontrada.', 404);
        DB::statement('INSERT IGNORE INTO notif_reads (user_id, lesson_id, read_at) VALUES (?, ?, ?)', [(int) $me['user_id'], (int) $id, now()]);
        json_out(['ok' => true]);
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
