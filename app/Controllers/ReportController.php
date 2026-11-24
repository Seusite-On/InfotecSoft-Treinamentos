<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DB;
use App\Core\Gate;
use App\Core\Request;

/** Relatórios: ranking de pontos e progresso de cada usuário (permissão "relatorios"). */
class ReportController extends Controller
{
    public function ranking(): void
    {
        require_login('relatorios');
        $q = new Request();
        $days = max(0, min(3650, $q->int('dias')));
        $sys = $q->string('sistema');
        $wv = 'v.completed_at IS NOT NULL'; $av = [];
        $wq = 'a.points > 0'; $aq = [];
        $wp = 'l.post_points > 0'; $ap = [];
        // período: datas escolhidas (de/até, inclusive) ou "últimos N dias"
        $de = $q->string('de'); $ate = $q->string('ate');
        $de = preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) ? $de . ' 00:00:00' : '';
        $ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) ? $ate . ' 23:59:59' : '';
        if ($de !== '' && $ate !== '' && $de > $ate) json_fail('A data inicial é depois da data final.', 422);
        if ($de === '' && $ate === '' && $days > 0) $de = date('Y-m-d H:i:s', time() - $days * 86400);
        foreach ([['v.completed_at', 'wv', 'av'], ['a.created_at', 'wq', 'aq'], ['l.created_at', 'wp', 'ap']] as [$col, $w, $a]) {
            if ($de !== '') { $$w .= " AND $col >= ?"; $$a[] = $de; }
            if ($ate !== '') { $$w .= " AND $col <= ?"; $$a[] = $ate; }
        }
        // por usuário
        $uid = $q->int('usuario');
        if ($uid > 0) { $wv .= ' AND v.user_id = ?'; $av[] = $uid; $wq .= ' AND a.user_id = ?'; $aq[] = $uid; $wp .= ' AND l.created_by = ?'; $ap[] = $uid; }
        // um ou vários sistemas (separados por vírgula)
        $sysList = array_values(array_unique(array_filter(array_map('trim', explode(',', $sys)), fn($x) => preg_match('/^[a-z0-9-]{1,40}$/', $x))));
        $sysList = array_slice($sysList, 0, 50);
        if ($sysList) {
            $in = ' AND m.system_id IN (' . implode(',', array_fill(0, count($sysList), '?')) . ')';
            $wv .= $in; $wq .= $in; $wp .= $in;
            array_push($av, ...$sysList); array_push($aq, ...$sysList); array_push($ap, ...$sysList);
        }
        $views = DB::select("SELECT v.user_id, COUNT(*) AS videos, SUM(v.points) AS pontos, MAX(v.completed_at) AS ultimo, COUNT(DISTINCT l.module_id) AS modulos
              FROM lesson_views v JOIN lessons l ON l.lesson_id = v.lesson_id JOIN modules m ON m.module_id = l.module_id
             WHERE $wv GROUP BY v.user_id", $av);
        $quiz = DB::select("SELECT a.user_id, COUNT(*) AS provas, SUM(a.points) AS pontos, MAX(a.created_at) AS ultimo
              FROM quiz_attempts a JOIN quizzes z ON z.quiz_id = a.quiz_id JOIN modules m ON m.module_id = z.module_id
             WHERE $wq GROUP BY a.user_id", $aq);
        $posts = DB::select("SELECT l.created_by AS user_id, COUNT(*) AS n, SUM(l.post_points) AS pontos, MAX(l.created_at) AS ultimo
              FROM lessons l JOIN modules m ON m.module_id = l.module_id WHERE $wp AND l.created_by IS NOT NULL GROUP BY l.created_by", $ap);
        $by = [];
        foreach ($views as $r) $by[(int) $r['user_id']] = ['videos' => (int) $r['videos'], 'pontos' => (int) $r['pontos'], 'modulos' => (int) $r['modulos'], 'provas' => 0, 'ultimo' => $r['ultimo']];
        foreach ($posts as $r) {
            $u = (int) $r['user_id'];
            $by[$u] ??= ['videos' => 0, 'pontos' => 0, 'modulos' => 0, 'provas' => 0, 'ultimo' => null];
            $by[$u]['postagens'] = (int) $r['n'];
            $by[$u]['pontos'] += (int) $r['pontos'];
            if ($r['ultimo'] > (string) $by[$u]['ultimo']) $by[$u]['ultimo'] = $r['ultimo'];
        }
        foreach ($quiz as $r) {
            $u = (int) $r['user_id'];
            $by[$u] ??= ['videos' => 0, 'pontos' => 0, 'modulos' => 0, 'provas' => 0, 'ultimo' => null];
            $by[$u]['provas'] = (int) $r['provas'];
            $by[$u]['pontos'] += (int) $r['pontos'];
            if ($r['ultimo'] > (string) $by[$u]['ultimo']) $by[$u]['ultimo'] = $r['ultimo'];
        }
        $users = $by ? DB::select('SELECT user_id, name, username, company_name FROM users WHERE user_id IN (' . implode(',', array_map('intval', array_keys($by))) . ')') : [];
        $rows = [];
        foreach ($users as $u) {
            $s = $by[(int) $u['user_id']];
            $rows[] = ['id' => (string) $u['user_id'], 'nome' => $u['name'], 'usuario' => $u['username'], 'empresa' => $u['company_name'] ?? '',
                'videos' => $s['videos'], 'pontos' => $s['pontos'], 'modulos' => $s['modulos'], 'provas' => $s['provas'], 'postagens' => $s['postagens'] ?? 0, 'ultimo' => ms($s['ultimo'])];
        }
        usort($rows, fn($a, $b) => [$b['pontos'], $b['videos'], $a['ultimo']] <=> [$a['pontos'], $a['videos'], $b['ultimo']]);
        $top = DB::select(
            "SELECT l.title, m.system_id, COUNT(*) AS vezes FROM lesson_views v JOIN lessons l ON l.lesson_id = v.lesson_id JOIN modules m ON m.module_id = l.module_id
              WHERE $wv GROUP BY l.lesson_id, l.title, m.system_id ORDER BY vezes DESC LIMIT 10", $av);
        // extrato do usuário escolhido: cada vídeo, prova e postagem com data e pontos
        $detalhe = [];
        if ($uid > 0) {
            foreach (DB::select("SELECT 'video' AS tipo, l.title AS titulo, m.system_id AS sistema, m.name AS modulo, v.points AS pontos, v.completed_at AS em
                  FROM lesson_views v JOIN lessons l ON l.lesson_id = v.lesson_id JOIN modules m ON m.module_id = l.module_id WHERE $wv", $av) as $r) $detalhe[] = $r;
            foreach (DB::select("SELECT 'prova' AS tipo, z.title AS titulo, m.system_id AS sistema, m.name AS modulo, a.points AS pontos, a.created_at AS em, a.score, a.total, a.passed
                  FROM quiz_attempts a JOIN quizzes z ON z.quiz_id = a.quiz_id JOIN modules m ON m.module_id = z.module_id WHERE " . str_replace('a.points > 0', '1=1', $wq), $aq) as $r) $detalhe[] = $r;
            foreach (DB::select("SELECT 'postagem' AS tipo, l.title AS titulo, m.system_id AS sistema, m.name AS modulo, l.post_points AS pontos, l.created_at AS em
                  FROM lessons l JOIN modules m ON m.module_id = l.module_id WHERE $wp AND l.created_by IS NOT NULL", $ap) as $r) $detalhe[] = $r;
            usort($detalhe, fn($a, $b) => strcmp((string) $b['em'], (string) $a['em']));
            $detalhe = array_map(fn($r) => ['tipo' => $r['tipo'], 'titulo' => $r['titulo'], 'sistema' => $r['sistema'], 'modulo' => $r['modulo'], 'pontos' => (int) $r['pontos'], 'em' => ms($r['em']),
                'nota' => isset($r['total']) && $r['total'] ? (int) round($r['score'] * 100 / $r['total']) : null, 'aprovado' => isset($r['passed']) ? (bool) $r['passed'] : null], array_slice($detalhe, 0, 1000));
        }
        $usuarios = array_map(fn($u) => ['id' => (string) $u['user_id'], 'nome' => $u['name'], 'empresa' => $u['company_name'] ?? ''],
            DB::select("SELECT user_id, name, company_name FROM users WHERE status = 'aprovado' ORDER BY name LIMIT 3000"));
        json_out(['ok' => true, 'rows' => array_slice($rows, 0, 500), 'detalhe' => $detalhe, 'usuarios' => $usuarios,
            'top' => array_map(fn($r) => ['titulo' => $r['title'], 'sistema' => $r['system_id'], 'vezes' => (int) $r['vezes']], $top)]);
    }

    /** Progresso de todos os usuários aprovados: aulas concluídas, onde parou e provas. */
    public function progress(): void
    {
        $me = require_login();
        if (!Gate::allows('relatorios') && !Gate::allows('usuarios')) json_fail('Você não tem permissão para ver o progresso da equipe.', 403);
        $users = DB::select("SELECT user_id, name, username, company_name, last_login_at FROM users WHERE status = 'aprovado' AND role <> 'admin' ORDER BY name LIMIT 2000");
        $views = DB::select('SELECT user_id, lesson_id, started_at, completed_at FROM lesson_views');
        $att = DB::select('SELECT a.user_id, z.module_id, MAX(a.score * 100 / a.total) AS best, MAX(a.passed) AS passed, COUNT(*) AS tentativas
              FROM quiz_attempts a JOIN quizzes z ON z.quiz_id = a.quiz_id GROUP BY a.user_id, z.module_id');
        $pts = [];
        foreach (DB::select('SELECT user_id, SUM(points) AS p FROM lesson_views GROUP BY user_id UNION ALL SELECT user_id, SUM(points) FROM quiz_attempts GROUP BY user_id UNION ALL SELECT created_by, SUM(post_points) FROM lessons WHERE created_by IS NOT NULL GROUP BY created_by') as $r) {
            $pts[(int) $r['user_id']] = ($pts[(int) $r['user_id']] ?? 0) + (int) $r['p'];
        }
        $done = []; $last = [];
        foreach ($views as $v) {
            $u = (int) $v['user_id'];
            if ($v['completed_at']) $done[$u][] = (string) $v['lesson_id'];
            if (!isset($last[$u]) || $v['started_at'] > $last[$u]['at']) $last[$u] = ['lesson_id' => (string) $v['lesson_id'], 'at' => $v['started_at']];
        }
        $prov = [];
        foreach ($att as $a) $prov[(int) $a['user_id']][] = ['module_id' => (int) $a['module_id'], 'best' => (int) round((float) $a['best']), 'passed' => (bool) $a['passed'], 'tentativas' => (int) $a['tentativas']];
        json_out(['ok' => true, 'users' => array_map(function ($u) use ($done, $last, $prov, $pts) {
            $id = (int) $u['user_id'];
            return ['id' => (string) $id, 'nome' => $u['name'], 'usuario' => $u['username'], 'empresa' => $u['company_name'] ?? '', 'login' => ms($u['last_login_at']),
                'assistidas' => $done[$id] ?? [], 'ultima' => isset($last[$id]) ? ['lesson_id' => $last[$id]['lesson_id'], 'em' => ms($last[$id]['at'])] : null,
                'provas' => $prov[$id] ?? [], 'pontos' => $pts[$id] ?? 0];
        }, $users)]);
    }
}
