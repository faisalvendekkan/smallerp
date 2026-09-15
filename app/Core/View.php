<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Money;
use App\Support\Text;

/**
 * Plain PHP templating.
 *
 * Views are PHP files under /views. There is no template compiler: PHP already
 * is one, and an SME's IT contractor can edit an invoice layout without
 * learning a templating language first.
 */
final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** Render a view inside the main layout. */
    public static function render(string $view, array $data = [], string $layout = 'layout/app'): string
    {
        $content = self::partial($view, $data);

        return self::partial($layout, $data + ['content' => $content]);
    }

    /** Render a view on its own, with no layout. Used for print pages. */
    public static function partial(string $view, array $data = []): string
    {
        $file = Config::root() . '/views/' . str_replace(['..', '\\'], '', $view) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }

        extract(self::$shared, EXTR_SKIP);
        extract($data, EXTR_OVERWRITE);

        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /** Flash a message to be shown once on the next page. */
    public static function flash(string $message, string $type = 'success'): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type:string,message:string}|null */
    public static function takeFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $flash;
    }

    /** Remember submitted values so a failed form can be re-rendered filled in. */
    public static function flashInput(array $input): void
    {
        unset($input['_token'], $input['_method'], $input['password'], $input['password_confirmation']);
        $_SESSION['old_input'] = $input;
    }

    public static function takeOldInput(): array
    {
        $old = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);

        return $old;
    }
}
