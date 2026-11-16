<?php

namespace App\Core;

class Route
{
    protected static array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'PATCH' => [],
        'DELETE' => [],
        'OPTIONS' => [],
    ];

    public static function get(string $pattern, $action): void
    {
        self::$routes['GET'][] = [self::toRegex($pattern), $action];
    }

    public static function post(string $pattern, $action): void
    {
        self::$routes['POST'][] = [self::toRegex($pattern), $action];
    }

    public static function put(string $pattern, $action): void
    {
        self::$routes['PUT'][] = [self::toRegex($pattern), $action];
    }

    public static function patch(string $pattern, $action): void
    {
        self::$routes['PATCH'][] = [self::toRegex($pattern), $action];
    }

    public static function delete(string $pattern, $action): void
    {
        self::$routes['DELETE'][] = [self::toRegex($pattern), $action];
    }

    public static function options(string $pattern, $action): void
    {
        self::$routes['OPTIONS'][] = [self::toRegex($pattern), $action];
    }

    public static function any(string $pattern, $action): void
    {
        foreach (array_keys(self::$routes) as $method) {
            self::$routes[$method][] = [self::toRegex($pattern), $action];
        }
    }

    public static function dispatch(): void
    {
        $request = new Request();
        if (!$request->validateCSRF()) {
            http_response_code(419);
            echo 'Error 419: Token CSRF inválido ou expirado.';
            exit;
        }

        $method = Request::method();
        $path = Request::path();
        $routes = self::$routes[$method] ?? [];
        foreach ($routes as [$regex, $action]) {
            if (preg_match($regex, $path, $matches)) {
                $params = [];
                foreach ($matches as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = $v;
                    }
                }
                self::invoke($action, $params);
                return;
            }
        }
        http_response_code(404);
        echo 'Error 404';
    }

    protected static function invoke($action, array $params): void
    {
        if (is_callable($action)) {
            echo call_user_func_array($action, $params);
            return;
        }
        if (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;
            $instance = is_string($class) ? new $class() : $class;
            echo call_user_func_array([$instance, $method], $params);
            return;
        }
        if (is_string($action) && strpos($action, '@') !== false) {
            [$controller, $method] = explode('@', $action, 2);
            $fqcn = 'App\\Controllers\\' . $controller;
            $instance = new $fqcn();
            echo call_user_func_array([$instance, $method], $params);
            return;
        }
        echo '';
    }

    protected static function toRegex(string $pattern): string
    {
        $pattern = '/' . ltrim($pattern, '/');
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_-]*)\}#', '(?P<$1>[^/]+)', $pattern);
        if ($pattern !== '/') {
            $pattern = rtrim($pattern, '/');
        }
        return '#^' . $pattern . '$#';
    }
}

