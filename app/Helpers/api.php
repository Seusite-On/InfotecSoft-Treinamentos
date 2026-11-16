<?php

/** Responde JSON e encerra (padrão das rotas /api). */
if (!function_exists('json_out')) {
    function json_out(array $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('json_fail')) {
    function json_fail(string $message, int $status = 422, array $extra = []): never
    {
        json_out(['ok' => false, 'message' => $message] + $extra, $status);
    }
}

/** Exige login (401) e, opcionalmente, uma permissão do Gate (403). */
if (!function_exists('require_login')) {
    function require_login(?string $permission = null): array
    {
        if (!\App\Core\Auth::check()) {
            json_fail('Sua sessão expirou. Entre novamente.', 401);
        }
        $row = \App\Core\DB::table('users')->select('status')->where('user_id', (int) \App\Core\Auth::id())->first();
        if (!$row || $row['status'] !== 'aprovado') {
            \App\Core\Auth::logout();
            json_fail('Seu acesso não está mais ativo. Fale com o administrador.', 401);
        }
        if ($permission !== null && !empty($_SESSION['must_change'])) {
            json_fail('Troque sua senha em "Meu perfil" antes de continuar.', 403, ['code' => 'must_change']);
        }
        if ($permission !== null && !\App\Core\Gate::allows($permission)) {
            json_fail('Você não tem permissão para esta ação.', 403);
        }
        return \App\Core\Auth::user();
    }
}

if (!function_exists('ms')) {
    /** DATETIME do MySQL -> milissegundos (o front trabalha com Date.now()). */
    function ms(?string $dt): ?int
    {
        return $dt ? strtotime($dt) * 1000 : null;
    }
}

if (!function_exists('now')) {
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
