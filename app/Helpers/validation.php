<?php

if (!function_exists('valid_cpf')) {
    function valid_cpf(string $d): bool
    {
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) {
                $s += (int) $d[$i] * ($t + 1 - $i);
            }
            if ((($s * 10) % 11) % 10 !== (int) $d[$t]) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('valid_cnpj')) {
    function valid_cnpj(string $d): bool
    {
        if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }
        $dv = static function (int $n) use ($d): int {
            $s = 0;
            $w = $n - 7;
            for ($i = 0; $i < $n; $i++) {
                $s += (int) $d[$i] * $w--;
                if ($w < 2) {
                    $w = 9;
                }
            }
            $r = $s % 11;
            return $r < 2 ? 0 : 11 - $r;
        };
        return $dv(12) === (int) $d[12] && $dv(13) === (int) $d[13];
    }
}

if (!function_exists('format_doc')) {
    function format_doc(string $d): string
    {
        return strlen($d) === 11
            ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d)
            : preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d);
    }
}
