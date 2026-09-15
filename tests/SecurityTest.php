<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Support\ValidationException;

/**
 * Regression tests for the security review.
 *
 * Each of these covers a specific finding, so that a later refactor cannot
 * quietly reintroduce it.
 */
final class SecurityTest extends TestCase
{
    /** Build a Request carrying a given Referer, as a browser would send it. */
    private function requestWithReferer(?string $referer, string $host = 'erp.example.qa'): Request
    {
        $server = ['HTTP_HOST' => $host, 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/index.php'];
        if ($referer !== null) {
            $server['HTTP_REFERER'] = $referer;
        }

        return new Request([], [], $server);
    }

    // ------------------------------------------------------------------
    // Open redirect
    // ------------------------------------------------------------------

    public function testBackUrlKeepsSameHostPaths(): void
    {
        $request = $this->requestWithReferer('https://erp.example.qa/invoices?status=draft');
        $this->assertSame('/invoices?status=draft', $request->backUrl(), 'path and query are kept');

        $this->assertSame(
            '/employees',
            $this->requestWithReferer('https://erp.example.qa/employees')->backUrl()
        );
    }

    public function testBackUrlHandlesAHostWithAPort(): void
    {
        // parse_url() reports the port separately while HTTP_HOST keeps it, so
        // a naive string comparison rejects every legitimate referer on any
        // non-standard port.
        $request = $this->requestWithReferer(
            'http://127.0.0.1:8899/employees?status=active',
            '127.0.0.1:8899'
        );
        $this->assertSame('/employees?status=active', $request->backUrl(), 'same host, explicit port');

        // A different host is still refused even when the port matches.
        $this->assertSame(
            '/',
            $this->requestWithReferer('http://evil.example.com:8899/x', '127.0.0.1:8899')->backUrl()
        );
    }

    public function testBackUrlRefusesAnotherHost(): void
    {
        // The whole point: a Location header must never be handed a URL that
        // someone else's site supplied.
        foreach ([
            'https://evil.example.com/login',
            'http://evil.example.com/',
            '//evil.example.com/login',
            'https://erp.example.qa.evil.com/login',
        ] as $hostile) {
            $this->assertSame(
                '/',
                $this->requestWithReferer($hostile)->backUrl(),
                "refuses {$hostile}"
            );
        }
    }

    public function testBackUrlFallsBackOnJunk(): void
    {
        $this->assertSame('/', $this->requestWithReferer(null)->backUrl());
        $this->assertSame('/', $this->requestWithReferer('')->backUrl());
        $this->assertSame('/', $this->requestWithReferer('javascript:alert(1)')->backUrl());
        $this->assertSame('/', $this->requestWithReferer('not a url at all')->backUrl());
        $this->assertSame('/dashboard', $this->requestWithReferer(null)->backUrl('/dashboard'));
    }

    // ------------------------------------------------------------------
    // Login
    // ------------------------------------------------------------------

    private function setUpUsers(): void
    {
        $this->freshDatabase();
        $now = date('Y-m-d H:i:s');

        Database::insert('users', [
            'username' => 'active.user', 'name' => 'Active User', 'email' => '',
            'password_hash' => Auth::hashPassword('CorrectHorse99'),
            'role' => Auth::ROLE_ACCOUNTANT, 'locale' => 'en',
            'is_active' => 1, 'created_at' => $now,
        ]);
        Database::insert('users', [
            'username' => 'retired.user', 'name' => 'Retired User', 'email' => '',
            'password_hash' => Auth::hashPassword('CorrectHorse99'),
            'role' => Auth::ROLE_ACCOUNTANT, 'locale' => 'en',
            'is_active' => 0, 'created_at' => $now,
        ]);
    }

    public function testADeactivatedAccountIsNotRevealedByAWrongPassword(): void
    {
        $this->setUpUsers();
        $request = $this->requestWithReferer(null);

        // Probing a deactivated account with a wrong password must look
        // exactly like probing an account that does not exist, or the login
        // form becomes a username oracle.
        $deactivated = '';
        try {
            Auth::attempt('retired.user', 'WrongPassword1', $request);
        } catch (ValidationException $e) {
            $deactivated = $e->getMessage();
        }

        $unknown = '';
        try {
            Auth::attempt('no.such.user', 'WrongPassword1', $request);
        } catch (ValidationException $e) {
            $unknown = $e->getMessage();
        }

        $this->assertSame($unknown, $deactivated, 'both must give the same answer');
        $this->assertTrue(str_contains($deactivated, 'incorrect'), 'and it is the generic one');
        $this->assertFalse(
            str_contains($deactivated, 'deactivated'),
            'the account status must not leak to someone without the password'
        );
    }

    public function testADeactivatedAccountIsExplainedToItsOwner(): void
    {
        $this->setUpUsers();
        // With the right password there is nothing left to disclose, so the
        // user gets a message they can act on instead of a dead end.
        $this->assertThrows(
            fn () => Auth::attempt('retired.user', 'CorrectHorse99', $this->requestWithReferer(null)),
            'deactivated'
        );
    }

    public function testADeactivatedAccountCannotSignIn(): void
    {
        $this->setUpUsers();
        $this->assertThrows(
            fn () => Auth::attempt('retired.user', 'CorrectHorse99', $this->requestWithReferer(null)),
            '',
            'a deactivated account must never authenticate'
        );
    }

    public function testRepeatedFailuresLockTheAccount(): void
    {
        $this->setUpUsers();
        $request = $this->requestWithReferer(null);

        for ($attempt = 1; $attempt <= Auth::MAX_FAILED_ATTEMPTS; $attempt++) {
            try {
                Auth::attempt('active.user', 'WrongPassword1', $request);
            } catch (ValidationException) {
                // expected
            }
        }

        // Even the correct password is refused once the account is locked.
        $this->assertThrows(
            fn () => Auth::attempt('active.user', 'CorrectHorse99', $request),
            'Too many failed attempts'
        );
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function testSalesCannotReachPayrollOrSettings(): void
    {
        // Salaries are the one thing an owner does not want on the sales desk.
        $grants = Auth::grantsFor(Auth::ROLE_SALES);
        $flat = implode(' ', $grants);

        $this->assertFalse(str_contains($flat, 'payroll'), 'sales has no payroll grant');
        $this->assertFalse(str_contains($flat, 'hr.'), 'sales has no HR grant');
        $this->assertFalse(in_array('*', $grants, true), 'sales is not a wildcard role');
    }

    public function testViewerIsReadOnly(): void
    {
        foreach (Auth::grantsFor(Auth::ROLE_VIEWER) as $grant) {
            $this->assertTrue(
                str_ends_with($grant, '.view'),
                "the viewer role grants only .view permissions, found: {$grant}"
            );
        }
    }

    public function testOnlyAdminHoldsTheWildcard(): void
    {
        foreach ([Auth::ROLE_ACCOUNTANT, Auth::ROLE_SALES, Auth::ROLE_HR, Auth::ROLE_VIEWER] as $role) {
            $this->assertFalse(
                in_array('*', Auth::grantsFor($role), true),
                "{$role} must not hold the wildcard grant"
            );
        }
        $this->assertTrue(in_array('*', Auth::grantsFor(Auth::ROLE_ADMIN), true));
    }

    public function testPasswordStrengthIsEnforced(): void
    {
        $this->assertThrows(static fn () => Auth::assertPasswordStrength('short1'), 'at least');
        $this->assertThrows(static fn () => Auth::assertPasswordStrength('alllettersonly'), 'letters and numbers');
        $this->assertThrows(static fn () => Auth::assertPasswordStrength('1234567890'), 'letters and numbers');

        // A reasonable password raises nothing.
        Auth::assertPasswordStrength('CorrectHorse99');
        $this->assertTrue(true, 'a strong password is accepted');
    }

    public function testPasswordsAreHashedNotStored(): void
    {
        $this->setUpUsers();
        $hash = (string) Database::value("SELECT password_hash FROM users WHERE username = 'active.user'");

        $this->assertFalse(str_contains($hash, 'CorrectHorse99'), 'the password is not in the column');
        $this->assertTrue(password_verify('CorrectHorse99', $hash), 'and it verifies');
        $this->assertTrue(strlen($hash) >= 50, 'a real hash, not something truncated');
    }

    // ------------------------------------------------------------------
    // Output encoding
    // ------------------------------------------------------------------

    public function testTheItemCatalogueCannotEscapeItsJsonBlock(): void
    {
        // The catalogue is rendered into a <script type="application/json">
        // block. A "</script>" in an item name must not be able to close it.
        $hostile = '</script><script>alert(1)</script>';
        $json = json_encode(
            ['1' => ['name' => $hostile]],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $this->assertFalse(
            str_contains($json, '</script>'),
            'the closing tag must be escaped — do not add JSON_UNESCAPED_SLASHES here'
        );
    }

    public function testCsvExportNeutralisesSpreadsheetFormulas(): void
    {
        // A contact named "=cmd|..." must not execute when the export is
        // opened in Excel.
        foreach (['=1+1', '+1', '-1', '@SUM(A1)'] as $payload) {
            $cell = \App\Support\Text::csvCell($payload);
            $this->assertTrue(
                str_starts_with($cell, "'"),
                "formula payload {$payload} is neutralised"
            );
        }

        $this->assertSame('Al Khaleej Trading', \App\Support\Text::csvCell('Al Khaleej Trading'));
    }

    public function testHtmlEscapingCoversQuotesAndTags(): void
    {
        $escaped = \App\Support\Text::escape('<script>"x" \'y\' & z</script>');

        foreach (['<script>', '"', "'"] as $raw) {
            $this->assertFalse(str_contains($escaped, $raw), "escapes {$raw}");
        }
        $this->assertTrue(str_contains($escaped, '&lt;script&gt;'));
    }
}
