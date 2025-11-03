<?php

namespace FubberTool;

use PDO;
use PDOStatement;

/**
 * Clean database abstraction with automatic statement caching and debug logging
 */
class DB
{
    private PDO $pdo;
    /** @var array<string, PDOStatement> */
    private array $stmtCache = [];

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        $this->pdo = new PDO($dsn, $username, $password, $options);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Set fetch mode for individual statements too
        // This ensures that all prepared statements default to FETCH_ASSOC
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    }

    /**
     * Execute a query without parameters (DDL, simple updates)
     *
     * @return int|false Number of affected rows or false on failure
     */
    public function exec(string $sql)
    {
        $output = $GLOBALS['fubber_output'] ?? null;
        if ($output) {
            $start = microtime(true);
            $result = $this->pdo->exec($sql);
            $time = sprintf('%.2fms', (microtime(true) - $start) * 1000);
            $status = $result !== false ? 'success' : 'failed';
            $output->debug(3, "SQL: {query} → {status} ({time})", [
                'query' => preg_replace('/\s+/', ' ', trim($sql)),
                'status' => $status,
                'time' => $time
            ]);
            return $result;
        }
        return $this->pdo->exec($sql);
    }

    /**
     * Execute a prepared statement and return affected row count
     * Use this for INSERT, UPDATE, DELETE with parameters
     *
     * @param array $params Parameters to bind (either positional or named)
     * @return int Number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Query for multiple rows
     *
     * @param array $params Parameters to bind
     * @return array Array of associative arrays
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->prepare($sql);
        $output = $GLOBALS['fubber_output'] ?? null;
        if ($output) {
            $start = microtime(true);
            $stmt->execute($params);
            $result = $stmt->fetchAll();
            $time = sprintf('%.2fms', (microtime(true) - $start) * 1000);
            $output->debug(3, "SQL: {query} → {count} rows ({time})", [
                'query' => preg_replace('/\s+/', ' ', trim($sql)),
                'count' => count($result),
                'time' => $time
            ]);
            return $result;
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Query for a single row
     *
     * @param array $params Parameters to bind
     * @return array|false Associative array or false if no row found
     */
    public function queryRow(string $sql, array $params = []): array|false
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result !== false ? $result : false;
    }

    /**
     * Query for a single column value
     *
     * @param array $params Parameters to bind
     * @return mixed The value of the first column, or false if no row found
     */
    public function queryValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Query for a single column from multiple rows
     *
     * @param array $params Parameters to bind
     * @return array Array of scalar values
     */
    public function queryColumn(string $sql, array $params = []): array
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Prepare a statement (with caching)
     * Use this for custom statement handling
     */
    public function prepare(string $sql): PDOStatement
    {
        // Create cache key by normalizing whitespace
        $cacheKey = preg_replace('/\s+/', ' ', trim($sql));

        if (!isset($this->stmtCache[$cacheKey])) {
            $this->stmtCache[$cacheKey] = $this->pdo->prepare($sql);
        }

        return $this->stmtCache[$cacheKey];
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Roll back a transaction
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Check if currently in a transaction
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId(?string $name = null): string|false
    {
        return $this->pdo->lastInsertId($name);
    }

    /**
     * Set a PDO attribute
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->pdo->setAttribute($attribute, $value);
    }

    /**
     * Get a PDO attribute
     */
    public function getAttribute(int $attribute): mixed
    {
        return $this->pdo->getAttribute($attribute);
    }

    /**
     * Get the underlying PDO instance (for advanced operations)
     */
    public function getPDO(): PDO
    {
        return $this->pdo;
    }

}
