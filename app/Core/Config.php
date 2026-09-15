<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application configuration, loaded once from config.php.
 *
 * Falls back to config.example.php so a fresh clone boots without any setup
 * step -- handy for evaluating the system before committing to a database.
 */
final class Config
{
    private static ?array $values = null;

    public static function load(string $root): void
    {
        $file = $root . '/config.php';
        if (!is_file($file)) {
            $file = $root . '/config.example.php';
        }

        /** @var array $values */
        $values = require $file;
        $values['root'] = $root;

        if (($values['app_key'] ?? '') === '') {
            // Derive a stable key from the install path so sessions survive a
            // restart. A real deployment should set its own in config.php.
            $values['app_key'] = hash('sha256', 'smallerp:' . $root);
            $values['app_key_is_derived'] = true;
        }

        self::$values = $values;
        date_default_timezone_set($values['timezone'] ?? 'Asia/Qatar');
    }

    /** Read a dotted config path, e.g. Config::get('db.mysql.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$values === null) {
            throw new \RuntimeException('Config::load() must be called before Config::get()');
        }

        $value = self::$values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function root(): string
    {
        return (string) self::get('root', dirname(__DIR__, 2));
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('debug', false);
    }
}
