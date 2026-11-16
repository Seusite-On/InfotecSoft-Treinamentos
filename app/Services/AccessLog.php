<?php

namespace App\Services;

use App\Core\DB;
use App\Core\Request;

/** Grava logins, tentativas e saídas (painel "Últimos acessos"). Nunca derruba a requisição se falhar. */
class AccessLog
{
    public static function record(string $event, ?int $userId = null, ?string $username = null): void
    {
        try {
            DB::table('access_logs')->insert([
                'user_id' => $userId, 'username' => $username !== null ? mb_substr($username, 0, 150) : null, 'event' => $event,
                'ip' => mb_substr((new Request())->clientIp(), 0, 45), 'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (random_int(1, 50) === 1) { // limpeza ocasional: guarda 90 dias
                DB::select('DELETE FROM access_logs WHERE created_at < (NOW() - INTERVAL 90 DAY)');
            }
        } catch (\Throwable $e) {
            error_log('[access_log] ' . $e->getMessage());
        }
    }
}
