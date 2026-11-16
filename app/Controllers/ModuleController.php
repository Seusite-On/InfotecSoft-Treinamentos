<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\ModuleService;

class ModuleController extends Controller
{
    private function reply(array $r): void
    {
        $r['ok'] ? json_out($r) : json_fail($r['message'], $r['status'] ?? 422);
    }

    public function store(): void
    {
        require_login('modulos');
        $q = new Request();
        $this->reply((new ModuleService())->create($q->string('system'), $q->string('name'), $q->string('icon', 'book'), $q->boolean('auto')));
    }

    public function update(string $id): void
    {
        require_login('modulos');
        $q = new Request();
        $this->reply((new ModuleService())->update((int) $id, $q->string('name'), $q->string('icon', 'book')));
    }

    public function move(string $id): void
    {
        require_login('modulos');
        $this->reply((new ModuleService())->move((int) $id, (new Request())->int('dir')));
    }

    public function destroy(string $id): void
    {
        require_login('modulos');
        $this->reply((new ModuleService())->delete((int) $id));
    }
}
