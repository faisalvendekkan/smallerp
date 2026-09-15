<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\ValidationException;

/**
 * Authentication, roles and permissions.
 *
 * Roles are coarse on purpose: a five-person trading company in Doha does not
 * want a permission matrix, it wants "the accountant can post journals and the
 * salesman cannot". Permissions are strings like `invoices.create`, granted to
 * roles below and checked by the router.
 */
final class Auth
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_ACCOUNTANT = 'accountant';
    public const ROLE_SALES = 'sales';
    public const ROLE_HR = 'hr';
    public const ROLE_VIEWER = 'viewer';

    /** Passwords below this length are rejected at creation time. */
    public const MIN_PASSWORD_LENGTH = 10;

    /** Lock an account after this many consecutive failures. */
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;

    public const ROLES = [
        self::ROLE_ADMIN => ['name_en' => 'Administrator', 'name_ar' => 'مدير النظام'],
        self::ROLE_ACCOUNTANT => ['name_en' => 'Accountant', 'name_ar' => 'محاسب'],
        self::ROLE_SALES => ['name_en' => 'Sales', 'name_ar' => 'مبيعات'],
        self::ROLE_HR => ['name_en' => 'HR & Payroll', 'name_ar' => 'الموارد البشرية'],
        self::ROLE_VIEWER => ['name_en' => 'Read only', 'name_ar' => 'اطلاع فقط'],
    ];

    /**
     * Permissions granted to each role. `*` means everything.
     *
     * Note that payroll is deliberately walled off: salaries are the one thing
     * an SME owner does not want visible to the sales desk.
     */
    private const GRANTS = [
        self::ROLE_ADMIN => ['*'],
        self::ROLE_ACCOUNTANT => [
            'dashboard.view', 'contacts.*', 'items.*', 'sales.*', 'purchases.*',
            'payments.*', 'accounting.*', 'inventory.*', 'reports.*', 'hr.view',
            'payroll.view', 'payroll.post', 'settings.view',
        ],
        self::ROLE_SALES => [
            'dashboard.view', 'contacts.view', 'contacts.create', 'contacts.edit',
            'items.view', 'sales.*', 'payments.view', 'payments.create',
            'inventory.view', 'reports.sales', 'reports.view',
        ],
        self::ROLE_HR => [
            'dashboard.view', 'hr.*', 'payroll.*', 'reports.hr', 'reports.view',
        ],
        self::ROLE_VIEWER => [
            'dashboard.view', 'contacts.view', 'items.view', 'sales.view',
            'purchases.view', 'payments.view', 'accounting.view',
            'inventory.view', 'reports.view',
        ],
    ];

    private static ?array $user = null;

    public static function startSession(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = (int) Config::get('session_lifetime', 480) * 60;
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $request->basePath() ?: '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (bool) Config::get('https_only', false) || $request->isSecure(),
        ]);
        session_name('smallerp_session');
        session_start();

        // Idle timeout: an unattended terminal in a shared office is the most
        // realistic threat to a small company's books.
        $now = time();
        if (isset($_SESSION['last_seen']) && $now - (int) $_SESSION['last_seen'] > $lifetime) {
            self::logout();
            session_start();
            $_SESSION['flash'] = ['type' => 'info', 'message' => 'Your session expired. Please sign in again.'];
        }
        $_SESSION['last_seen'] = $now;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function assertPasswordStrength(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationException(
                sprintf('Password must be at least %d characters long', self::MIN_PASSWORD_LENGTH),
                'password'
            );
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new ValidationException('Password must contain both letters and numbers', 'password');
        }
    }

    /**
     * Verify credentials and start an authenticated session.
     *
     * @throws ValidationException on bad credentials or a locked account.
     */
    public static function attempt(string $username, string $password, Request $request): array
    {
        $user = Database::first(
            'SELECT * FROM users WHERE username = ? OR email = ?',
            [$username, $username]
        );

        // Always run a hash comparison so a missing user and a wrong password
        // take the same time, and the login form cannot be used to enumerate
        // valid usernames.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $passwordOk = password_verify($password, $hash);

        if ($user && self::isLockedOut($user)) {
            throw new ValidationException(sprintf(
                'Too many failed attempts. Try again in %d minutes.',
                self::LOCKOUT_MINUTES
            ));
        }

        // Only confirm that an account is deactivated once the password has
        // been proved: saying so earlier would let anyone probe the login form
        // to discover which usernames exist.
        if ($user && $passwordOk && (int) $user['is_active'] !== 1) {
            throw new ValidationException('This account has been deactivated. Ask an administrator to re-enable it.');
        }

        if (!$user || !$passwordOk) {
            if ($user) {
                self::recordFailure($user);
            }
            AuditLog::record('auth.failed', 'user', $user['id'] ?? null, [
                'username' => $username,
                'ip' => $request->ip(),
            ]);
            throw new ValidationException('The username or password is incorrect.');
        }

        // Rehash if PHP's default cost has moved on since the account was made.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password_hash' => self::hashPassword($password)], ['id' => (int) $user['id']]);
        }

        Database::update('users', [
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $request->ip(),
        ], ['id' => (int) $user['id']]);

        // A fresh session id on privilege change defeats session fixation.
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['locale'] = $user['locale'] ?: Config::get('default_locale', 'en');
        self::$user = null;

        AuditLog::record('auth.login', 'user', (int) $user['id'], ['ip' => $request->ip()]);

        return self::user() ?? [];
    }

    private static function isLockedOut(array $user): bool
    {
        $lockedUntil = $user['locked_until'] ?? null;

        return $lockedUntil !== null && strtotime((string) $lockedUntil) > time();
    }

    private static function recordFailure(array $user): void
    {
        $attempts = (int) ($user['failed_attempts'] ?? 0) + 1;
        $data = ['failed_attempts' => $attempts];
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
            $data['failed_attempts'] = 0;
        }
        Database::update('users', $data, ['id' => (int) $user['id']]);
    }

    public static function logout(): void
    {
        if (isset($_SESSION['user_id'])) {
            AuditLog::record('auth.logout', 'user', (int) $_SESSION['user_id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }

        $user = Database::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
        if (!$user) {
            // Account deleted or disabled mid-session.
            self::logout();

            return null;
        }
        self::$user = $user;

        return $user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user ? (int) $user['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function role(): string
    {
        return (string) (self::user()['role'] ?? '');
    }

    /** Does the signed-in user hold a permission such as `sales.create`? */
    public static function can(?string $permission): bool
    {
        if ($permission === null || $permission === '') {
            return true;
        }
        $user = self::user();
        if (!$user) {
            return false;
        }

        $grants = self::GRANTS[$user['role']] ?? [];
        foreach ($grants as $grant) {
            if ($grant === '*' || $grant === $permission) {
                return true;
            }
            // `sales.*` covers `sales.create`, `sales.view` and so on.
            if (str_ends_with($grant, '.*') && str_starts_with($permission, substr($grant, 0, -1))) {
                return true;
            }
        }

        // A module-wide `.view` grant implies the generic `reports.view` etc.
        return false;
    }

    public static function authorise(?string $permission): void
    {
        if (!self::can($permission)) {
            throw HttpException::forbidden();
        }
    }

    public static function isAdmin(): bool
    {
        return self::role() === self::ROLE_ADMIN;
    }

    /** @return string[] Permissions list for a role, for the users screen. */
    public static function grantsFor(string $role): array
    {
        return self::GRANTS[$role] ?? [];
    }
}
