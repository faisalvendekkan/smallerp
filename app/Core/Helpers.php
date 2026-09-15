<?php

declare(strict_types=1);

/**
 * Global helpers available inside views.
 *
 * Kept deliberately few and short -- these are the things a template needs on
 * nearly every line, where a fully qualified static call would drown the HTML.
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Lang;
use App\Support\Money;
use App\Support\Text;

/** Escape for HTML. Every value interpolated into a view goes through this. */
function e(mixed $value): string
{
    return Text::escape($value === null ? '' : (string) $value);
}

/** Translate an interface string. */
function __(string $key, array $replace = []): string
{
    return Lang::get($key, $replace);
}

/** Translate and escape -- the common case in templates. */
function t(string $key, array $replace = []): string
{
    return e(Lang::get($key, $replace));
}

/** Build a URL relative to the app's base path. */
function url(string $path = '/', array $query = []): string
{
    $base = rtrim(App\Core\App::basePath(), '/');
    $path = '/' . ltrim($path, '/');
    $url = $base . ($path === '/' ? '/' : rtrim($path, '/'));
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

/** Format integer dirhams for display. */
function money(int|float|null $dirhams, bool $withSymbol = false): string
{
    return Money::format((int) ($dirhams ?? 0), $withSymbol);
}

/** The hidden CSRF input for a form. */
function csrf_field(): string
{
    return Csrf::field();
}

/** Spoof a PUT/PATCH/DELETE from an HTML form. */
function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

/** Can the signed-in user do this? Used to hide buttons they cannot press. */
function can(string $permission): bool
{
    return Auth::can($permission);
}

/** Format a date for display. */
function fdate(?string $date, bool $withTime = false): string
{
    return Lang::date($date, $withTime);
}

/** A CSS class for a document status badge. */
function status_class(string $status): string
{
    return match (strtolower($status)) {
        'paid', 'posted', 'approved', 'active', 'received' => 'ok',
        'partial', 'draft', 'pending', 'submitted' => 'warn',
        'overdue', 'void', 'cancelled', 'rejected', 'expired', 'inactive' => 'bad',
        default => 'muted',
    };
}

/** Re-populate a form field after a validation failure. */
function old(string $key, mixed $default = ''): string
{
    $old = $GLOBALS['__old_input'] ?? [];

    return e($old[$key] ?? $default);
}
