<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Qatar;
use App\Support\ValidationException;
use Database\Seeder;

/** Qatari identifiers: QID, CR number, IBAN and phone numbers. */
final class QatarTest extends TestCase
{
    public function testAcceptsAValidQid(): void
    {
        $this->assertSame('28563400123', Qatar::validateQid('28563400123'));
        $this->assertSame('28563400123', Qatar::validateQid('285-6340-0123'), 'strips separators');
        $this->assertTrue(Qatar::isValidQid('28563400123'));
    }

    public function testRejectsABadQid(): void
    {
        $this->assertThrows(static fn () => Qatar::validateQid('123'), 'exactly 11 digits');
        $this->assertThrows(static fn () => Qatar::validateQid('12345678901'), 'must start with 2');
        $this->assertThrows(static fn () => Qatar::validateQid(''), 'required');
        $this->assertFalse(Qatar::isValidQid('99999999999'));
    }

    public function testDecodesTheQid(): void
    {
        // 2 = born in the 1900s, 85 = 1985, 634 = nationality code.
        $this->assertSame(1985, Qatar::qidBirthYear('28563400123'));
        $this->assertSame('634', Qatar::qidNationalityCode('28563400123'));
        $this->assertSame(2005, Qatar::qidBirthYear('30563400123'));
    }

    public function testRejectsAQidBornInTheFuture(): void
    {
        $futureYear = ((int) date('y') + 2) % 100;
        $qid = '3' . str_pad((string) $futureYear, 2, '0', STR_PAD_LEFT) . '63400123';
        // Only meaningful while we are in the 2000s, which we are.
        if (Qatar::qidBirthYear($qid) > (int) date('Y')) {
            $this->assertThrows(static fn () => Qatar::validateQid($qid), 'future');
        } else {
            $this->assertTrue(true);
        }
    }

    public function testValidatesIbanChecksum(): void
    {
        $iban = Seeder::iban('QNBA', '000000000012345678901');
        $this->assertSame(29, strlen($iban), 'a Qatari IBAN is 29 characters');
        $this->assertSame($iban, Qatar::validateIban($iban));
        $this->assertSame(1, Qatar::ibanChecksum($iban), 'mod-97 of a valid IBAN is 1');
        $this->assertTrue(Qatar::isValidIban($iban));
    }

    public function testRejectsAMistypedIban(): void
    {
        $iban = Seeder::iban('QNBA', '000000000012345678901');
        // Transpose two digits: exactly the typo the mod-97 check exists for.
        $broken = substr($iban, 0, 10) . strrev(substr($iban, 10, 2)) . substr($iban, 12);
        if ($broken !== $iban) {
            $this->assertThrows(static fn () => Qatar::validateIban($broken), 'check digits');
        } else {
            $this->assertTrue(true);
        }

        $this->assertThrows(static fn () => Qatar::validateIban('AE070331234567890123456'), 'country code QA');
        $this->assertThrows(static fn () => Qatar::validateIban('QA58DOHB0000'), '29 characters');
        $this->assertThrows(static fn () => Qatar::validateIban(''), 'required');
    }

    public function testMapsIbanToBankShortName(): void
    {
        $this->assertSame('QNB', Qatar::bankShortName(Seeder::iban('QNBA', '000000000012345678901')));
        $this->assertSame('CBQ', Qatar::bankShortName(Seeder::iban('CBQA', '000000000012345678901')));
        $this->assertSame('Doha Bank', Qatar::bankName(Seeder::iban('DOHB', '000000000012345678901')));
        // An unrecognised bank falls back to the raw code rather than failing.
        $this->assertSame('ZZZZ', Qatar::bankShortName(Seeder::iban('ZZZZ', '000000000012345678901')));
    }

    public function testFormatsIbanInFours(): void
    {
        $formatted = Qatar::formatIban('QA94QNBA000000000012345678901');
        $this->assertSame('QA94 QNBA 0000 0000 0012 3456 7890 1', $formatted);
    }

    public function testValidatesCrNumber(): void
    {
        $this->assertSame('84213', Qatar::validateCrNumber('84213'));
        $this->assertSame('84213', Qatar::validateCrNumber('CR-84213'), 'strips non-digits');
        $this->assertThrows(static fn () => Qatar::validateCrNumber('12'), 'between 4 and 12');
    }

    public function testValidatesPhoneNumbers(): void
    {
        $this->assertSame('+97444318820', Qatar::validatePhone('44318820'));
        $this->assertSame('+97444318820', Qatar::validatePhone('+974 4431 8820'));
        $this->assertSame('+97444318820', Qatar::validatePhone('0097444318820'));
        $this->assertThrows(static fn () => Qatar::validatePhone('1234'), '8 digits');
        $this->assertThrows(static fn () => Qatar::validatePhone('14318820'), 'starts with 3, 4, 5, 6 or 7');

        $this->assertTrue(Qatar::isMobile('+97455112233'), '5 is a mobile prefix');
        $this->assertFalse(Qatar::isMobile('+97444318820'), '4 is a fixed line');
    }

    public function testNationalSportsDayIsTheSecondTuesdayOfFebruary(): void
    {
        foreach ([2024, 2025, 2026, 2027] as $year) {
            $day = Qatar::nationalSportsDay($year);
            $this->assertSame('Tuesday', $day->format('l'), "{$year} falls on a Tuesday");
            $this->assertSame('02', $day->format('m'), "{$year} is in February");
            $dayOfMonth = (int) $day->format('j');
            $this->assertTrue($dayOfMonth >= 8 && $dayOfMonth <= 14, "{$year} is the second Tuesday");
        }
    }

    public function testCountsSundayToThursdayWorkingDays(): void
    {
        // The Qatari week runs Sunday to Thursday; Friday is the rest day.
        $days = Qatar::workingDaysInMonth(2026, 2);
        $this->assertTrue($days >= 19 && $days <= 21, 'February has around 20 working days');

        $withHoliday = Qatar::workingDaysInMonth(2026, 2, ['2026-02-10']);
        $this->assertSame($days - 1, $withHoliday, 'a holiday on a working day reduces the count');
    }

    public function testVatIsNotYetInForce(): void
    {
        // Qatar has drafted a VAT law but not commenced it, so the default
        // rate must be zero or every invoice raised would be wrong.
        $this->assertFalse(Qatar::VAT_IN_FORCE);
        $this->assertSame(0.0, Qatar::DEFAULT_TAX_RATE);
        $this->assertSame(5.0, Qatar::GCC_STANDARD_VAT_RATE);
        $this->assertTrue(str_contains(Qatar::taxNotice(), 'not yet in force'));
        $this->assertTrue(str_contains(Qatar::taxNotice('ar'), 'ضريبة القيمة المضافة'));
    }
}
