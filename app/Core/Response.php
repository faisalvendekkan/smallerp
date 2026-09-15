<?php

declare(strict_types=1);

namespace App\Core;

/** An HTTP response. Controllers return one of these; the kernel sends it. */
final class Response
{
    private function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = []
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /** A file download, e.g. a WPS SIF file or a CSV export. */
    public static function download(string $body, string $filename, string $contentType = 'text/csv; charset=UTF-8'): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'download';

        return new self($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
            // Baseline hardening. The CSP is deliberately strict: the app ships
            // its own CSS and JS and pulls nothing from a CDN.
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: same-origin');
            if (str_contains($this->headers['Content-Type'] ?? '', 'text/html')) {
                header(
                    "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
                    . "style-src 'self' 'unsafe-inline'; script-src 'self'; form-action 'self'; "
                    . "base-uri 'self'; frame-ancestors 'self'"
                );
            }
        }

        echo $this->body;
    }
}
