<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\DTO;

/**
 * A phone number split the way a user store normally wants it.
 *
 * The dial code is bare ("91", never "+91") and the national number carries no
 * country code, so the two can be concatenated into E.164 or stored separately
 * without any further parsing. Construct it through PhoneNormaliser rather
 * than directly -- the invariants below are only guaranteed by that path.
 */
final class NormalisedPhone
{
    public function __construct(
        private string $dialCode,
        private string $nationalNumber
    ) {
    }

    /** Bare dial code, e.g. "91". Never "+91". */
    public function dialCode(): string
    {
        return $this->dialCode;
    }

    /** National number with no country code, e.g. "9876543210". */
    public function nationalNumber(): string
    {
        return $this->nationalNumber;
    }

    /** e.g. "+919876543210". */
    public function e164(): string
    {
        return '+' . $this->dialCode . $this->nationalNumber;
    }

    /** Digits only, no leading "+", e.g. "919876543210". */
    public function digits(): string
    {
        return $this->dialCode . $this->nationalNumber;
    }

    /** e.g. "+91*****3210" -- safe to log. */
    public function masked(): string
    {
        $tail = substr($this->nationalNumber, -4);
        $hidden = max(0, strlen($this->nationalNumber) - 4);

        return '+' . $this->dialCode . str_repeat('*', $hidden) . $tail;
    }

    public function toArray(): array
    {
        return [
            'dial_code'       => $this->dialCode,
            'national_number' => $this->nationalNumber,
            'e164'            => $this->e164(),
        ];
    }
}
