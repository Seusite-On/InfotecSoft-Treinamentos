<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DB;
use App\Core\Request;

/** Relatório de pontuação: quem mais assistiu, quantos vídeos e pontos (permissão "relatorios"). */
class ReportController extends Controller
{
    public function ranking(): void
    {
        require_login('relatorios');
        $q = new Request();
        $days = max(0, min(3650, $q->int('dias')));
        $sys = $q->string('sistema');
        $where = 'v.completed_at IS NOT NULL';
        $args = [];
        if ($days > 0) { $where .= ' AND v.completed_at >= (NOW() - INTERVAL ? DAY)'; $args[] = $days; }
        if ($sys !== '') { $where .= ' AND m.system_id = ?'; $args[] = $sys; }
        $rows = DB::select(
            "SELECT u.user_id, u.name, u.username, u.company_name, COUNT(*) AS videos, SUM(v.points) AS pontos,
                    MAX(v.completed_at) AS ultimo, COUNT(DISTINCT l.module_id) AS modulos
               FROM lesson_views v
               JOIN users u ON u.user_id = v.user_id
               JOIN lessons l ON l.lesson_id = v.lesson_id
               JOIN modules m ON m.module_id = l.module_id
              WHERE $where
              GROUP BY u.user_id, u.name, u.username, u.company_name
              ORDER BY pontos DESC, videos DESC, ultimo ASC
              LIMIT 500", $args);
        $top = DB::select(
            "SELECT l.title, m.system_id, COUNT(*) AS vezes FROM lesson_views v JOIN lessons l ON l.lesson_id = v.lesson_id JOIN modules m ON m.module_id = l.module_id
              WHERE $where GROUP BY l.lesson_id, l.title, m.system_id ORDER BY vezes DESC LIMIT 10", $args);
        json_out(['ok' => true, 'rows' => array_map(fn($r) => [
            'id' => (string) $r['user_id'], 'nome' => $r['name'], 'usuario' => $r['username'], 'empresa' => $r['company_name'] ?? '',
            'videos' => (int) $r['videos'], 'pontos' => (int) $r['pontos'], 'modulos' => (int) $r['modulos'], 'ultimo' => ms($r['ultimo']),
        ], $rows), 'top' => array_map(fn($r) => ['titulo' => $r['title'], 'sistema' => $r['system_id'], 'vezes' => (int) $r['vezes']], $top)]);
    }
}
