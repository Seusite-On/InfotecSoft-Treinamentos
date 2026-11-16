<?php

namespace App\Repositories;

use App\Core\DB;

class CompanyRepository
{
    public function findByCnpj(string $cnpj): ?array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) !== 14) return null;
        return DB::table('companies')->where('cnpj', $cnpj)->where('active', 1)->first() ?: null;
    }

    public function find(int $id): ?array
    {
        return DB::table('companies')->where('company_id', $id)->first() ?: null;
    }

    public function all(): array
    {
        return DB::select('SELECT company_id, cnpj, name FROM companies WHERE active = 1 ORDER BY name');
    }
}
