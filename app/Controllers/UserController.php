<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\UserService;

class UserController extends Controller
{
    public function status(string $id): void
    {
        require_login('aprovacoes');
        $r = (new UserService())->changeStatus((int) $id, (new Request())->string('status'));
        $r['ok'] ? json_out($r) : json_fail($r['message'], $r['status']);
    }

    public function resetPassword(string $id): void
    {
        require_login('usuarios');
        $r = (new UserService())->resetPassword((int) $id);
        $r['ok'] ? json_out($r) : json_fail($r['message'], $r['status']);
    }

    public function profile(string $id): void
    {
        require_login('usuarios');
        $r = (new UserService())->updateProfile((int) $id, (new Request())->all());
        $r['ok'] ? json_out($r) : json_out($r, $r['status']);
    }

    public function access(string $id): void
    {
        require_login('usuarios');
        $req = new Request();
        $modules = $req->input('modules', []);
        $adm = $req->input('adm', null);
        $super = $req->input('super', null);
        $r = (new UserService())->saveAccess((int) $id, is_array($modules) ? $modules : [], is_array($adm) ? array_values(array_filter($adm, 'is_string')) : null, $super === null ? null : (bool) $super);
        $r['ok'] ? json_out($r) : json_fail($r['message'], $r['status']);
    }
}
