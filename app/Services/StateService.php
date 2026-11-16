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
        $systems = array_map(fn($s) => ['id' => $s['system_id'], 'name' => $s['name'], 'desc' => $s['description']], $this->systems->all());
        $state = ['ok' => true, 'csrf' => CSRF::token(), 'me' => null, 'systems' => $systems];
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
        $access = $isAdmin ? [] : ($this->users->accessMap($uid)[$uid] ?? []);
        $state['me'] = [
            'id' => (string) $uid, 'nome' => $me['name'], 'empresa' => $me['company_name'] ?? '', 'usuario' => $me['username'],
            'admin' => $isAdmin, 'must_change' => !empty($_SESSION['must_change']), 'adm' => $isAdmin ? [] : $adm, 'perms' => $isAdmin ? null : (object) $access,
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
        $state['lessons'] = array_map(function ($l) use ($seeAll, $allowed) {
            $ok = $seeAll || isset($allowed[(int) $l['module_id']]);
            $id = (int) $l['lesson_id'];
            return [
                'id' => (string) $id, 'module_id' => (int) $l['module_id'], 'title' => $l['title'], 'description' => $l['description'] ?? '',
                'duration' => $l['duration'] ?? '', 'position' => (int) $l['position'], 'origin' => $l['origin'],
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
                    if ((int) ($permsAll[$id][$col] ?? 0) === 1) {
                        $adm[] = $code;
                    }
                }
                return [
                    'id' => (string) $id, 'nome' => $u['name'], 'empresa' => $u['company_name'] ?? '', 'doc' => $u['document'] ?? '',
                    'email' => $u['email'], 'sistema' => $u['requested_system_id'] ?? '', 'usuario' => $u['username'], 'status' => $u['status'],
                    'revogado' => (bool) $u['revoked'], 'criado' => ms($u['created_at']), 'decidido' => ms($u['decided_at']),
                    'perms' => (object) ($accessAll[$id] ?? []), 'adm' => $adm,
                ];
            }, $this->users->allUsers());
        } else {
            $state['users'] = [];
        }
        return $state;
    }
}
