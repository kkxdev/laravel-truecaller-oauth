<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Exceptions;

/**
 * Truecaller returned a server error, throttled us, or answered nonsensically.
 */
final class UnavailableException extends TruecallerException
{
    public function errorCode(): string
    {
        return 'TRUECALLER_UNAVAILABLE';
    }

    public function clientStatus(): int
    {
        return 503;
    }

    public function clientMessage(): string
    {
        return 'Truecaller is temporarily unavailable. Please log in with OTP.';
    }

    public function isTransient(): bool
    {
        return true;
    }
}
