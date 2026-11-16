<?php

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\DB;

function migrate() {
    $db = DB::connection();

    // Criar tabela de migrations se não existir
    $db->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $executedMigrations = $db->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);

    // Caminho das migrations
    $migrationsPath = realpath(__DIR__ . '/../migrations');
    if (!$migrationsPath || !is_dir($migrationsPath)) {
        echo "Error: Migrations directory not found at " . (__DIR__ . '/../migrations') . "\n";
        exit(1);
    }

    // Buscar arquivos .sql e .SQL (sensibilidade a maiúsculas no Linux)
    $migrationFiles = glob($migrationsPath . '/*.{sql,SQL}', GLOB_BRACE);
    
    // Fallback caso GLOB_BRACE não seja suportado em algum ambiente
    if ($migrationFiles === false) {
        $migrationFiles = array_merge(
            glob($migrationsPath . '/*.sql') ?: [],
            glob($migrationsPath . '/*.SQL') ?: []
        );
    }

    sort($migrationFiles);

    echo "Found " . count($migrationFiles) . " migration files in $migrationsPath\n";
    echo "Already executed: " . count($executedMigrations) . "\n";

    $count = 0;
    foreach ($migrationFiles as $file) {
        $migrationName = basename($file);
        
        if (!in_array($migrationName, $executedMigrations)) {
            echo "Migrating: $migrationName\n";
            
            $sql = file_get_contents($file);
            
            try {
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Separar os comandos por ; e executar um por um para ignorar erros específicos se necessário
                // ou apenas executar o arquivo inteiro. 
                // Para tornar amigável a migrações manuais prévias, vamos capturar erros de "Duplicate column"
                $sql = file_get_contents($file);
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                
                foreach ($statements as $stmtText) {
                    try {
                        $db->exec($stmtText);
                    } catch (PDOException $e) {
                        // Ignorar erro 1060 (Duplicate column name) ou 1061 (Duplicate key name) 
                        // ou 1091 (Can't DROP 'column'; check that column/key exists)
                        if (in_array($e->errorInfo[1], [1060, 1061, 1091, 1050])) {
                            echo "      [!] Aviso: Comando ignorado (já aplicado): " . substr($stmtText, 0, 50) . "...\n";
                            continue;
                        }
                        throw $e;
                    }
                }
                
                $stmt = $db->prepare("INSERT INTO migrations (migration) VALUES (?)");
                $stmt->execute([$migrationName]);
                
                echo "Migrated:  $migrationName\n";
                $count++;
            } catch (Exception $e) {
                echo "Failed:    $migrationName\n";
                echo "Error:     " . $e->getMessage() . "\n";
                exit(1);
            }
        }
    }

    if ($count === 0) {
        echo "Nothing to migrate. All files found in " . basename($migrationsPath) . " already exist in migrations table.\n";
    } else {
        echo "Successfully migrated $count files.\n";
    }
}

migrate();
