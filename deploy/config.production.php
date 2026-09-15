<?php
/**
 * Production configuration template for Hostinger.
 *
 * Copy this file to the application root as `config.php` on the SERVER ONLY.
 * It is git-ignored and excluded from deployment, so it is written once by
 * hand and never overwritten by a deploy.
 *
 * Generate the app key first, on any machine with PHP:
 *
 *     php -r "echo bin2hex(random_bytes(32));"
 */

return [
    'db' => [
        // 'sqlite' needs no database server and is a sound choice for a
        // single-branch company. Move to 'mysql' if several people will be
        // working in the system at once on shared hosting.
        'driver' => 'mysql',

        'sqlite' => [
            // Must sit OUTSIDE the web root if you can manage it. If the
            // document root is public_html/public, then storage/ is already
            // outside it and this default is fine.
            'path' => __DIR__ . '/storage/smallerp.sqlite',
        ],

        'mysql' => [
            // hPanel -> Databases -> Management. Hostinger prefixes the
            // database name and username, e.g. u123456789_smallerp.
            'host'     => 'localhost',
            'port'     => 3306,
            'database' => 'u000000000_smallerp',
            'username' => 'u000000000_erp',
            'password' => 'CHANGE-ME',
            'charset'  => 'utf8mb4',
        ],
    ],

    // 32 random bytes, hex encoded. Changing it signs everyone out.
    'app_key' => 'CHANGE-ME-64-HEX-CHARACTERS',

    // Minutes of inactivity before a session expires. An unattended terminal
    // in a shared office is the most realistic threat to a small company's
    // books, so keep this short rather than generous.
    'session_lifetime' => 240,

    'default_locale' => 'en',

    // Hostinger issues a free SSL certificate for every domain, so there is no
    // reason for this to be false. It marks the session cookie Secure.
    'https_only' => true,

    // NEVER true on a live server: it would print stack traces, including
    // database credentials, to whoever triggered the error.
    'debug' => false,

    'timezone' => 'Asia/Qatar',
];
