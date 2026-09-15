<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\ValidationException;
use Database\Seeder;

/**
 * First-run installer.
 *
 * Runs automatically when the database has no schema, so uploading the folder
 * to a hosting account and opening the site is the whole of the installation.
 */
final class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        return Response::html(View::render('install/setup', [
            'title' => 'Set up SmallERP',
            'driver' => Config::get('db.driver'),
            'checks' => $this->environmentChecks(),
        ], 'layout/blank'));
    }

    public function run(Request $request): Response
    {
        if (Migrator::isInstalled()) {
            return $this->redirect('/login', 'SmallERP is already installed.', 'info');
        }

        $name = $request->required('name', 'Your name');
        $username = strtolower($request->required('username', 'Username'));
        $password = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirmation', '');
        $email = $request->string('email');

        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            throw new ValidationException(
                'The username may only contain letters, numbers, dots, dashes and underscores',
                'username'
            );
        }
        if ($password !== $confirm) {
            throw new ValidationException('The two passwords do not match', 'password_confirmation');
        }
        Auth::assertPasswordStrength($password);

        $failed = array_filter($this->environmentChecks(), static fn (array $c): bool => !$c['ok'] && $c['required']);
        if ($failed !== []) {
            throw ValidationException::withErrors(
                array_column($failed, 'label'),
                'The server is not ready yet'
            );
        }

        try {
            Migrator::install();
            Seeder::installBase($name, $username, $password, $email);

            if ($request->bool('demo_data')) {
                Seeder::installDemoData();
            }
        } catch (\PDOException $e) {
            throw new ValidationException('The database could not be set up: ' . $e->getMessage());
        }

        return $this->redirect('/login', 'SmallERP is ready. Sign in with the account you just created.');
    }

    /**
     * Things that have to be true before the app will work.
     *
     * @return array<int,array{label:string,ok:bool,required:bool,detail:string}>
     */
    private function environmentChecks(): array
    {
        $root = Config::root();
        $storage = $root . '/storage';
        $driver = (string) Config::get('db.driver', 'sqlite');

        $checks = [
            [
                'label' => 'PHP 8.1 or newer',
                'ok' => PHP_VERSION_ID >= 80100,
                'required' => true,
                'detail' => PHP_VERSION,
            ],
            [
                'label' => 'PDO ' . $driver . ' driver',
                'ok' => in_array($driver === 'mysql' ? 'mysql' : 'sqlite', \PDO::getAvailableDrivers(), true),
                'required' => true,
                'detail' => implode(', ', \PDO::getAvailableDrivers()),
            ],
            [
                'label' => 'mbstring extension (for Arabic text)',
                'ok' => extension_loaded('mbstring'),
                'required' => true,
                'detail' => extension_loaded('mbstring') ? 'loaded' : 'missing',
            ],
            [
                'label' => 'storage/ is writable',
                'ok' => is_dir($storage) && is_writable($storage),
                'required' => true,
                'detail' => $storage,
            ],
            [
                'label' => 'config.php present (otherwise defaults are used)',
                'ok' => is_file($root . '/config.php'),
                'required' => false,
                'detail' => is_file($root . '/config.php')
                    ? 'found'
                    : 'not found — copy config.example.php to config.php to set your own database and app key',
            ],
        ];

        if ($driver === 'mysql') {
            $connected = false;
            $detail = '';
            try {
                Database::pdo();
                $connected = true;
                $detail = (string) Config::get('db.mysql.database') . ' @ ' . (string) Config::get('db.mysql.host');
            } catch (\Throwable $e) {
                $detail = $e->getMessage();
            }
            $checks[] = [
                'label' => 'MySQL connection',
                'ok' => $connected,
                'required' => true,
                'detail' => $detail,
            ];
        }

        return $checks;
    }
}
