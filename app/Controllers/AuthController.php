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
        $password = raw_input('password');
        $rateKey = 'login:' . hash('sha256', strtolower($username ?: 'guest') . '|' . $this->request->clientIp());
        $ipKey = 'login-ip:' . hash('sha256', $this->request->clientIp());

        foreach ([[$rateKey, 5], [$ipKey, 20]] as [$k, $max]) {
            if ($this->rateLimiter->tooManyAttempts($k, $max, 900)) {
                header('Retry-After: ' . $this->rateLimiter->availableIn($k));
                json_fail('Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429, ['code' => 'locked']);
            }
        }
        if (mb_strlen($username) > 150 || strlen($password) > 200) {
            json_fail('Usuário ou senha incorretos. Tente novamente.', 401, ['code' => 'invalid']);
        }
        if ($username === '' || $password === '') {
            json_fail('Preencha usuário e senha para continuar.', 422, ['code' => 'empty']);
        }
        $r = $this->auth->attempt($username, $password);
        if (!$r['ok']) {
            if ($r['code'] === 'invalid') {
                $this->rateLimiter->hit($rateKey, 900);
                $this->rateLimiter->hit($ipKey, 900);
            }
            json_fail($r['message'], $r['status'], ['code' => $r['code']]);
        }
        $this->rateLimiter->reset($rateKey);
        json_out(['ok' => true, 'must_change' => !empty($_SESSION['must_change'])]);
    }

    public function logout(): void
    {
        Auth::logout();
        json_out(['ok' => true]);
    }

    public function register(): void
    {
        $key = 'register:' . hash('sha256', $this->request->clientIp());
        if ($this->rateLimiter->tooManyAttempts($key, 5, 3600)) {
            json_fail('Muitos cadastros feitos a partir desta rede. Tente novamente mais tarde.', 429, ['code' => 'locked']);
        }
        $in = $this->request->all();
        $in['pw'] = raw_input('pw');
        $r = $this->auth->register($in);
        if ($r['ok']) {
            $this->rateLimiter->hit($key, 3600);
        }
        $r['ok'] ? json_out($r, 201) : json_out($r + ['message' => 'Corrija os campos destacados.'], 422);
    }

    public function changePassword(): void
    {
        $me = require_login();
        $current = raw_input('current');
        $new = raw_input('new');
        $key = 'password:' . (int) $me['user_id'];
        if ($this->rateLimiter->tooManyAttempts($key, 5, 900)) {
            json_fail('Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429, ['code' => 'locked']);
        }
        $repo = new UserRepository();
        $u = $repo->find((int) $me['user_id']);
        if (!$u || !password_verify($current, (string) $u['password'])) {
            $this->rateLimiter->hit($key, 900);
            json_fail('Senha atual incorreta.', 422, ['field' => 'current']);
        }
        if (!password_is_strong($new)) json_fail(password_rule_message(), 422, ['field' => 'new']);
        if (hash_equals($current, $new)) json_fail('A nova senha precisa ser diferente da atual.', 422, ['field' => 'new']);
        $repo->update((int) $u['user_id'], ['password' => password_hash($new, PASSWORD_DEFAULT), 'updated_at' => now()]);
        $this->rateLimiter->reset($key);
        $_SESSION['must_change'] = false;
        session_regenerate_id(true);
        json_out(['ok' => true]);
    }
}
