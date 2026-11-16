<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Repositories\CompanyRepository;

/** Identifica a empresa pelo CNPJ na tela de cadastro (só empresas atendidas pelo portal). */
class CompanyController extends Controller
{
    public function lookup(string $cnpj): void
    {
        $rl = new RateLimiter();
        $key = 'company:' . hash('sha256', (new Request())->clientIp());
        if ($rl->tooManyAttempts($key, 40, 600)) json_fail('Muitas consultas. Aguarde alguns minutos.', 429);
        $rl->hit($key, 600);
        $c = (new CompanyRepository())->findByCnpj($cnpj);
        if (!$c) json_fail('Empresa não encontrada. Confira o CNPJ ou fale com a InfotecSoft.', 404, ['code' => 'not_found']);
        json_out(['ok' => true, 'name' => $c['name'], 'cnpj' => format_doc($c['cnpj'])]);
    }
}
