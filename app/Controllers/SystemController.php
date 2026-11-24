<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DB;
use App\Core\Request;

/** Sistemas (ex.: Fusion Web, E-Trade, SEFAZ): criar, editar, ordenar e excluir pelo portal (permissão "modulos"). */
class SystemController extends Controller
{
    private const ICONS = ['rocket','file','box','users','truck','cart','cash','receipt','chart','gear','card','plug','tag','store','sliders','sparkle','book','video','shield','calendar','globe','monitor','cloud','trend','award','msg','key','route','scale','grid'];

    private function input(): array
    {
        $q = new Request();
        $name = trim(preg_replace('/\s+/', ' ', $q->string('name')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) json_fail('O nome do sistema precisa ter de 2 a 40 caracteres.', 422, ['errors' => ['sy-name' => 'Informe o nome (2 a 40 caracteres).']]);
        $icon = $q->string('icon', 'grid');
        $color = strtoupper($q->string('color', '#1E6FE0'));
        return [
            'name' => $name,
            'description' => mb_substr(trim($q->string('desc')), 0, 255) ?: null,
            'icon' => in_array($icon, self::ICONS, true) ? $icon : 'grid',
            'color' => preg_match('/^#[0-9A-F]{6}$/', $color) ? $color : '#1E6FE0',
            'is_public' => $q->boolean('public') ? 1 : 0,
        ];
    }

    private function nameTaken(string $name, ?string $except = null): bool
    {
        $r = DB::select('SELECT system_id FROM systems WHERE active = 1 AND LOWER(name) = LOWER(?)' . ($except ? ' AND system_id <> ?' : ''), $except ? [$name, $except] : [$name]);
        return $r !== [];
    }

    public function store(): void
    {
        require_login('sistemas');
        $d = $this->input();
        if ($this->nameTaken($d['name'])) json_fail('Já existe um sistema com esse nome.', 422, ['errors' => ['sy-name' => 'Já existe um sistema com esse nome.']]);
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $d['name']) ?: '')), '-') ?: 'sistema';
        $base = substr($base, 0, 32);
        $id = $base;
        for ($i = 2; DB::table('systems')->where('system_id', $id)->first(); $i++) $id = $base . '-' . $i;
        $pos = (int) (DB::select('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM systems')[0]['p'] ?? 1);
        DB::table('systems')->insert($d + ['system_id' => $id, 'position' => $pos, 'active' => 1, 'created_at' => now()]);
        json_out(['ok' => true, 'id' => $id, 'message' => 'Sistema criado. Agora crie os módulos dele.']);
    }

    public function update(string $id): void
    {
        require_login('sistemas');
        $s = DB::table('systems')->where('system_id', $id)->where('active', 1)->first();
        if (!$s) json_fail('Sistema não encontrado.', 404);
        $d = $this->input();
        if ($this->nameTaken($d['name'], $id)) json_fail('Já existe um sistema com esse nome.', 422, ['errors' => ['sy-name' => 'Já existe um sistema com esse nome.']]);
        DB::table('systems')->where('system_id', $id)->update($d);
        json_out(['ok' => true, 'message' => 'Sistema atualizado.']);
    }

    public function move(string $id): void
    {
        require_login('sistemas');
        $dir = (new Request())->int('dir') < 0 ? -1 : 1;
        $ids = array_column(DB::select('SELECT system_id FROM systems WHERE active = 1 ORDER BY position, system_id'), 'system_id');
        $i = array_search($id, $ids, true);
        if ($i === false) json_fail('Sistema não encontrado.', 404);
        $j = $i + $dir;
        if ($j >= 0 && $j < count($ids)) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $p => $sid) DB::table('systems')->where('system_id', $sid)->update(['position' => $p + 1]);
        }
        json_out(['ok' => true]);
    }

    public function destroy(string $id): void
    {
        require_login('sistemas');
        $s = DB::table('systems')->where('system_id', $id)->where('active', 1)->first();
        if (!$s) json_fail('Sistema não encontrado.', 404);
        $n = (int) (DB::select('SELECT COUNT(*) AS n FROM modules WHERE system_id = ?', [$id])[0]['n'] ?? 0);
        if ($n > 0) json_fail("Este sistema ainda tem $n módulo(s). Exclua ou mova os módulos antes de excluir o sistema.", 422);
        DB::statement('DELETE FROM systems WHERE system_id = ?', [$id]);
        json_out(['ok' => true, 'message' => 'Sistema excluído.']);
    }
}
