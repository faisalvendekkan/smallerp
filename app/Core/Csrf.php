<?php

declare(strict_types=1);

namespace App\Core;

/** Per-session CSRF token, verified on every state-changing request. */
final class Csrf
{
    private const KEY = '_csrf_token';
    private const FIELD = '_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    public static function verify(Request $request): void
    {
        $supplied = (string) ($request->input(self::FIELD) ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = $_SESSION[self::KEY] ?? '';

        if ($expected === '' || !hash_equals($expected, $supplied)) {
            throw new HttpException(419, 'Your session expired or the form was stale. Please try again.');
        }
    }
}
