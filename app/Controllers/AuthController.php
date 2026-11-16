<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Repositories\UserRepository;
use App\Services\AuthService;

class AuthController extends Controller
{
    private Request $request;
    private RateLimiter $rateLimiter;
    private AuthService $auth;

    public function __construct()
    {
        $this->request = new Request();
        $this->rateLimiter = new RateLimiter();
        $this->auth = new AuthService();
    }

    public function login(): void
    {
        $username = trim($this->request->string('username'));
        $password = $this->request->string('password');
        $rateKey = 'login:' . hash('sha256', strtolower($username ?: 'guest') . '|' . $this->request->clientIp());

        if ($this->rateLimiter->tooManyAttempts($rateKey, 5, 900)) {
            header('Retry-After: ' . $this->rateLimiter->availableIn($rateKey));
            json_fail('Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429, ['code' => 'locked']);
        }
        if ($username === '' || $password === '') {
            json_fail('Preencha usuário e senha para continuar.', 422, ['code' => 'empty']);
        }
        $r = $this->auth->attempt($username, $password);
        if (!$r['ok']) {
            if ($r['code'] === 'invalid') {
                $this->rateLimiter->hit($rateKey, 900);
            }
            json_fail($r['message'], $r['status'], ['code' => $r['code']]);
        }
        $this->rateLimiter->reset($rateKey);
        json_out(['ok' => true]);
    }

    public function logout(): void
    {
        Auth::logout();
        json_out(['ok' => true]);
    }

    public function register(): void
    {
        $r = $this->auth->register($this->request->all());
        $r['ok'] ? json_out($r, 201) : json_out($r + ['message' => 'Corrija os campos destacados.'], 422);
    }

    public function changePassword(): void
    {
        $me = require_login();
        $current = $this->request->string('current');
        $new = $this->request->string('new');
        $repo = new UserRepository();
        $u = $repo->find((int) $me['user_id']);
        if (!$u || !password_verify($current, (string) $u['password'])) json_fail('Senha atual incorreta.', 422, ['field' => 'current']);
        if (strlen($new) < 6 || !preg_match('/[a-zA-Z]/', $new) || !preg_match('/\d/', $new)) json_fail('A nova senha precisa ter 6+ caracteres, com letras e números.', 422, ['field' => 'new']);
        $repo->update((int) $u['user_id'], ['password' => password_hash($new, PASSWORD_DEFAULT), 'updated_at' => now()]);
        json_out(['ok' => true]);
    }
}
