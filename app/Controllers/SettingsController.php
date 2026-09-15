<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Numbering;
use App\Services\Settings;
use App\Support\Qatar;
use App\Support\ValidationException;
use App\Support\Wps;

/** Company settings, users, holidays and the audit trail. */
final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('settings/index', [
            'title' => __('nav.settings'),
            'settings' => Settings::all(),
            'banks' => Qatar::BANKS,
            'taxNotice' => Qatar::taxNotice(\App\Core\Lang::locale()),
            'gccRate' => Qatar::GCC_STANDARD_VAT_RATE,
            'numbering' => Numbering::DEFAULTS,
            'wpsConfigured' => Settings::isWpsConfigured(),
            'sifVersion' => Wps::DEFAULT_SIF_VERSION,
        ]);
    }

    public function update(Request $request): Response
    {
        $values = [];

        // Only keys we know about are written, so a tampered form cannot
        // inject arbitrary settings.
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            if ($request->has($key)) {
                $values[$key] = $request->string($key);
            }
        }

        // Checkboxes post nothing when unticked.
        foreach (['tax_enabled', 'prices_include_tax'] as $flag) {
            $values[$flag] = $request->bool($flag) ? '1' : '0';
        }

        // Numbering prefixes live outside the DEFAULTS list.
        foreach (array_keys(Numbering::DEFAULTS) as $docType) {
            $key = 'numbering_' . $docType . '_prefix';
            if ($request->has($key)) {
                $values[$key] = strtoupper(trim($request->string($key)));
            }
        }

        // Validate the Qatari identifiers before they reach a printed invoice
        // or a WPS file, where a mistake is expensive.
        if (($values['cr_number'] ?? '') !== '') {
            $values['cr_number'] = Qatar::validateCrNumber($values['cr_number']);
        }
        if (($values['establishment_id'] ?? '') !== '') {
            $values['establishment_id'] = Qatar::validateEstablishmentId($values['establishment_id']);
        }
        if (($values['wps_iban'] ?? '') !== '') {
            $values['wps_iban'] = Qatar::validateIban($values['wps_iban']);
            if (($values['wps_bank_short_name'] ?? '') === '') {
                $values['wps_bank_short_name'] = Qatar::bankShortName($values['wps_iban']);
            }
        }
        if (($values['wps_payer_qid'] ?? '') !== '') {
            $values['wps_payer_qid'] = Qatar::validateQid($values['wps_payer_qid']);
        }
        foreach (['phone', 'mobile'] as $field) {
            if (($values[$field] ?? '') !== '' && str_starts_with($values[$field], '+974')) {
                $values[$field] = Qatar::validatePhone($values[$field]);
            }
        }
        if (($values['email'] ?? '') !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('That email address is not valid', 'email');
        }

        $rate = (float) ($values['default_tax_rate'] ?? 0);
        if ($rate < 0 || $rate > 100) {
            throw new ValidationException('Tax rate must be between 0 and 100 percent', 'default_tax_rate');
        }

        Settings::setMany($values);

        return $this->redirect('/settings', 'Settings saved.');
    }

    // --------------------------------------------------------------- Users

    public function users(Request $request): Response
    {
        return $this->view('settings/users', [
            'title' => __('nav.users'),
            'users' => Database::all('SELECT * FROM users ORDER BY name'),
            'roles' => Auth::ROLES,
        ]);
    }

    public function createUser(Request $request): Response
    {
        return $this->view('settings/user_form', [
            'title' => __('action.new') . ' user',
            'user' => null,
            'roles' => Auth::ROLES,
            'grants' => [],
        ]);
    }

    public function editUser(Request $request): Response
    {
        $user = $this->findOr404(
            Database::first('SELECT * FROM users WHERE id = ?', [$request->routeInt('id')]),
            'user'
        );

        return $this->view('settings/user_form', [
            'title' => __('action.edit') . ' — ' . $user['name'],
            'user' => $user,
            'roles' => Auth::ROLES,
            'grants' => Auth::grantsFor((string) $user['role']),
        ]);
    }

    public function storeUser(Request $request): Response
    {
        $username = strtolower($request->required('username', 'Username'));
        $password = (string) $request->input('password', '');

        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            throw new ValidationException(
                'The username may only contain letters, numbers, dots, dashes and underscores',
                'username'
            );
        }
        if (Database::first('SELECT id FROM users WHERE username = ?', [$username])) {
            throw new ValidationException('That username is already taken', 'username');
        }
        Auth::assertPasswordStrength($password);

        $id = Database::insert('users', [
            'username' => $username,
            'email' => $request->string('email'),
            'name' => $request->required('name', 'Full name'),
            'password_hash' => Auth::hashPassword($password),
            'role' => $this->role($request),
            'locale' => $request->string('locale', 'en') === 'ar' ? 'ar' : 'en',
            'is_active' => $request->bool('is_active', true) ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        AuditLog::record('user.create', 'user', $id, ['username' => $username]);

        return $this->redirect('/users', 'User created.');
    }

    public function updateUser(Request $request): Response
    {
        $id = $request->routeInt('id');
        $user = $this->findOr404(Database::first('SELECT * FROM users WHERE id = ?', [$id]), 'user');

        $role = $this->role($request);
        $isActive = $request->bool('is_active', true);

        // Locking yourself out, or removing the last administrator, would need
        // database access to undo.
        if ((int) $user['id'] === Auth::id()) {
            if (!$isActive) {
                throw new ValidationException('You cannot deactivate your own account');
            }
            if ($role !== Auth::ROLE_ADMIN && $user['role'] === Auth::ROLE_ADMIN) {
                throw new ValidationException('You cannot remove your own administrator role');
            }
        }
        if ($user['role'] === Auth::ROLE_ADMIN && ($role !== Auth::ROLE_ADMIN || !$isActive)) {
            $otherAdmins = (int) Database::value(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?",
                [$id],
                0
            );
            if ($otherAdmins === 0) {
                throw new ValidationException('This is the last active administrator — appoint another one first');
            }
        }

        $data = [
            'name' => $request->required('name', 'Full name'),
            'email' => $request->string('email'),
            'role' => $role,
            'locale' => $request->string('locale', 'en') === 'ar' ? 'ar' : 'en',
            'is_active' => $isActive ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $password = (string) $request->input('password', '');
        if ($password !== '') {
            Auth::assertPasswordStrength($password);
            $data['password_hash'] = Auth::hashPassword($password);
            // A password reset also clears any lockout.
            $data['failed_attempts'] = 0;
            $data['locked_until'] = null;
        }

        Database::update('users', $data, ['id' => $id]);
        AuditLog::record('user.update', 'user', $id, ['role' => $role]);

        return $this->redirect('/users', 'User updated.');
    }

    private function role(Request $request): string
    {
        $role = $request->string('role', Auth::ROLE_VIEWER);
        if (!isset(Auth::ROLES[$role])) {
            throw new ValidationException('Choose a valid role', 'role');
        }

        return $role;
    }

    // --------------------------------------------------------------- Audit

    public function audit(Request $request): Response
    {
        return $this->view('settings/audit', [
            'title' => __('nav.audit'),
            'rows' => AuditLog::recent(200, [
                'entity_type' => $request->string('entity_type'),
                'user_id' => $request->int('user_id'),
            ]),
            'entityType' => $request->string('entity_type'),
            'userId' => $request->int('user_id'),
            'users' => Database::all('SELECT id, name FROM users ORDER BY name'),
            'entityTypes' => array_column(
                Database::all("SELECT DISTINCT entity_type FROM audit_log WHERE entity_type <> '' ORDER BY entity_type"),
                'entity_type'
            ),
        ]);
    }

    // ------------------------------------------------------------ Holidays

    public function holidays(Request $request): Response
    {
        $year = $request->int('year', (int) date('Y'));

        return $this->view('settings/holidays', [
            'title' => 'Public holidays',
            'year' => $year,
            'rows' => Database::all(
                'SELECT * FROM holidays WHERE holiday_date LIKE ? ORDER BY holiday_date',
                [$year . '-%']
            ),
            // Eid follows the Hijri calendar and is announced by the Amiri
            // Diwan each year, so it is entered rather than computed.
            'suggested' => Qatar::fixedPublicHolidays($year),
        ]);
    }

    public function storeHoliday(Request $request): Response
    {
        $date = $request->date('holiday_date');
        $nameEn = $request->required('name_en', 'Holiday name');

        if ($date === null) {
            throw new ValidationException('Choose the date of the holiday', 'holiday_date');
        }

        $exists = Database::first(
            'SELECT id FROM holidays WHERE holiday_date = ? AND name_en = ?',
            [$date, $nameEn]
        );
        if ($exists) {
            throw new ValidationException('That holiday is already in the calendar');
        }

        Database::insert('holidays', [
            'holiday_date' => $date,
            'name_en' => mb_substr($nameEn, 0, 120),
            'name_ar' => mb_substr($request->string('name_ar'), 0, 120),
            'is_paid' => $request->bool('is_paid', true) ? 1 : 0,
        ]);

        return $this->redirect('/holidays?year=' . substr($date, 0, 4), 'Holiday added.');
    }

    public function deleteHoliday(Request $request): Response
    {
        $id = $request->routeInt('id');
        $holiday = Database::first('SELECT * FROM holidays WHERE id = ?', [$id]);
        Database::delete('holidays', ['id' => $id]);

        return $this->redirect(
            '/holidays?year=' . substr((string) ($holiday['holiday_date'] ?? date('Y')), 0, 4),
            'Holiday removed.'
        );
    }
}
