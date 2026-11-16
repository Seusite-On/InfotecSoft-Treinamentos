<?php

namespace App\Services;

/** Guarda vídeos e miniaturas em storage/ (fora da pasta pública). */
class MediaStorage
{
    public const VIDEO_EXT = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'ogv' => 'video/ogg'];

    public function videoDir(): string { return BASE_PATH . '/storage/videos'; }
    public function thumbDir(): string { return BASE_PATH . '/storage/thumbs'; }

    /** @return array{ok:bool,message?:string,path?:string,name?:string,size?:int,mime?:string} */
    public function storeVideo(array $file, int $lessonId): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'message' => 'O vídeo é maior que o limite do servidor (' . ini_get('upload_max_filesize') . ').'];
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['ok' => false, 'message' => 'Não foi possível receber o arquivo de vídeo. Tente novamente.'];
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::VIDEO_EXT[$ext])) {
            return ['ok' => false, 'message' => 'Formato não suportado. Envie MP4, WebM ou MOV.'];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!str_starts_with($mime, 'video/') && $mime !== 'application/octet-stream') {
            return ['ok' => false, 'message' => 'O arquivo enviado não é um vídeo.'];
        }
        if (!is_dir($this->videoDir())) mkdir($this->videoDir(), 0775, true);
        $name = $lessonId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $this->videoDir() . '/' . $name)) {
            return ['ok' => false, 'message' => 'Não foi possível salvar o vídeo no servidor.'];
        }
        return ['ok' => true, 'path' => $name, 'name' => mb_substr(basename((string) $file['name']), 0, 255), 'size' => (int) $file['size'],
            'mime' => str_starts_with($mime, 'video/') ? $mime : self::VIDEO_EXT[$ext]];
    }

    /** Miniatura enviada como data URL JPEG/PNG (gerada no navegador a partir do vídeo). */
    public function storeThumb(string $dataUrl, int $lessonId): ?string
    {
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) return null;
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 1_500_000 || @getimagesizefromstring($bin) === false) return null;
        if (!is_dir($this->thumbDir())) mkdir($this->thumbDir(), 0775, true);
        $name = $lessonId . '-' . bin2hex(random_bytes(6)) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        file_put_contents($this->thumbDir() . '/' . $name, $bin);
        return $name;
    }

    public function deleteVideo(?string $name): void { $this->unlinkIn($this->videoDir(), $name); }
    public function deleteThumb(?string $name): void { $this->unlinkIn($this->thumbDir(), $name); }

    private function unlinkIn(string $dir, ?string $name): void
    {
        if ($name && preg_match('/^[A-Za-z0-9._-]+$/', $name) && is_file($dir . '/' . $name)) {
            @unlink($dir . '/' . $name);
        }
    }
}
