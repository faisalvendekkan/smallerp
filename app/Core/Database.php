<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper shared by the whole application.
 *
 * Only two drivers are supported -- SQLite for a single-branch install and
 * MySQL for shared hosting -- and the schema is written to run on both, so
 * an SME can start on SQLite and move to MySQL when it outgrows it.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $transactionDepth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = (string) Config::get('db.driver', 'sqlite');
        self::$pdo = $driver === 'mysql' ? self::connectMysql() : self::connectSqlite();
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        return self::$pdo;
    }

    private static function connectSqlite(): PDO
    {
        $path = (string) Config::get('db.sqlite.path');
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create database directory: {$dir}");
        }

        $pdo = new PDO('sqlite:' . $path);
        // WAL lets reports run while invoices are being written; foreign keys
        // are off by default in SQLite and must be enabled per connection.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    private static function connectMysql(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) Config::get('db.mysql.host'),
            (int) Config::get('db.mysql.port', 3306),
            (string) Config::get('db.mysql.database'),
            (string) Config::get('db.mysql.charset', 'utf8mb4')
        );

        return new PDO(
            $dsn,
            (string) Config::get('db.mysql.username'),
            (string) Config::get('db.mysql.password'),
            [PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES'"]
        );
    }

    public static function driver(): string
    {
        return (string) self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Reset the connection -- used by the test suite between cases. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$transactionDepth = 0;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($name, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();

        return $value === false ? $default : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier($table),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );
        self::query($sql, self::bindable($data));

        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, array $where): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = self::quoteIdentifier($column) . ' = :set_' . $column;
        }
        $conditions = [];
        foreach (array_keys($where) as $column) {
            $conditions[] = self::quoteIdentifier($column) . ' = :where_' . $column;
        }

        $params = [];
        foreach (self::bindable($data) as $key => $value) {
            $params['set_' . $key] = $value;
        }
        foreach (self::bindable($where) as $key => $value) {
            $params['where_' . $key] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::quoteIdentifier($table),
            implode(', ', $sets),
            implode(' AND ', $conditions)
        );

        return self::query($sql, $params)->rowCount();
    }

    public static function delete(string $table, array $where): int
    {
        $conditions = [];
        foreach (array_keys($where) as $column) {
            $conditions[] = self::quoteIdentifier($column) . ' = :' . $column;
        }
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            self::quoteIdentifier($table),
            implode(' AND ', $conditions)
        );

        return self::query($sql, self::bindable($where))->rowCount();
    }

    /**
     * Run a callback inside a transaction, nesting safely via savepoints.
     *
     * Posting an invoice writes the document, its lines, stock moves and the
     * journal; all of it has to commit together or the books stop balancing.
     *
     * @template T
     * @param  callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $depth = self::$transactionDepth;

        if ($depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT erp_sp_' . $depth);
        }
        self::$transactionDepth++;

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            self::$transactionDepth--;
            if ($depth === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT erp_sp_' . $depth);
            }
            throw $e;
        }

        self::$transactionDepth--;
        if ($depth === 0) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT erp_sp_' . $depth);
        }

        return $result;
    }

    public static function tableExists(string $table): bool
    {
        try {
            if (self::driver() === 'sqlite') {
                return self::value(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
                    [$table],
                    0
                ) > 0;
            }

            return self::value(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
                0
            ) > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Quote an identifier for the active driver.
     *
     * Identifiers are never user input in this codebase, but the whitelist
     * keeps it that way even if a future caller gets careless.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }

        return self::driver() === 'mysql' ? "`{$identifier}`" : "\"{$identifier}\"";
    }

    /** Convert PHP values into something PDO can bind on both drivers. */
    private static function bindable(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
