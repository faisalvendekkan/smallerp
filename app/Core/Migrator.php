<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Creates the schema on a fresh database.
 *
 * The schema is written once in SQLite dialect; the handful of tokens that
 * differ on MySQL are rewritten here. That keeps a single source of truth for
 * the table definitions instead of two files that drift apart.
 */
final class Migrator
{
    public static function install(): void
    {
        $sql = file_get_contents(Config::root() . '/database/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Cannot read database/schema.sql');
        }

        $isMysql = Database::driver() === 'mysql';
        foreach (self::splitStatements($sql) as $statement) {
            Database::pdo()->exec($isMysql ? self::toMysql($statement) : $statement);
        }
    }

    /** Split a script into statements, ignoring semicolons inside comments. */
    private static function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--')) {
                continue;
            }
            // Strip a trailing comment, but not one inside a quoted string.
            if (($pos = strpos($line, '--')) !== false && substr_count(substr($line, 0, $pos), "'") % 2 === 0) {
                $line = substr($line, 0, $pos);
            }
            $lines[] = $line;
        }

        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private static function toMysql(string $statement): string
    {
        // MySQL spells auto-increment differently and needs the storage engine
        // and charset stated for a table to accept Arabic text reliably.
        $statement = str_replace(
            'INTEGER PRIMARY KEY AUTOINCREMENT',
            'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            $statement
        );
        $statement = preg_replace('/\bREAL\b/', 'DECIMAL(14,4)', $statement) ?? $statement;

        if (str_starts_with(strtoupper($statement), 'CREATE TABLE')) {
            $statement .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        // MySQL requires index names to be unique only per table, but keeping
        // the global names from the SQLite script is harmless and simpler.
        return $statement;
    }

    public static function isInstalled(): bool
    {
        return Database::tableExists('users') && Database::tableExists('accounts');
    }
}
