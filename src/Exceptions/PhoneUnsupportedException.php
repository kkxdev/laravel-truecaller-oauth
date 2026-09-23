<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Exceptions;

/**
 * The phone number could not be normalised with confidence.
 *
 * Either the country is absent from the ISO -> dial-code map or the national
 * number failed its rule. Rejecting is deliberate: a guessed dial code
 * produces a number the real owner can never match.
 */
final class PhoneUnsupportedException extends TruecallerException
{
    public function errorCode(): string
    {
        return 'PHONE_UNSUPPORTED';
    }

    public function clientStatus(): int
    {
        return 422;
    }

    public function clientMessage(): string
    {
        return 'Truecaller number not supported. Please log in with OTP.';
    }
}
