<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Qatar-specific rules: identifiers, banks, tax and labour law.
 *
 * Every rule anchored in Qatari law lives here with its source named, so when
 * the law changes there is exactly one file to edit.
 *
 * Legal references:
 *  - Labour Law No. 14 of 2004 (as amended, notably by Law No. 17 of 2020):
 *    Art. 49 notice, Art. 54 end-of-service gratuity, Art. 66 wage payment,
 *    Art. 73 weekly rest, Art. 79 annual leave.
 *  - Law No. 24 of 2018 (Income Tax): 10% corporate rate, 5% withholding.
 *  - Law No. 25 of 2018 (Excise Tax).
 *  - Wage Protection System, Ministry of Labour / Qatar Central Bank.
 */
final class Qatar
{
    public const COUNTRY_CODE = 'QA';
    public const DIAL_CODE = '+974';
    public const TIMEZONE = 'Asia/Qatar'; // UTC+3, no daylight saving.

    /**
     * The Qatari working week runs Sunday to Thursday; Friday is the weekly
     * rest day guaranteed by Art. 73. PHP's date('w'): 0 = Sunday.
     */
    public const WORKING_WEEKDAYS = [0, 1, 2, 3, 4];
    public const WEEKEND_WEEKDAYS = [5, 6];

    // ------------------------------------------------------------------
    // Tax
    // ------------------------------------------------------------------

    /**
     * Qatar has no VAT in force. The GCC VAT Framework Agreement commits
     * member states to a 5% standard rate and Qatar's domestic VAT law has
     * been drafted but not commenced. SmallERP therefore defaults every tax
     * rate to zero while keeping the machinery in place, so that when VAT
     * arrives an SME switches it on in Settings rather than changing software.
     */
    public const VAT_IN_FORCE = false;
    public const GCC_STANDARD_VAT_RATE = 5.0;
    public const DEFAULT_TAX_RATE = 0.0;

    /** Corporate income tax; generally applies to the foreign-owned share of profit. */
    public const CORPORATE_INCOME_TAX_RATE = 10.0;

    /** Withholding tax on in-scope payments to non-residents. */
    public const WITHHOLDING_TAX_RATE = 5.0;

    /** Excise tax rates, for SMEs trading these goods. */
    public const EXCISE_RATES = [
        'tobacco' => 100.0,
        'energy_drinks' => 100.0,
        'special_purpose_goods' => 100.0,
        'carbonated_drinks' => 50.0,
    ];

    public static function taxNotice(string $lang = 'en'): string
    {
        return $lang === 'ar'
            ? 'لا تُطبَّق ضريبة القيمة المضافة في قطر حتى الآن، لذا تكون النسبة صفر افتراضياً. يمكن تفعيل النسبة القياسية 5% عند صدور القانون.'
            : 'VAT is not yet in force in Qatar, so the default rate is 0%. The 5% GCC standard rate can be switched on in Settings when it commences.';
    }

    // ------------------------------------------------------------------
    // Identifiers
    // ------------------------------------------------------------------

    /**
     * Validate a Qatar ID (QID) and return it normalised to 11 digits.
     *
     * The QID encodes `C YY NNN SSSSS`: century of birth (2 = 1900s,
     * 3 = 2000s), the last two digits of the birth year, then a three-digit
     * nationality code and a serial.
     *
     * Qatar publishes no check-digit algorithm for the QID, so this validates
     * structure only: it catches typos of the wrong length or an impossible
     * birth year, but cannot prove the number was ever issued.
     */
    public static function validateQid(?string $value): string
    {
        $qid = Text::digits($value);
        if ($qid === '') {
            throw new ValidationException('QID is required', 'qid');
        }
        if (strlen($qid) !== 11) {
            throw new ValidationException('QID must be exactly 11 digits', 'qid');
        }
        if (!in_array($qid[0], ['2', '3'], true)) {
            throw new ValidationException('QID must start with 2 (born 1900s) or 3 (born 2000s)', 'qid');
        }
        if (self::qidBirthYear($qid) > (int) date('Y')) {
            throw new ValidationException('QID encodes a birth year in the future', 'qid');
        }

        return $qid;
    }

    public static function isValidQid(?string $value): bool
    {
        try {
            self::validateQid($value);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** Birth year encoded in the first three digits of a QID. */
    public static function qidBirthYear(string $value): int
    {
        $qid = Text::digits($value);
        if (strlen($qid) !== 11) {
            throw new ValidationException('QID must be exactly 11 digits', 'qid');
        }

        return ($qid[0] === '2' ? 1900 : 2000) + (int) substr($qid, 1, 2);
    }

    /** The three-digit nationality code embedded in a QID. */
    public static function qidNationalityCode(string $value): string
    {
        return substr(Text::digits($value), 3, 3);
    }

    /**
     * Validate a Commercial Registration (CR) number issued by the MoCI.
     * Current numbers run to eight digits; older registrations are shorter.
     */
    public static function validateCrNumber(?string $value): string
    {
        $cr = Text::digits($value);
        if (strlen($cr) < 4 || strlen($cr) > 12) {
            throw new ValidationException('CR number must be between 4 and 12 digits', 'cr_number');
        }

        return $cr;
    }

    /** Validate a Ministry of Labour Establishment ID (the WPS Employer EID). */
    public static function validateEstablishmentId(?string $value): string
    {
        $eid = Text::digits($value);
        if (strlen($eid) < 4 || strlen($eid) > 12) {
            throw new ValidationException('Establishment ID must be between 4 and 12 digits', 'establishment_id');
        }

        return $eid;
    }

    /**
     * Validate a Qatari IBAN and return it upper-cased without separators.
     *
     * A Qatar IBAN is 29 characters: `QA` + 2 check digits + a 4-letter bank
     * code + 21 alphanumeric characters. The ISO 13616 mod-97 check runs here
     * so a mistyped salary account is caught at data entry rather than by the
     * bank three days after payroll was submitted.
     */
    public static function validateIban(?string $value): string
    {
        $iban = strtoupper(preg_replace('/[\s\-]+/', '', (string) $value) ?? '');
        if ($iban === '') {
            throw new ValidationException('IBAN is required', 'iban');
        }
        if (!str_starts_with($iban, 'QA')) {
            throw new ValidationException('IBAN must start with the country code QA', 'iban');
        }
        if (strlen($iban) !== 29) {
            throw new ValidationException('A Qatari IBAN must be 29 characters long', 'iban');
        }
        if (!preg_match('/^QA\d{2}[A-Z]{4}[A-Z0-9]{21}$/', $iban)) {
            throw new ValidationException(
                'IBAN format is QA + 2 digits + 4-letter bank code + 21 alphanumeric characters',
                'iban'
            );
        }
        if (self::ibanChecksum($iban) !== 1) {
            throw new ValidationException('IBAN check digits are wrong — please re-enter the account number', 'iban');
        }

        return $iban;
    }

    public static function isValidIban(?string $value): bool
    {
        try {
            self::validateIban($value);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * ISO 13616 mod-97 remainder; a valid IBAN gives 1.
     *
     * The rearranged IBAN is far larger than PHP's integer range, so the
     * remainder is taken in chunks rather than converting the whole string.
     */
    public static function ibanChecksum(string $iban): int
    {
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord(strtoupper($char)) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (((string) $remainder . $chunk) % 97);
        }

        return $remainder;
    }

    public static function ibanBankCode(?string $iban): string
    {
        return substr(strtoupper(preg_replace('/[\s\-]+/', '', (string) $iban) ?? ''), 4, 4);
    }

    /** Group an IBAN in fours for display. */
    public static function formatIban(?string $iban): string
    {
        $clean = strtoupper(preg_replace('/[\s\-]+/', '', (string) $iban) ?? '');

        return trim(chunk_split($clean, 4, ' '));
    }

    /**
     * Validate a Qatari phone number, returning it as +974XXXXXXXX.
     * Mobiles begin 3, 5, 6 or 7; fixed lines begin 4. Both are 8 digits.
     */
    public static function validatePhone(?string $value): string
    {
        $digits = Text::digits($value);
        if (str_starts_with($digits, '00974')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '974') && strlen($digits) === 11) {
            $digits = substr($digits, 3);
        }
        if (strlen($digits) !== 8) {
            throw new ValidationException('A Qatari phone number has 8 digits', 'phone');
        }
        if (!str_contains('34567', $digits[0])) {
            throw new ValidationException('A Qatari number starts with 3, 4, 5, 6 or 7', 'phone');
        }

        return self::DIAL_CODE . $digits;
    }

    public static function isMobile(?string $value): bool
    {
        try {
            return str_contains('3567', self::validatePhone($value)[4]);
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * Short names Qatari banks use in WPS SIF files, keyed by IBAN bank code.
     * Confirm the short name with your own bank before the first submission.
     */
    public const BANKS = [
        'QNBA' => ['short' => 'QNB', 'name_en' => 'Qatar National Bank', 'name_ar' => 'بنك قطر الوطني'],
        'CBQA' => ['short' => 'CBQ', 'name_en' => 'Commercial Bank of Qatar', 'name_ar' => 'البنك التجاري'],
        'DOHB' => ['short' => 'DB', 'name_en' => 'Doha Bank', 'name_ar' => 'بنك الدوحة'],
        'QIBK' => ['short' => 'QIB', 'name_en' => 'Qatar Islamic Bank', 'name_ar' => 'مصرف قطر الإسلامي'],
        'QISB' => ['short' => 'QIIB', 'name_en' => 'Qatar International Islamic Bank', 'name_ar' => 'الدولي الإسلامي'],
        'MAFR' => ['short' => 'MAR', 'name_en' => 'Masraf Al Rayan', 'name_ar' => 'مصرف الريان'],
        'ABQQ' => ['short' => 'ABQ', 'name_en' => 'Ahli Bank', 'name_ar' => 'البنك الأهلي'],
        'IDBQ' => ['short' => 'IBQ', 'name_en' => 'International Bank of Qatar', 'name_ar' => 'بنك قطر الدولي'],
        'BBME' => ['short' => 'HSBC', 'name_en' => 'HSBC Bank Middle East', 'name_ar' => 'بنك إتش إس بي سي'],
        'MSHQ' => ['short' => 'MASHREQ', 'name_en' => 'Mashreq Bank', 'name_ar' => 'بنك المشرق'],
        'SCBL' => ['short' => 'SCB', 'name_en' => 'Standard Chartered', 'name_ar' => 'ستاندرد تشارترد'],
        'ARAB' => ['short' => 'ARAB', 'name_en' => 'Arab Bank', 'name_ar' => 'البنك العربي'],
        'UBLQ' => ['short' => 'UBL', 'name_en' => 'United Bank Limited', 'name_ar' => 'يونايتد بنك'],
        'BNPA' => ['short' => 'BNP', 'name_en' => 'BNP Paribas', 'name_ar' => 'بي إن بي باريبا'],
        'NBOK' => ['short' => 'NBK', 'name_en' => 'National Bank of Kuwait', 'name_ar' => 'بنك الكويت الوطني'],
        'BKDQ' => ['short' => 'BKDQ', 'name_en' => 'Bank Audi', 'name_ar' => 'بنك عودة'],
    ];

    /** Map an IBAN to the bank short name a WPS file expects. */
    public static function bankShortName(?string $iban, string $fallback = ''): string
    {
        $code = self::ibanBankCode($iban);

        return self::BANKS[$code]['short'] ?? ($fallback !== '' ? $fallback : $code);
    }

    public static function bankName(?string $iban, string $lang = 'en'): string
    {
        $code = self::ibanBankCode($iban);

        return self::BANKS[$code]['name_' . ($lang === 'ar' ? 'ar' : 'en')] ?? $code;
    }

    // ------------------------------------------------------------------
    // Calendar
    // ------------------------------------------------------------------

    /** Qatar's National Sports Day: the second Tuesday of February. */
    public static function nationalSportsDay(int $year): \DateTimeImmutable
    {
        $day = new \DateTimeImmutable(sprintf('%d-02-01', $year));
        while ((int) $day->format('N') !== 2) { // 2 = Tuesday
            $day = $day->modify('+1 day');
        }

        return $day->modify('+7 days');
    }

    /**
     * Public holidays with a fixed Gregorian date.
     *
     * Eid al-Fitr and Eid al-Adha follow the Hijri calendar and are announced
     * by the Amiri Diwan each year, so they are entered by the user under
     * Settings rather than computed here.
     *
     * @return array<int,array{date:string,name_en:string,name_ar:string}>
     */
    public static function fixedPublicHolidays(int $year): array
    {
        return [
            [
                'date' => self::nationalSportsDay($year)->format('Y-m-d'),
                'name_en' => 'National Sports Day',
                'name_ar' => 'اليوم الرياضي للدولة',
            ],
            [
                'date' => sprintf('%d-12-18', $year),
                'name_en' => 'Qatar National Day',
                'name_ar' => 'اليوم الوطني لدولة قطر',
            ],
        ];
    }

    /**
     * Count Sunday-Thursday working days in a month, excluding given holidays.
     *
     * @param string[] $holidays Y-m-d dates
     */
    public static function workingDaysInMonth(int $year, int $month, array $holidays = []): int
    {
        $holidays = array_flip($holidays);
        $days = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $count = 0;
        for ($d = 1; $d <= $days; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $weekday = (int) date('w', strtotime($date));
            if (in_array($weekday, self::WORKING_WEEKDAYS, true) && !isset($holidays[$date])) {
                $count++;
            }
        }

        return $count;
    }
}
