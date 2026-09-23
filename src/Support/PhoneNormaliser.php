<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Support;

use Kkxdev\Truecaller\Contracts\PhoneNormaliserInterface;
use Kkxdev\Truecaller\DTO\NormalisedPhone;
use Kkxdev\Truecaller\Exceptions\PhoneUnsupportedException;
use Kkxdev\Truecaller\Exceptions\ProfileIncompleteException;

/**
 * Turns a provider-supplied phone number into a bare dial code plus a bare
 * national number.
 *
 * The whole point of this class is one invariant:
 *
 *   A normalised number must be one the user's own OTP flow could also have
 *   produced.
 *
 * That matters because a phone number is usually the identity. Get the split
 * wrong and you write a number the real owner can never match, so they get a
 * second account -- with their history, entitlements and rewards stranded on
 * the first one. Stores rarely have a unique index to catch it, and nobody
 * notices until support does.
 *
 * So this class never guesses. It builds a short list of candidate readings of
 * the digits, in order of likelihood, and returns the first that satisfies the
 * configured rule for its dial code. If none does, it rejects, and the caller
 * falls back to OTP -- which is lossless. Guessing is not.
 */
final class PhoneNormaliser implements PhoneNormaliserInterface
{
    /** Widest plausible dial code: 1-4 digits, no leading zero. */
    private const DIAL_CODE_PATTERN = '/^[1-9][0-9]{0,3}$/';

    /**
     * @param array<string, string> $isoToDialCode      ISO alpha-2 => bare dial code
     * @param array<string, string> $nationalNumberRules dial code (or 'default') => regex
     */
    public function __construct(
        private array $isoToDialCode,
        private array $nationalNumberRules
    ) {
    }

    public function normalise($rawPhone, ?string $isoCountry): NormalisedPhone
    {
        // One pass handles "+", spaces, dashes, parentheses, and the fact that
        // a JSON number arrives as an int rather than a string.
        $digits = preg_replace('/\D+/', '', (string) $rawPhone) ?? '';

        if ($digits === '') {
            throw new ProfileIncompleteException('Truecaller returned no phone number.');
        }

        $dial = $this->dialCodeForIso($isoCountry);

        if ($dial !== null) {
            $national = $this->firstValidReading($digits, $dial);

            if ($national !== null) {
                return new NormalisedPhone($dial, $national);
            }

            // The country said one thing and the digits say another. Fall
            // through rather than trusting either blindly.
        }

        return $this->resolveWithoutCountry($digits);
    }

    /**
     * Try each plausible reading of the digits against a known dial code.
     *
     * Order matters. "9123456789" is a perfectly valid Indian mobile that
     * happens to start with the Indian dial code, so "already national" has to
     * be tested before "strip the country code" -- otherwise a real number
     * gets mangled into an invalid one and the user is turned away.
     */
    private function firstValidReading(string $digits, string $dial, bool $requireStrip = false): ?string
    {
        foreach ($this->candidateNationalNumbers($digits, $dial, $requireStrip) as $candidate) {
            if ($this->isValidNationalNumber($candidate, $dial)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Readings of the digits, most likely first.
     *
     * Stripping the country code comes first because providers overwhelmingly
     * return E.164. Where a national number legitimately begins with its own
     * dial code -- Indian mobiles in the 9x series against dial code 91 -- the
     * stripped reading fails that country's rule and the untouched digits win
     * on the next pass, so the ordering costs nothing there while resolving
     * the countries whose rule is too permissive to disambiguate on its own.
     *
     * @param bool $requireStrip when the dial code was inferred from the digits
     *                           themselves, readings that leave it in place are
     *                           circular and must not be offered
     *
     * @return list<string>
     */
    private function candidateNationalNumbers(string $digits, string $dial, bool $requireStrip = false): array
    {
        $candidates = [];

        $withoutDial = $this->stripPrefix($digits, $dial);

        if ($withoutDial !== null) {
            $candidates[] = $withoutDial;

            // Some providers hand back the subscriber's own leading trunk zero.
            $candidates[] = ltrim($withoutDial, '0');

            // The doubled country code: "+91 91 9876543210". This is the one
            // malformation that looks entirely valid to the eye and matches
            // nothing at all, so it is the likeliest source of duplicates.
            $doubled = $this->stripPrefix($withoutDial, $dial);

            if ($doubled !== null) {
                $candidates[] = $doubled;
            }
        }

        if (!$requireStrip) {
            $candidates[] = $digits;
            $candidates[] = ltrim($digits, '0');
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $c): bool => $c !== '')));
    }

    /**
     * No usable country hint, so work backwards from the number itself.
     *
     * Longest prefix first, because "1" is a prefix of nothing but "91" is a
     * prefix of "919...". A match is only accepted if the remainder satisfies
     * that dial code's rule, so this cannot invent a country.
     */
    private function resolveWithoutCountry(string $digits): NormalisedPhone
    {
        $knownDialCodes = $this->knownDialCodes();

        for ($length = 4; $length >= 1; $length--) {
            $candidateDial = substr($digits, 0, $length);

            if (!isset($knownDialCodes[$candidateDial])) {
                continue;
            }

            $national = $this->firstValidReading($digits, $candidateDial, true);

            if ($national !== null) {
                return new NormalisedPhone($candidateDial, $national);
            }
        }

        throw new PhoneUnsupportedException(
            'Could not resolve a country for the number Truecaller returned.'
        );
    }

    private function dialCodeForIso(?string $isoCountry): ?string
    {
        if ($isoCountry === null || $isoCountry === '') {
            return null;
        }

        $dial = $this->isoToDialCode[strtoupper(trim($isoCountry))] ?? null;

        if ($dial === null) {
            return null;
        }

        $dial = (string) $dial;

        // A malformed map entry must not become a malformed identity.
        return preg_match(self::DIAL_CODE_PATTERN, $dial) === 1 ? $dial : null;
    }

    private function isValidNationalNumber(string $national, string $dial): bool
    {
        $rule = $this->nationalNumberRules[$dial]
            ?? $this->nationalNumberRules['default']
            ?? null;

        if ($rule === null) {
            return false;
        }

        return preg_match($rule, $national) === 1;
    }

    private function stripPrefix(string $digits, string $prefix): ?string
    {
        if ($prefix === '' || strlen($digits) <= strlen($prefix)) {
            return null;
        }

        if (strncmp($digits, $prefix, strlen($prefix)) !== 0) {
            return null;
        }

        return substr($digits, strlen($prefix));
    }

    /**
     * @return array<string, true>
     */
    private function knownDialCodes(): array
    {
        $codes = [];

        foreach ($this->isoToDialCode as $dial) {
            $dial = (string) $dial;

            if (preg_match(self::DIAL_CODE_PATTERN, $dial) === 1) {
                $codes[$dial] = true;
            }
        }

        return $codes;
    }
}
