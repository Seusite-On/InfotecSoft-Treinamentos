<?php

namespace App\Core;

use App\Core\CSRF;

class Request
{
    /**
     * Retorna o body "normalizado" do request:
     * - GET: query string
     * - POST/PUT/PATCH/DELETE: $_POST (forms) OU JSON (fetch)
     */
    public function all(): array
    {
        $method = self::method();

        if ($method === 'GET') {
            return $_GET;
        }

        // Se veio JSON (ex.: fetch), prioriza JSON
        $json = $this->json();
        if ($json !== []) {
            return $json;
        }

        // Forms (x-www-form-urlencoded / multipart)
        return $_POST;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $data = $this->all();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        if ($value === null) {
            return $default;
        }
        return is_string($value) ? trim($value) : (string) $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, null);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $value = is_string($value) ? strtolower(trim($value)) : $value;

        return in_array($value, [1, '1', 'true', 'on', 'yes', 'sim'], true);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return true;
    }

    public function only(array $keys): array
    {
        $data = $this->all();
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        return $out;
    }

    public function except(array $keys): array
    {
        $data = $this->all();
        foreach ($keys as $k) {
            unset($data[$k]);
        }
        return $data;
    }

    /**
     * Validação simples (sem dependências):
     * Regras suportadas: required, email, min:N, max:N
     * Retorna array de erros: ['campo' => ['msg1', 'msg2']]
     */
    public function validate(array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $this->input($field, null);
            $rulesList = array_filter(array_map('trim', explode('|', (string) $ruleString)));

            foreach ($rulesList as $rule) {
                if ($rule === 'required') {
                    if (!$this->filled($field)) {
                        $errors[$field][] = 'Campo obrigatório.';
                    }
                    continue;
                }

                if ($rule === 'email') {
                    $str = is_string($value) ? trim($value) : (string) $value;
                    if ($str !== '' && filter_var($str, FILTER_VALIDATE_EMAIL) === false) {
                        $errors[$field][] = 'E-mail inválido.';
                    }
                    continue;
                }

                if (str_starts_with($rule, 'min:')) {
                    $n = (int) substr($rule, 4);
                    $str = is_string($value) ? trim($value) : (string) $value;
                    if ($str !== '' && mb_strlen($str) < $n) {
                        $errors[$field][] = "Mínimo de {$n} caracteres.";
                    }
                    continue;
                }

                if (str_starts_with($rule, 'max:')) {
                    $n = (int) substr($rule, 4);
                    $str = is_string($value) ? trim($value) : (string) $value;
                    if ($str !== '' && mb_strlen($str) > $n) {
                        $errors[$field][] = "Máximo de {$n} caracteres.";
                    }
                    continue;
                }
            }
        }

        return $errors;
    }

    public function json(): array
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_contains($contentType, 'application/json')) {
            return [];
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function clientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        if (is_string($remoteAddr) && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return $remoteAddr;
        }

        $proxyEnv = $_ENV['TRUST_PROXY_HEADERS'] ?? getenv('TRUST_PROXY_HEADERS') ?? false;
        $trustProxyHeaders = filter_var($proxyEnv, FILTER_VALIDATE_BOOL);
        if (!$trustProxyHeaders) {
            return 'unknown';
        }

        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $parts = array_map('trim', explode(',', $candidate));
            foreach ($parts as $part) {
                if (filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }

        return 'unknown';
    }

    public static function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // HTML forms: apenas GET/POST. Para PUT/PATCH/DELETE use override.
        if ($method === 'POST') {
            $override = $_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;

            if (is_string($override) && $override !== '') {
                $override = strtoupper(trim($override));
                if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                    return $override;
                }
            }
        }

        return $method;
    }

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = str_replace('\\', '/', dirname($scriptName));
        
        // Remove a parte do diretório do script do path para suportar subpastas
        // Ex: /sistema-pedido/public/index.php -> scriptDir é /sistema-pedido/public
        if ($scriptDir !== '/' && strpos($path, $scriptDir) === 0) {
            $path = substr($path, strlen($scriptDir));
        } else {
            // Se o htaccess redirecionou da raiz do projeto, a base pode ser apenas o diretório pai do public
            $projectBase = str_replace('\\', '/', dirname($scriptDir));
            if ($projectBase !== '/' && strpos($path, $projectBase) === 0) {
                $path = substr($path, strlen($projectBase));
            }
        }

        $path = $path ?: '/';
        return '/' . ltrim($path, '/');
    }

    public function validateCSRF(): bool
    {
        $method = self::method();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $token = $this->input('csrf_token') ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        return CSRF::validate($token);
    }
}

