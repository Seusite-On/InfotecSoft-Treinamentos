<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Gate;
use App\Repositories\LessonRepository;
use App\Repositories\UserRepository;

/** Serve vídeos enviados (com suporte a Range para avançar/voltar no player) e miniaturas. */
class MediaController extends Controller
{
    private function deny(int $code): never
    {
        http_response_code($code);
        exit;
    }

    /** Logado, com cadastro aprovado. */
    private function guard(): void
    {
        if (!Auth::check()) $this->deny(401);
        $row = (new UserRepository())->find((int) Auth::id());
        if (!$row || $row['status'] !== 'aprovado') $this->deny(401);
    }

    public function video(string $id): void
    {
        $this->guard();
        if (!ctype_digit($id)) $this->deny(404);
        $l = (new LessonRepository())->find((int) $id);
        if (!$l || $l['video_source'] !== 'file' || !$l['file_path']) $this->deny(404);
        $can = Gate::allows('videos') || Gate::allows('modulos')
            || in_array((int) $l['module_id'], (new UserRepository())->moduleIds((int) Auth::id()), true);
        if (!$can) $this->deny(403);
        $path = BASE_PATH . '/storage/videos/' . basename($l['file_path']);
        if (!is_file($path)) $this->deny(404);

        session_write_close(); // não trava outras requisições enquanto o vídeo é enviado
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        $mime = in_array($l['file_mime'], \App\Services\MediaStorage::VIDEO_EXT, true) ? $l['file_mime'] : 'video/mp4';
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int) $m[2]); }
            else { $start = (int) $m[1]; if ($m[2] !== '') $end = min((int) $m[2], $size - 1); }
            if ($start > $end || $start >= $size) {
                header("Content-Range: bytes */$size");
                $this->deny(416);
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        $fh = fopen($path, 'rb');
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, (int) min(1024 * 512, $left));
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }

    public function thumb(string $id): void
    {
        $this->guard();
        if (!ctype_digit($id)) $this->deny(404);
        $l = (new LessonRepository())->find((int) $id);
        if (!$l || !$l['thumb_path']) $this->deny(404);
        $path = BASE_PATH . '/storage/thumbs/' . basename($l['thumb_path']);
        if (!is_file($path)) $this->deny(404);
        session_write_close();
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg')));
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
