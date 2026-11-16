<?php

/**
 * Configuração completa do banco: cria o banco, aplica as migrations e garante o admin.
 * Cada script roda em um processo PHP separado (mesmo padrão do Stok Net).
 */
$scripts = ['create_db.php', 'migrate.php', 'seed.php'];
$php = PHP_BINARY ?: 'php';

echo "=== CONFIGURANDO O BANCO DO PORTAL DE TREINAMENTOS ===\n\n";
foreach ($scripts as $script) {
    $path = __DIR__ . '/' . $script;
    echo ">>> Executando: $script\n";
    passthru('"' . $php . '" "' . $path . '"', $code);
    if ($code !== 0) {
        echo "\n [!] ERRO ao executar $script (código $code). Abortando.\n";
        exit(1);
    }
    echo "--------------------------------------------------\n";
}
echo "\n=== BANCO PRONTO! Rode o iniciar-servidor.bat e acesse o portal. ===\n";
