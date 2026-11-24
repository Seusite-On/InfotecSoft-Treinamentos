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
        'relatorios' => 'can_view_reports',
        'equipe'     => 'can_view_team',
        'sistemas'   => 'can_manage_systems',
        'provas'     => 'can_manage_quizzes',
        'acessos'    => 'can_view_access_log',
        'sugestoes'  => 'can_manage_suggestions',
        'postar'     => 'can_post_videos',
        'sugerir'    => 'can_send_suggestions',
    ];

    /** Recursos de usuário comum: vêm ligados por padrão e não fazem a pessoa ser "equipe". */
    public const USER_FEATURES = ['postar', 'sugerir'];

    private static function on(array $row, string $code, string $col): bool
    {
        if (!$row) return in_array($code, self::USER_FEATURES, true);
        return (int) ($row[$col] ?? (in_array($code, self::USER_FEATURES, true) ? 1 : 0)) === 1;
    }

    /** Códigos administrativos do usuário (sem os recursos de usuário comum). */
    public static function staffCodesFor(int $userId): array
    {
        return array_values(array_diff(self::codesFor($userId), self::USER_FEATURES));
    }

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
        if ((int) ($row['is_super'] ?? 0) === 1) {
            return true;
        }
        return self::on($row, $permission, $col);
    }

    /** Lista de códigos liberados para um usuário (ex.: ['modulos','videos']) */
    public static function codesFor(int $userId): array
    {
        $row = self::row($userId);
        if (self::isSuper($userId)) {
            return array_keys(self::MAP);
        }
        $out = [];
        foreach (self::MAP as $code => $col) {
            if (self::on($row, $code, $col)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    public static function isStaff(): bool
    {
        foreach (array_diff(array_keys(self::MAP), self::USER_FEATURES) as $p) {
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

    /** Super usuário: todas as permissões e todos os módulos. */
    public static function isSuper(int $userId): bool
    {
        return (int) (self::row($userId)['is_super'] ?? 0) === 1;
    }

    protected static function row(int $userId): array
    {
        if (!array_key_exists($userId, self::$cache)) {
            self::$cache[$userId] = DB::table('user_permissions')->where('user_id', $userId)->first() ?: [];
        }
        return self::$cache[$userId];
    }
}
