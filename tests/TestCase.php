<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Services\ChartOfAccounts;
use App\Services\Settings;

/**
 * A minimal test harness.
 *
 * PHPUnit would be the obvious choice, but SmallERP deliberately has no
 * Composer dependencies — it has to deploy by copying a folder onto a cPanel
 * account — so the tests run on the same terms as the application.
 */
abstract class TestCase
{
    protected int $assertions = 0;
    /** @var string[] */
    public array $failures = [];
    public int $passed = 0;

    /** Point the app at a throwaway in-memory-ish database and build the schema. */
    protected function freshDatabase(): void
    {
        $path = sys_get_temp_dir() . '/smallerp-test-' . getmypid() . '.sqlite';
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        // Config is loaded once per process, so the path is swapped by hand.
        $reflection = new \ReflectionClass(Config::class);
        $property = $reflection->getProperty('values');
        $property->setAccessible(true);
        $values = $property->getValue();
        $values['db']['driver'] = 'sqlite';
        $values['db']['sqlite']['path'] = $path;
        $property->setValue(null, $values);

        Database::reset();
        ChartOfAccounts::clearCache();
        Settings::clearCache();
        Migrator::install();
    }

    /** Set up the accounts and warehouse the posting engine needs. */
    protected function seedBase(): void
    {
        ChartOfAccounts::install();
        Database::insert('warehouses', [
            'code' => 'MAIN',
            'name_en' => 'Main Store',
            'name_ar' => 'المخزن',
            'is_default' => 1,
            'is_active' => 1,
        ]);
    }

    // ------------------------------------------------------------------
    // Assertions
    // ------------------------------------------------------------------

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected === $actual) {
            $this->passed++;

            return;
        }
        $this->fail(sprintf(
            '%s expected %s, got %s',
            $message !== '' ? $message . ':' : 'Assertion failed:',
            $this->describe($expected),
            $this->describe($actual)
        ));
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected == $actual) {
            $this->passed++;

            return;
        }
        $this->fail(sprintf(
            '%s expected %s, got %s',
            $message !== '' ? $message . ':' : 'Assertion failed:',
            $this->describe($expected),
            $this->describe($actual)
        ));
    }

    protected function assertTrue(bool $condition, string $message = 'Expected true'): void
    {
        $this->assertions++;
        if ($condition) {
            $this->passed++;

            return;
        }
        $this->fail($message);
    }

    protected function assertFalse(bool $condition, string $message = 'Expected false'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    /** Assert that $callback throws, optionally matching part of the message. */
    protected function assertThrows(callable $callback, string $expectedMessage = '', string $message = ''): void
    {
        $this->assertions++;
        try {
            $callback();
        } catch (\Throwable $e) {
            // A ValidationException carries its detail in errors[], so a
            // summary message must not hide what actually went wrong.
            $haystack = $e->getMessage();
            if ($e instanceof \App\Support\ValidationException) {
                $haystack .= ' ' . implode(' ', $e->allMessages());
            }

            if ($expectedMessage === '' || str_contains($haystack, $expectedMessage)) {
                $this->passed++;

                return;
            }
            $this->fail(sprintf(
                '%s expected a message containing "%s", got "%s"',
                $message !== '' ? $message . ':' : 'Wrong exception:',
                $expectedMessage,
                $haystack
            ));

            return;
        }
        $this->fail(($message !== '' ? $message . ': ' : '') . 'Expected an exception, none was thrown');
    }

    private function fail(string $message): void
    {
        $this->failures[] = $message;
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => 'null',
            is_array($value) => 'array' . json_encode($value, JSON_UNESCAPED_UNICODE),
            default => var_export($value, true),
        };
    }

    /** Every public method starting with `test` is a case. */
    public function run(): void
    {
        foreach (get_class_methods($this) as $method) {
            if (!str_starts_with($method, 'test')) {
                continue;
            }
            try {
                $this->{$method}();
            } catch (\Throwable $e) {
                $this->failures[] = sprintf(
                    '%s threw %s: %s (%s:%d)',
                    $method,
                    $e::class,
                    $e->getMessage(),
                    basename($e->getFile()),
                    $e->getLine()
                );
            }
        }
    }
}
