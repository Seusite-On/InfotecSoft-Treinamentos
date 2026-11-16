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
}
