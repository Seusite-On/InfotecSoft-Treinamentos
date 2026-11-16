<?php

/** Regras de senha do portal: 8+ caracteres, com letras e números. */
if (!function_exists('password_is_strong')) {
    function password_is_strong(string $pw): bool
    {
        return strlen($pw) >= 8 && strlen($pw) <= 200 && preg_match('/[a-zA-Z]/', $pw) && preg_match('/\d/', $pw);
    }
}

if (!function_exists('password_rule_message')) {
    function password_rule_message(): string
    {
        return 'A senha precisa ter pelo menos 8 caracteres, com letras e números.';
    }
}

/** Valor "cru" do corpo da requisição (senhas não podem ser aparadas). */
if (!function_exists('raw_input')) {
    function raw_input(string $key): string
    {
        $v = (new \App\Core\Request())->input($key, '');
        return is_string($v) ? $v : '';
    }
}

/** Requisição chegou por HTTPS (inclui proxy/CDN da hospedagem). */
if (!function_exists('is_https')) {
    function is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
        $p = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $p === 'https';
    }
}
