<?php

declare(strict_types=1);

/**
 * SmallERP bootstrap: a PSR-4 style autoloader over the App\ namespace and the
 * global helper functions. No Composer, by design -- this has to deploy by
 * uploading a folder to a cPanel account.
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('SmallERP requires PHP 8.1 or newer. This server is running ' . PHP_VERSION . '.');
}

spl_autoload_register(static function (string $class): void {
    // App\ lives in /app, Database\ in /database.
    $roots = [
        'App\\' => __DIR__ . '/',
        'Database\\' => dirname(__DIR__) . '/database/',
    ];

    foreach ($roots as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});

require_once __DIR__ . '/Core/Helpers.php';

App\Core\App::boot(dirname(__DIR__));
