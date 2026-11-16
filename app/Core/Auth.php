<?php

namespace App\Core;

class Auth
{
    /**
     * Inicia a sessão se não estiver ativa.
     */
    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Verifica se o usuário está logado.
     */
    public static function check(): bool
    {
        self::startSession();
        return isset($_SESSION['user']);
    }

    /**
     * Retorna os dados do usuário logado ou null.
     */
    public static function user(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    /**
     * Retorna o ID do usuário logado ou null.
     */
    public static function id(): ?int
    {
        $user = self::user();
        return isset($user['user_id']) ? (int)$user['user_id'] : null;
    }

    /**
     * Define a sessão para o usuário logado.
     */
    public static function login(array $userData): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user'] = $userData;
    }

    /**
     * Destrói a sessão e faz o logout.
     */
    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}
