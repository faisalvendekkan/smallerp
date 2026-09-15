<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLog;
use App\Core\Database;
use App\Support\Qatar;

/**
 * Company settings, stored as key/value rows and cached per request.
 *
 * These are the details that end up on a printed tax invoice and in the WPS
 * file: CR number, Establishment ID, the company's salary bank account.
 */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'company_name_en' => '',
        'company_name_ar' => '',
        'cr_number' => '',
        'establishment_id' => '',
        'tax_card_number' => '',
        'municipality_licence' => '',
        'chamber_membership' => '',
        'address' => '',
        'po_box' => '',
        'city' => 'Doha',
        'country' => 'Qatar',
        'phone' => '',
        'mobile' => '',
        'email' => '',
        'website' => '',
        // Tax. Zero until VAT commences in Qatar -- see Qatar::taxNotice().
        'tax_enabled' => '0',
        'default_tax_rate' => '0',
        'tax_label_en' => 'VAT',
        'tax_label_ar' => 'ضريبة القيمة المضافة',
        'prices_include_tax' => '0',
        // WPS / payroll
        'wps_bank_short_name' => '',
        'wps_iban' => '',
        'wps_payer_qid' => '',
        'wps_payer_eid' => '',
        'wps_sif_version' => '1',
        'payroll_working_days' => '30',
        'gratuity_weeks_tier1' => '3',
        'gratuity_weeks_tier2' => '4',
        'gratuity_weeks_tier3' => '5',
        // Documents
        'invoice_terms_en' => 'Payment due within 30 days of the invoice date.',
        'invoice_terms_ar' => 'تستحق قيمة الفاتورة خلال 30 يوماً من تاريخ الفاتورة.',
        'invoice_footer_en' => '',
        'invoice_footer_ar' => '',
        'bank_details' => '',
        'fiscal_year_start_month' => '1',
        'expiry_alert_days' => '60',
    ];

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::DEFAULTS;
        foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
            $values[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return self::$cache = $values;
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();
        $value = $all[$key] ?? $default;

        return $value === '' && $default !== '' ? $default : (string) $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === '' ? $default : (int) $value;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);

        return $value === '' ? $default : (float) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, ?string $value): void
    {
        $exists = Database::value('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$key], 0) > 0;
        $data = ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')];

        if ($exists) {
            Database::update('settings', $data, ['setting_key' => $key]);
        } else {
            Database::insert('settings', $data + ['setting_key' => $key]);
        }

        self::$cache = null;
    }

    /** @param array<string,string|null> $values */
    public static function setMany(array $values): void
    {
        Database::transaction(static function () use ($values): void {
            foreach ($values as $key => $value) {
                self::set($key, $value);
            }
        });
        AuditLog::record('settings.update', 'settings', null, ['keys' => array_keys($values)]);
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /** The tax rate applied to a new line when the item does not set one. */
    public static function defaultTaxRate(): float
    {
        return self::bool('tax_enabled') ? self::float('default_tax_rate', 0.0) : 0.0;
    }

    /** The company name in the active language, for headers and documents. */
    public static function companyName(string $lang = 'en'): string
    {
        $name = self::get('company_name_' . ($lang === 'ar' ? 'ar' : 'en'));
        if ($name === '') {
            $name = self::get('company_name_en') ?: self::get('company_name_ar');
        }

        return $name !== '' ? $name : 'SmallERP';
    }

    /** Gratuity tiers as the LabourLaw helper expects them. */
    public static function gratuityTiers(): array
    {
        return [
            0 => self::float('gratuity_weeks_tier1', 3.0),
            5 => self::float('gratuity_weeks_tier2', 4.0),
            10 => self::float('gratuity_weeks_tier3', 5.0),
        ];
    }

    /**
     * The employer block of a WPS SIF file.
     *
     * @return array<string,string>
     */
    public static function wpsEmployer(): array
    {
        $iban = self::get('wps_iban');

        return [
            'establishment_id' => self::get('establishment_id'),
            'payer_eid' => self::get('wps_payer_eid') ?: self::get('establishment_id'),
            'payer_qid' => self::get('wps_payer_qid'),
            'bank_short_name' => self::get('wps_bank_short_name') ?: Qatar::bankShortName($iban),
            'iban' => $iban,
        ];
    }

    /** True once the details a printed invoice needs have been filled in. */
    public static function isCompanyConfigured(): bool
    {
        return self::get('company_name_en') !== '' && self::get('cr_number') !== '';
    }

    /** True once payroll can actually produce a WPS file. */
    public static function isWpsConfigured(): bool
    {
        return self::get('establishment_id') !== ''
            && Qatar::isValidIban(self::get('wps_iban'));
    }
}
