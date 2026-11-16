<?php

namespace App\Core;

use App\Config\Database;
use PDO;

class DB
{
    public static function connection(): PDO
    {
        return Database::connection();
    }

    /**
     * @param string $table
     * @return QueryBuilder
     */
    public static function table(string $table): QueryBuilder
    {
        return (new QueryBuilder(self::connection()))->table($table);
    }

    public static function select(string $sql, array $bindings = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    public static function statement(string $sql, array $bindings = []): bool
    {
        $stmt = self::connection()->prepare($sql);
        return $stmt->execute($bindings);
    }
}

