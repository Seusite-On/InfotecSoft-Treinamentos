<?php

namespace App\Core;

use PDO;

class QueryBuilder
{
    protected PDO $pdo;
    protected string $table = '';
    protected array $columns = ['*'];
    protected array $wheres = [];
    protected array $joins = [];
    protected array $orders = [];
    protected ?int $limit = null;
    protected ?int $offset = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function table(string $table): QueryBuilder
    {
        $this->assertSafeTableReference($table);
        $this->table = $table;
        return $this;
    }

    public function select($columns = ['*']): QueryBuilder
    {
        if (is_array($columns)) {
            $this->columns = $columns;
        } else {
            $this->columns = func_get_args();
        }
        return $this;
    }

    public function where(string $column, $operatorOrValue, $value = null): QueryBuilder
    {
        $this->assertSafeColumnReference($column);

        if ($value === null) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = $operatorOrValue;
        }
        $placeholder = $this->placeholder($column);
        $this->wheres[] = ["$column $operator $placeholder", [$placeholder => $value], 'AND'];
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): QueryBuilder
    {
        $this->wheres[] = [$sql, $bindings, $boolean];
        return $this;
    }

    public function orWhere(string $column, $operatorOrValue, $value = null): QueryBuilder
    {
        $this->assertSafeColumnReference($column);

        if ($value === null) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = $operatorOrValue;
        }
        $placeholder = $this->placeholder($column);
        $this->wheres[] = ["$column $operator $placeholder", [$placeholder => $value], 'OR'];
        return $this;
    }

    public function join(string $table, $first, $operator = null, $second = null, string $type = 'INNER'): QueryBuilder
    {
        $this->assertSafeTableReference($table);

        if (is_string($first) && $operator === null && $second === null) {
            // Suporte para join('table', 'a.id = b.id')
            $this->joins[] = [$type, $table, $first];
        } elseif ($second === null) {
            // Suporte para join('table', 'a.id', 'b.id') -> assume '='
            $this->joins[] = [$type, $table, "$first = $operator"];
        } else {
            // Suporte completo join('table', 'a.id', '=', 'b.id')
            $this->joins[] = [$type, $table, "$first $operator $second"];
        }
        return $this;
    }

    public function leftJoin(string $table, $first, $operator = null, $second = null): QueryBuilder
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, $first, $operator = null, $second = null): QueryBuilder
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    public function crossJoin(string $table, $first, $operator = null, $second = null): QueryBuilder
    {
        return $this->join($table, $first, $operator, $second, 'CROSS');
    }

    public function innerJoin(string $table, $first, $operator = null, $second = null): QueryBuilder
    {
        return $this->join($table, $first, $operator, $second, 'INNER');
    }

    public function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        $this->assertSafeColumnReference($column);

        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = "$column $dir";
        return $this;
    }

    public function limit(int $limit): QueryBuilder
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): QueryBuilder
    {
        // Evita OFFSET negativo (ex.: ?page=0 ou ?page=abc), que gera erro de SQL
        $this->offset = max(0, $offset);
        return $this;
    }

    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    public function first(): ?array
    {
        $originalLimit = $this->limit;
        $this->limit(1);
        $rows = $this->get();
        $this->limit = $originalLimit;
        return $rows[0] ?? null;
    }

    /**
     * @param array $data
     * @return string|bool
     */
    public function insert(array $data): string|bool
    {
        $columns = array_keys($data);
        $placeholders = [];
        $bindings = [];
        foreach ($data as $col => $val) {
            $ph = $this->placeholder($col);
            $placeholders[] = $ph;
            $bindings[$ph] = $val;
        }
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $id = $this->pdo->lastInsertId();
        return $id !== '0' ? $id : true;
    }

    public function update(array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        $sets = [];
        $bindings = [];
        foreach ($data as $col => $val) {
            $ph = $this->placeholder('set_' . $col);
            $sets[] = "$col = $ph";
            $bindings[$ph] = $val;
        }

        [$whereSql, $whereBindings] = $this->compileWheres();
        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets);

        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($bindings, $whereBindings));
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        [$whereSql, $whereBindings] = $this->compileWheres();
        $sql = 'DELETE FROM ' . $this->table;

        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($whereBindings);
        return $stmt->rowCount();
    }

    public function toSql(): string
    {
        [$sql] = $this->compileSelect();
        return $sql;
    }

    public function bindings(): array
    {
        [, $bindings] = $this->compileSelect();
        return $bindings;
    }

    protected function compileSelect(): array
    {
        $columns = implode(', ', $this->columns);
        $sql = 'SELECT ' . $columns . ' FROM ' . $this->table;
        foreach ($this->joins as [$type, $table, $on]) {
            $sql .= ' ' . $type . ' JOIN ' . $table . ' ON ' . $on;
        }
        [$whereSql, $bindings] = $this->compileWheres();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }
        if ($this->orders) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        return [$sql, $bindings];
    }

    protected function compileWheres(): array
    {
        if (!$this->wheres) {
            return ['', []];
        }
        $sql = '';
        $bindings = [];
        foreach ($this->wheres as $i => [$fragment, $bind, $boolean]) {
            if ($i === 0) {
                $sql .= $fragment;
            } else {
                $sql .= ' ' . $boolean . ' ' . $fragment;
            }
            $bindings += $bind;
        }
        return [$sql, $bindings];
    }

    public function count(): int
    {
        $originalColumns = $this->columns;
        $this->columns = ['COUNT(*) as total'];
        $row = $this->first();
        $this->columns = $originalColumns;
        return (int)($row['total'] ?? 0);
    }

    protected function placeholder(string $column): string
    {
        $key = preg_replace('/[^a-zA-Z0-9_]/', '_', $column) . '_' . substr(uniqid('', true), -6);
        return ':' . $key;
    }

    private function assertSafeTableReference(string $table): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?$/i', trim($table))) {
            throw new \InvalidArgumentException('Identificador de tabela inválido.');
        }
    }

    private function assertSafeColumnReference(string $column): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', trim($column))) {
            throw new \InvalidArgumentException('Identificador de coluna inválido.');
        }
    }
}

