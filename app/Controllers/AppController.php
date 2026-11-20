<?php

namespace App\Controllers;

use App\Core\Controller;

/** Entrega o portal (página única em app/Views/app.html). */
class AppController extends Controller
{
    public function index(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile(BASE_PATH . '/app/Views/app.html');
    }

    /** Versão do portal publicada (muda a cada publicação) — o navegador avisa para atualizar. */
    public static function version(): string
    {
        $f = BASE_PATH . '/app/Views/app.html';
        return is_file($f) ? substr(md5((string) filemtime($f) . filesize($f)), 0, 10) : '';
    }

    public function versionJson(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => true, 'v' => self::version()]);
    }
}
