<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Tests;

use Kkxdev\Truecaller\Exceptions\PhoneUnsupportedException;
use Kkxdev\Truecaller\Exceptions\ProfileIncompleteException;
use Kkxdev\Truecaller\Support\PhoneNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The normaliser decides whether a returning user is recognised or silently
 * given a second account, so these are behaviour locks rather than coverage.
 *
 * Every case runs against the ISO map and rules as shipped.
 */
class PhoneNormaliserTest extends TestCase
{
    private PhoneNormaliser $normaliser;

    protected function setUp(): void
    {
        parent::setUp();

        [$isoMap, $rules] = $this->shippedPhoneConfig();

        $this->normaliser = new PhoneNormaliser($isoMap, $rules);
    }

    // ---- the ordinary Indian shapes

    public function testIndianNumberWithCountryCodePrefixSplitsIntoDialCodeAndNationalNumber(): void
    {
        $phone = $this->normaliser->normalise('+919876543210', 'IN');

        $this->assertSame('91', $phone->dialCode());
        $this->assertSame('9876543210', $phone->nationalNumber());
    }

    public function testIndianNumberWithoutPlusIsTreatedIdentically(): void
    {
        $phone = $this->normaliser->normalise('919876543210', 'IN');

        $this->assertSame('+919876543210', $phone->e164());
    }

    public function testIntegerPhoneNumberFromUserinfoIsAccepted(): void
    {
        // JSON numbers arrive as ints; a careless cast truncates them.
        $phone = $this->normaliser->normalise(919876543210, 'IN');

        $this->assertSame('+919876543210', $phone->e164());
    }

    public function testSpacesAndDashesAreStrippedBeforeSplitting(): void
    {
        $phone = $this->normaliser->normalise('+91 98765-43210', 'IN');

        $this->assertSame('+919876543210', $phone->e164());
    }

    public function testBareTenDigitIndianNumberUsesIsoCountryForTheDialCode(): void
    {
        $phone = $this->normaliser->normalise('9876543210', 'IN');

        $this->assertSame('91', $phone->dialCode());
        $this->assertSame('9876543210', $phone->nationalNumber());
    }

    // ---- the malformations that create duplicate accounts

    public function testDoubledCountryCodeIsCollapsed(): void
    {
        // "+91 91 9876543210" looks entirely valid and matches nothing, which
        // makes it the likeliest single source of duplicate accounts.
        $phone = $this->normaliser->normalise('91919876543210', 'IN');

        $this->assertSame('+919876543210', $phone->e164());
    }

    public function testLeadingZeroAfterIndianDialCodeIsStripped(): void
    {
        $phone = $this->normaliser->normalise('+9109876543210', 'IN');

        $this->assertSame('+919876543210', $phone->e164());
    }

    public function testIndianMobileBeginningWithItsOwnDialCodeIsNotMangled(): void
    {
        // 9123456789 is a real 10-digit mobile that starts with "91". Stripping
        // the dial code here would reject a legitimate user, so the untouched
        // reading has to win.
        $phone = $this->normaliser->normalise('9123456789', 'IN');

        $this->assertSame('91', $phone->dialCode());
        $this->assertSame('9123456789', $phone->nationalNumber());
    }

    // ---- other countries

    public function testUsAndCanadaShareDialCodeOneAndBothResolve(): void
    {
        foreach (['US', 'CA'] as $iso) {
            $phone = $this->normaliser->normalise('+14155550123', $iso);

            $this->assertSame('1', $phone->dialCode(), $iso);
            $this->assertSame('4155550123', $phone->nationalNumber(), $iso);
        }
    }

    public function testUaeNumberResolvesFromIsoCountry(): void
    {
        $phone = $this->normaliser->normalise('+971501234567', 'AE');

        $this->assertSame('971', $phone->dialCode());
        $this->assertSame('501234567', $phone->nationalNumber());
    }

    public function testMissingIsoCountryFallsBackToLongestDialCodePrefixMatch(): void
    {
        $this->assertSame('+919876543210', $this->normaliser->normalise('+919876543210', null)->e164());
        $this->assertSame('+14155550123', $this->normaliser->normalise('+14155550123', null)->e164());
    }

    // ---- rejection rather than guesswork

    public function testUnmappedIsoCountryIsRejectedRatherThanGuessed(): void
    {
        // Guessing a dial code writes a number the real owner can never match.
        // Rejecting sends them to OTP, which loses nothing.
        $this->expectException(PhoneUnsupportedException::class);

        $this->normaliser->normalise('+6811234567', 'NU');
    }

    public function testIndianNumberFailingTheMobileSeriesRuleIsRejected(): void
    {
        $this->expectException(PhoneUnsupportedException::class);

        $this->normaliser->normalise('+911234567890', 'IN');
    }

    public function testTooShortNationalNumberIsRejected(): void
    {
        $this->expectException(PhoneUnsupportedException::class);

        $this->normaliser->normalise('+9112345', 'IN');
    }

    /**
     * @dataProvider missingNumbers
     *
     * @param string|int|null $raw
     */
    #[DataProvider('missingNumbers')]
    public function testEmptyOrNonNumericPhoneNumberIsRejectedAsMissing($raw): void
    {
        // Distinct from "unsupported": the caller reports these differently,
        // because the user has nothing to correct.
        $this->expectException(ProfileIncompleteException::class);

        $this->normaliser->normalise($raw, 'IN');
    }

    /**
     * @return array<string, array{0: string|int|null}>
     */
    public static function missingNumbers(): array
    {
        return [
            'empty string' => [''],
            'null'         => [null],
            'placeholder'  => ['unknown'],
        ];
    }

    // ---- invariants the rest of the system depends on

    public function testNormalisedE164IsTheDialCodeAndNationalNumberConcatenated(): void
    {
        // Callers build their lookup key as "+" . country_code . mobile, so
        // this equality is what makes a Truecaller login find an OTP account.
        $phone = $this->normaliser->normalise('+919876543210', 'IN');

        $this->assertSame('+' . $phone->dialCode() . $phone->nationalNumber(), $phone->e164());
        $this->assertSame('+' . '91' . '9876543210', $phone->e164());
    }

    public function testDialCodeIsBareAndNotPlusPrefixed(): void
    {
        // Storing "+91" where the rest of a system stores "91" is a real bug
        // that has shipped before; this pins the convention.
        $this->assertSame('91', $this->normaliser->normalise('+919876543210', 'IN')->dialCode());
    }

    public function testMaskedNumberKeepsOnlyTheLastFourDigits(): void
    {
        $this->assertSame('+91******3210', $this->normaliser->normalise('+919876543210', 'IN')->masked());
    }
}
