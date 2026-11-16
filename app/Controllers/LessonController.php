<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\LessonService;

class LessonController extends Controller
{
    public function save(): void
    {
        require_login('videos');
        // Envio de arquivo grande acima do post_max_size chega com $_POST vazio
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
            json_fail('O vídeo é maior que o limite do servidor (' . ini_get('post_max_size') . ').', 413);
        }
        $r = (new LessonService())->save((new Request())->all(), $_FILES['file'] ?? null);
        $r['ok'] ? json_out($r) : json_out($r, $r['status'] ?? 422);
    }

    public function removeVideo(string $id): void
    {
        require_login('videos');
        $r = (new LessonService())->removeVideo((int) $id);
        $r['ok'] ? json_out($r) : json_fail($r['message'], $r['status']);
    }
}
