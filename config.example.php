<?php
/**
 * SmallERP configuration.
 *
 * Copy this file to config.php and edit it. config.php is git-ignored so your
 * database credentials and app key never reach the repository.
 */

return [
    // 'sqlite' needs no server and is ideal for a single-branch SME.
    // 'mysql' is for shared cPanel hosting or a multi-user office LAN.
    'db' => [
        'driver'   => getenv('ERP_DB_DRIVER') ?: 'sqlite',
        'sqlite'   => ['path' => __DIR__ . '/storage/smallerp.sqlite'],
        'mysql'    => [
            'host'     => getenv('ERP_DB_HOST') ?: '127.0.0.1',
            'port'     => (int) (getenv('ERP_DB_PORT') ?: 3306),
            'database' => getenv('ERP_DB_NAME') ?: 'smallerp',
            'username' => getenv('ERP_DB_USER') ?: 'smallerp',
            'password' => getenv('ERP_DB_PASS') ?: '',
            'charset'  => 'utf8mb4',
        ],
    ],

    // Used to sign session cookies. Generate one with:
    //   php -r "echo bin2hex(random_bytes(32));"
    'app_key' => getenv('ERP_APP_KEY') ?: '',

    // Session lifetime in minutes; users are logged out after this much idle time.
    'session_lifetime' => 480,

    // 'en' or 'ar'. Individual users can override this from the header switcher.
    'default_locale' => 'en',

    // Set true behind HTTPS so the session cookie is marked Secure.
    'https_only' => false,

    // Show stack traces in the browser. Never enable on a live server.
    'debug' => (bool) (getenv('ERP_DEBUG') ?: false),

    'timezone' => 'Asia/Qatar',
];
