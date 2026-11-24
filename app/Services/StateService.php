<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\CSRF;
use App\Core\Gate;
use App\Repositories\LessonRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\SystemRepository;
use App\Repositories\UserRepository;

/** Monta tudo o que a tela precisa em uma única resposta (GET /api/state). */
class StateService
{
    public function __construct(
        private SystemRepository $systems = new SystemRepository(),
        private ModuleRepository $modules = new ModuleRepository(),
        private LessonRepository $lessons = new LessonRepository(),
        private UserRepository $users = new UserRepository(),
    ) {}

    public function build(): array
    {
        $systems = array_map(fn($s) => ['id' => $s['system_id'], 'name' => $s['name'], 'desc' => $s['description'], 'icon' => $s['icon'] ?? null, 'color' => $s['color'] ?? null, 'public' => (bool) ($s['is_public'] ?? 0)], $this->systems->all());
        $state = ['ok' => true, 'csrf' => CSRF::token(), 'me' => null, 'systems' => $systems, 'versao' => \App\Controllers\AppController::version()];
        // Números reais para a tela de login (sem dados pessoais).
        $st = \App\Core\DB::select("SELECT (SELECT COUNT(*) FROM lessons WHERE video_source <> 'none') AS v, (SELECT COUNT(*) FROM modules) AS m")[0] ?? [];
        $state['stats'] = ['videos' => (int) ($st['v'] ?? 0), 'modules' => (int) ($st['m'] ?? 0), 'systems' => count($systems)];

        $sess = Auth::user();
        $me = $sess ? $this->users->find((int) $sess['user_id']) : null;
        if (!$me || $me['status'] !== 'aprovado') {
            if ($sess) {
                Auth::logout();
                $state['csrf'] = CSRF::token();
            }
            return $state;
        }

        $uid = (int) $me['user_id'];
        $isAdmin = $me['role'] === 'admin';
        $adm = $isAdmin ? array_keys(Gate::MAP) : Gate::codesFor($uid);
        $isSuper = !$isAdmin && Gate::isSuper($uid);
        $access = ($isAdmin || $isSuper) ? [] : ($this->users->accessMap($uid)[$uid] ?? []);
        // sistemas abertos para todos: os módulos entram no acesso de qualquer usuário aprovado
        if (!$isAdmin && !$isSuper && ($me['status'] ?? '') === 'aprovado') {
            foreach (\App\Core\DB::select('SELECT m.system_id, m.name FROM modules m JOIN systems s ON s.system_id = m.system_id WHERE s.is_public = 1 AND s.active = 1') as $pm) {
                if (!in_array($pm['name'], $access[$pm['system_id']] ?? [], true)) $access[$pm['system_id']][] = $pm['name'];
            }
        }
        $state['me'] = [
            'id' => (string) $uid, 'nome' => $me['name'], 'empresa' => $me['company_name'] ?? '', 'usuario' => $me['username'],
            'admin' => $isAdmin, 'must_change' => !empty($_SESSION['must_change']), 'adm' => $isAdmin ? [] : $adm, 'perms' => ($isAdmin || $isSuper) ? null : (object) $access, 'super' => $isSuper,
        ];

        $modules = $this->modules->all();
        $state['modules'] = array_map(fn($m) => [
            'id' => (int) $m['module_id'], 'system' => $m['system_id'], 'name' => $m['name'], 'icon' => $m['icon'], 'position' => (int) $m['position'],
        ], $modules);

        // Quem gerencia conteúdo vê tudo; o aluno só recebe o vídeo dos módulos liberados.
        $seeAll = $isAdmin || in_array('videos', $adm, true) || in_array('modulos', $adm, true);
        $allowed = [];
        if (!$seeAll) {
            $byName = [];
            foreach ($modules as $m) {
                $byName[$m['system_id']][$m['name']] = (int) $m['module_id'];
            }
            foreach ($access as $sys => $names) {
                foreach ($names as $n) {
                    if (isset($byName[$sys][$n])) {
                        $allowed[$byName[$sys][$n]] = true;
                    }
                }
            }
        }
        $autores = [];
        foreach (\App\Core\DB::select("SELECT DISTINCT u.user_id, u.name FROM lessons l JOIN users u ON u.user_id = l.created_by WHERE u.role <> 'admin'") as $a) $autores[(int) $a['user_id']] = $a['name'];
        $state['lessons'] = array_map(function ($l) use ($seeAll, $allowed, $autores) {
            $ok = $seeAll || isset($allowed[(int) $l['module_id']]);
            $id = (int) $l['lesson_id'];
            return [
                'id' => (string) $id, 'module_id' => (int) $l['module_id'], 'title' => $l['title'], 'description' => $l['description'] ?? '',
                'autor' => $autores[(int) ($l['created_by'] ?? 0)] ?? '', 'autor_id' => isset($autores[(int) ($l['created_by'] ?? 0)]) ? (string) $l['created_by'] : '', 'post_pts' => (int) ($l['post_points'] ?? 0),
                'duration' => $l['duration'] ?? '', 'published' => $l['published_at'] ?? '', 'position' => (int) $l['position'], 'origin' => $l['origin'],
                'source' => $ok ? $l['video_source'] : 'none', 'locked' => !$ok,
                'yt' => $ok && $l['video_source'] === 'youtube' ? $l['youtube_id'] : '',
                'file' => $ok && $l['video_source'] === 'file',
                'fname' => $ok ? ($l['file_name'] ?? '') : '', 'fsize' => $ok ? (int) ($l['file_size'] ?? 0) : 0,
                'thumb' => $l['thumb_path'] ? '/media/thumb/' . $id . '?v=' . substr(md5((string) $l['thumb_path']), 0, 8) : '',
                'created' => ms($l['created_at']), 'updated' => ms($l['updated_at']), 'video_at' => ms($l['video_updated_at']),
            ];
        }, $this->lessons->all());

        if (Gate::allows('aprovacoes') || Gate::allows('usuarios')) {
            $accessAll = $this->users->accessMap();
            $permsAll = $this->users->permissionsMap();
            $state['users'] = array_map(function ($u) use ($accessAll, $permsAll) {
                $id = (int) $u['user_id'];
                $adm = [];
                foreach (Gate::MAP as $code => $col) {
                    // sem registro salvo: recursos de usuário comum vêm ligados (mesma regra do Gate)
                    $def = in_array($code, Gate::USER_FEATURES, true) ? 1 : 0;
                    if ((int) ($permsAll[$id][$col] ?? $def) === 1) {
                        $adm[] = $code;
                    }
                }
                return [
                    'id' => (string) $id, 'nome' => $u['name'], 'empresa' => $u['company_name'] ?? '', 'doc' => $u['document'] ?? '',
                    'email' => $u['email'], 'sistema' => !empty($u['requested_all']) ? 'todos' : ($u['requested_system_id'] ?? ''), 'usuario' => $u['username'], 'status' => $u['status'],
                    'revogado' => (bool) $u['revoked'], 'troca_senha' => (bool) ($u['must_change_password'] ?? 0), 'criado' => ms($u['created_at']), 'decidido' => ms($u['decided_at']),
                    'perms' => (object) ($accessAll[$id] ?? []), 'adm' => $adm, 'super' => (int) ($permsAll[$id]['is_super'] ?? 0) === 1,
                ];
            }, $this->users->allUsers());
        } else {
            $state['users'] = [];
        }
        // Pontuação do usuário: aulas concluídas e total de pontos
        $views = \App\Core\DB::select('SELECT lesson_id, points FROM lesson_views WHERE user_id = ? AND completed_at IS NOT NULL', [$uid]);
        $state['me']['pontos'] = array_sum(array_map(fn($v) => (int) $v['points'], $views));
        $state['me']['assistidas'] = array_map(fn($v) => (string) $v['lesson_id'], $views);
        $state['pontos_por_video'] = \App\Controllers\ProgressController::POINTS_PER_VIDEO;
        $state['me']['pontos'] = \App\Controllers\ProgressController::total($uid);
        $state['me']['notif_visto'] = ms($me['notif_seen_at'] ?? null);
        $state['me']['notif_lidas'] = array_map(fn($r) => (string) $r['lesson_id'], \App\Core\DB::select('SELECT lesson_id FROM notif_reads WHERE user_id = ? AND read_at >= (NOW() - INTERVAL 60 DAY)', [$uid]));
        $state['me']['postagens'] = (int) (\App\Core\DB::select('SELECT COUNT(*) AS n FROM lessons WHERE created_by = ? AND post_points > 0', [$uid])[0]['n'] ?? 0);
        $state['pontos_por_postagem'] = \App\Controllers\LessonController::POINTS_PER_POST;
        $lv = \App\Core\DB::select('SELECT lesson_id, started_at FROM lesson_views WHERE user_id = ? ORDER BY started_at DESC LIMIT 1', [$uid]);
        $state['me']['ultima'] = $lv ? ['lesson_id' => (string) $lv[0]['lesson_id'], 'em' => ms($lv[0]['started_at'])] : null;
        $state['me']['provas'] = array_map(fn($a) => ['module_id' => (int) $a['module_id'], 'best' => (int) round((float) $a['best']), 'passed' => (bool) $a['passed'], 'tentativas' => (int) $a['tentativas']],
            \App\Core\DB::select('SELECT z.module_id, MAX(a.score * 100 / a.total) AS best, MAX(a.passed) AS passed, COUNT(*) AS tentativas FROM quiz_attempts a JOIN quizzes z ON z.quiz_id = a.quiz_id WHERE a.user_id = ? GROUP BY z.module_id', [$uid]));
        // Provas existentes (por módulo)
        $state['quizzes'] = array_map(fn($z) => ['module_id' => (int) $z['module_id'], 'title' => $z['title'], 'n' => (int) $z['n'], 'pass_pct' => (int) $z['pass_pct'], 'points' => (int) $z['points'], 'active' => (bool) $z['active']],
            \App\Core\DB::select('SELECT z.*, (SELECT COUNT(*) FROM quiz_questions q WHERE q.quiz_id = z.quiz_id) AS n FROM quizzes z' . ($isAdmin || \App\Core\Gate::allows('provas') ? '' : ' WHERE z.active = 1')));

        // Últimos acessos (permissão própria)
        if (Gate::allows('acessos')) {
            $state['access'] = array_map(fn($r) => [
                'id' => (string) $r['log_id'], 'ev' => $r['event'], 'nome' => $r['name'] ?? '', 'usuario' => $r['username'] ?? '', 'empresa' => $r['company_name'] ?? '',
                'ip' => $r['ip'] ?? '', 'ua' => $r['user_agent'] ?? '', 'em' => ms($r['created_at']), 'uid' => $r['user_id'] ? (string) $r['user_id'] : '',
            ], \App\Core\DB::select('SELECT a.*, u.name, u.company_name FROM access_logs a LEFT JOIN users u ON u.user_id = a.user_id ORDER BY a.log_id DESC LIMIT 300'));
        }
        // Sugestões: a equipe vê todas, cada usuário vê as próprias
        $isTeam = Gate::allows('sugestoes');
        $rows = $isTeam
            ? \App\Core\DB::select('SELECT s.*, u.name, u.company_name FROM suggestions s JOIN users u ON u.user_id = s.user_id ORDER BY s.created_at DESC LIMIT 300')
            : \App\Core\DB::select('SELECT s.*, NULL AS name, NULL AS company_name FROM suggestions s WHERE s.user_id = ? ORDER BY s.created_at DESC LIMIT 50', [$uid]);
        $state['suggestions'] = array_map(fn($r) => [
            'id' => (string) $r['suggestion_id'], 'cat' => $r['category'], 'msg' => $r['message'], 'status' => $r['status'], 'note' => $r['admin_note'] ?? '',
            'nome' => $r['name'] ?? '', 'empresa' => $r['company_name'] ?? '', 'mine' => (int) $r['user_id'] === $uid,
            'criado' => ms($r['created_at']), 'atualizado' => ms($r['updated_at']),
        ], $rows);
        return $state;
    }
}
