<?php

namespace App\Core;

class View
{
    public static function render(string $view, array $data = []): void
    {
        echo self::fetch($view, $data);
    }

    /**
     * Retorna o conteúdo processado de uma view em vez de exibir
     */
    public static function fetch(string $view, array $data = []): string
    {
        $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Views';
        
        $viewPath = str_replace(['/', '\\', '.'], DIRECTORY_SEPARATOR, $view);
        $file = $base . DIRECTORY_SEPARATOR . $viewPath . '.php';

        if (!is_file($file)) {
            return "Erro: View '{$view}' não encontrada no caminho '{$file}'";
        }

        extract($data, EXTR_SKIP);
        
        ob_start();
        require $file;
        return ob_get_clean();
    }
}

