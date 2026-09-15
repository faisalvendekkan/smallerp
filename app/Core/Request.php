<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Money;
use App\Support\Text;
use App\Support\ValidationException;

/** The current HTTP request, with typed accessors for form input. */
final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $files;
    /** @var array<string,string> Parameters captured from the matched route. */
    private array $routeParams = [];

    public function __construct(array $query, array $body, array $server, array $files = [])
    {
        $this->query = $query;
        $this->body = $body;
        $this->server = $server;
        $this->files = $files;
    }

    public static function capture(): self
    {
        $body = $_POST;

        // Support JSON bodies so the front end can post without form encoding.
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self($_GET, $body, $_SERVER, $_FILES);
    }

    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        // Browsers only send GET and POST, so honour the override field that
        // the delete/update forms include.
        if ($method === 'POST') {
            $override = strtoupper((string) ($this->body['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Strip the subdirectory the app is installed under, if any.
        $scriptDir = rtrim(str_replace('\\', '/', dirname($this->server['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        return '/' . trim($path, '/');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** True for fetch/XHR calls, which get JSON errors rather than HTML pages. */
    public function wantsJson(): bool
    {
        $accept = $this->server['HTTP_ACCEPT'] ?? '';
        $requestedWith = $this->server['HTTP_X_REQUESTED_WITH'] ?? '';

        return str_contains($accept, 'application/json')
            || strtolower($requestedWith) === 'xmlhttprequest';
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeInt(string $key): int
    {
        return (int) ($this->routeParams[$key] ?? 0);
    }

    /** Raw value from body first, then query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        if (is_array($value)) {
            return $default;
        }

        return trim((string) $value);
    }

    /** A required string; throws with the field name if it is blank. */
    public function required(string $key, string $label = ''): string
    {
        $value = $this->string($key);
        if ($value === '') {
            throw new ValidationException(($label ?: $key) . ' is required', $key);
        }

        return $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) Text::westernDigits((string) $value);
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (float) str_replace(',', '', Text::westernDigits((string) $value));
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        return in_array(strtolower((string) $this->input($key)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Read a money field as integer dirhams. */
    public function money(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return Money::toDirhams($value, $key);
    }

    /** Read a date field, validating the YYYY-MM-DD format. */
    public function date(string $key, ?string $default = null): ?string
    {
        $value = $this->string($key);
        if ($value === '') {
            return $default;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ValidationException("{$key} must be a date in YYYY-MM-DD format", $key);
        }

        return $value;
    }

    /** @return array<int,mixed> A repeated form field, e.g. line items. */
    public function array(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Rebuild repeated line-item inputs into a list of rows.
     *
     * Forms post `lines[qty][]`, `lines[price][]` and so on; this turns those
     * parallel arrays into one row per line, which is far easier to validate.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(string $key): array
    {
        $raw = $this->input($key, []);
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $field => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $index => $value) {
                $rows[$index][$field] = $value;
            }
        }
        ksort($rows);

        return array_values($rows);
    }

    public function all(): array
    {
        return $this->body + $this->query;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) ? $file : null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return Text::truncate((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 250);
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') !== ''
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * A safe "go back where you came from" target.
     *
     * The Referer header is supplied by the client, so putting it straight
     * into a Location header is an open redirect: a link on someone else's
     * site could bounce a user from your domain to a copy of the sign-in page.
     * Only the path and query of a same-host referer are honoured; anything
     * else falls back.
     */
    public function backUrl(string $fallback = '/'): string
    {
        $referer = trim((string) ($this->server['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return $fallback;
        }

        $parts = parse_url($referer);
        if ($parts === false) {
            return $fallback;
        }

        // A referer naming any other host is not ours to redirect to. This
        // also catches the protocol-relative "//evil.com" form, which
        // parse_url() reports as a host.
        //
        // Compare hostnames only: parse_url() splits the port out, while
        // HTTP_HOST keeps it, so "127.0.0.1" and "127.0.0.1:8000" are the same
        // machine and must not be treated as a cross-host redirect.
        $host = (string) ($parts['host'] ?? '');
        $ourHost = preg_replace('/:\d+$/', '', (string) ($this->server['HTTP_HOST'] ?? '')) ?? '';
        if ($host !== '' && ($ourHost === '' || strcasecmp($host, $ourHost) !== 0)) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** Base path the app is served from, for building URLs in views. */
    public function basePath(): string
    {
        $dir = rtrim(str_replace('\\', '/', dirname($this->server['SCRIPT_NAME'] ?? '')), '/');

        return $dir === '/' ? '' : $dir;
    }
}
