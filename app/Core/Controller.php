<?php

namespace App\Core;

class Controller
{
    protected function view(string $view, array $data = []): void
    {
        View::render($view, $data);
    }

    protected function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /**
     * Responde JSON para chamadas AJAX (modal de exclusão) ou faz Flash + redirect nas demais.
     */
    protected function respond(bool $success, string $message, string $redirectTo, int $errorStatus = 422): void
    {
        if ($this->isAjax()) {
            $this->jsonResponse(['success' => $success, 'message' => $message], $success ? 200 : $errorStatus);
        }
        $success ? Flash::success('Sucesso', $message) : Flash::error('Erro', $message);
        redirect($redirectTo);
    }

    protected function jsonResponse(array $data, int $statusCode = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

