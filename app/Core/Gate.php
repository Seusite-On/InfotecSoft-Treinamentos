<?php

namespace App\Core;

/**
 * Permissões administrativas do portal (mesmo padrão do Stok Net):
 * role 'admin' pode tudo; os demais consultam a tabela user_permissions.
 */
class Gate
{
    protected static array $cache = [];

    /** código usado no front-end => coluna da tabela user_permissions */
    public const MAP = [
        'aprovacoes' => 'can_approve_users',
        'usuarios'   => 'can_manage_users',
        'modulos'    => 'can_manage_modules',
        'videos'     => 'can_manage_videos',
    ];

    public static function isAdmin(): bool
    {
        return Auth::check() && (Auth::user()['role'] ?? '') === 'admin';
    }

    public static function allows(string $permission): bool
    {
        if (!Auth::check()) {
            return false;
        }
        if (self::isAdmin()) {
            return true;
        }
        $col = self::MAP[$permission] ?? null;
        if (!$col) {
            return false;
        }
        $row = self::row((int) Auth::id());
        return isset($row[$col]) && (int) $row[$col] === 1;
    }

    /** Lista de códigos liberados para um usuário (ex.: ['modulos','videos']) */
    public static function codesFor(int $userId): array
    {
        $row = self::row($userId);
        $out = [];
        foreach (self::MAP as $code => $col) {
            if ((int) ($row[$col] ?? 0) === 1) {
                $out[] = $code;
            }
        }
        return $out;
    }

    public static function isStaff(): bool
    {
        foreach (array_keys(self::MAP) as $p) {
            if (self::allows($p)) {
                return true;
            }
        }
        return false;
    }

    public static function forget(int $userId): void
    {
        unset(self::$cache[$userId]);
    }

    protected static function row(int $userId): array
    {
        if (!array_key_exists($userId, self::$cache)) {
            self::$cache[$userId] = DB::table('user_permissions')->where('user_id', $userId)->first() ?: [];
        }
        return self::$cache[$userId];
    }
}
