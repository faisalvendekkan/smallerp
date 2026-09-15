<?php

declare(strict_types=1);

/**
 * Production readiness check.
 *
 * Upload this alongside the app and run it once from the hPanel terminal, or
 * temporarily from the browser, after the first deploy:
 *
 *     php deploy/preflight.php
 *
 * It checks the things that actually go wrong on shared hosting, and says
 * plainly whether the install is safe to use. Delete it afterwards if you
 * reached it over the web.
 */

$root = dirname(__DIR__);
$problems = [];
$warnings = [];
$ok = [];

// --- PHP itself --------------------------------------------------------

if (PHP_VERSION_ID < 80100) {
    $problems[] = 'PHP ' . PHP_VERSION . ' is too old — set 8.1 or newer in hPanel.';
} else {
    $ok[] = 'PHP ' . PHP_VERSION;
}

foreach (['mbstring' => true, 'pdo' => true] as $extension => $required) {
    if (!extension_loaded($extension)) {
        $problems[] = "The {$extension} extension is not loaded.";
    }
}

$drivers = PDO::getAvailableDrivers();
if (!in_array('sqlite', $drivers, true) && !in_array('mysql', $drivers, true)) {
    $problems[] = 'Neither pdo_sqlite nor pdo_mysql is available.';
} else {
    $ok[] = 'PDO drivers: ' . implode(', ', $drivers);
}

// --- Configuration -----------------------------------------------------

$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    $problems[] = 'config.php is missing — copy deploy/config.production.php to the app root and edit it.';
} else {
    $config = require $configFile;
    $ok[] = 'config.php found';

    if (!empty($config['debug'])) {
        $problems[] = 'debug is TRUE. Turn it off: it prints stack traces and credentials to visitors.';
    } else {
        $ok[] = 'debug is off';
    }

    $key = (string) ($config['app_key'] ?? '');
    if ($key === '' || str_contains($key, 'CHANGE-ME')) {
        $problems[] = 'app_key has not been set. Generate one: php -r "echo bin2hex(random_bytes(32));"';
    } elseif (strlen($key) < 32) {
        $warnings[] = 'app_key is short; 64 hex characters is the intended length.';
    } else {
        $ok[] = 'app_key is set';
    }

    if (empty($config['https_only'])) {
        $warnings[] = 'https_only is false. Hostinger gives every domain a free certificate — turn it on.';
    } else {
        $ok[] = 'https_only is on';
    }

    if (($config['db']['driver'] ?? '') === 'mysql'
        && str_contains((string) ($config['db']['mysql']['password'] ?? ''), 'CHANGE-ME')) {
        $problems[] = 'The MySQL password is still the placeholder.';
    }
}

// --- Writable storage --------------------------------------------------

$storage = $root . '/storage';
if (!is_dir($storage)) {
    $warnings[] = 'storage/ does not exist yet — the app will try to create it on first run.';
} elseif (!is_writable($storage)) {
    $problems[] = 'storage/ is not writable. Set it to 755 (or 775) in the hPanel file manager.';
} else {
    $ok[] = 'storage/ is writable';
}

// --- The directories that must not be reachable over HTTP --------------

foreach (['app', 'database', 'storage', 'views'] as $directory) {
    if (!is_file($root . '/' . $directory . '/.htaccess')) {
        $warnings[] = "{$directory}/.htaccess is missing — web access to it may not be blocked.";
    }
}

// --- Report ------------------------------------------------------------

$line = str_repeat('-', 62);
echo "SmallERP preflight\n{$line}\n";

foreach ($ok as $message) {
    echo "  ok    {$message}\n";
}
foreach ($warnings as $message) {
    echo "  warn  {$message}\n";
}
foreach ($problems as $message) {
    echo "  FAIL  {$message}\n";
}

echo $line, "\n";

if ($problems !== []) {
    echo count($problems), " problem(s) must be fixed before going live.\n";
    exit(1);
}

echo $warnings === []
    ? "Ready for production.\n"
    : "Usable, but review the warnings above.\n";
exit(0);
