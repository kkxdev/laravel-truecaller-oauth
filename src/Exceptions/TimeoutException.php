<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Exceptions;

/**
 * The call to Truecaller timed out or the connection failed.
 *
 * Shares a client-facing code with UnavailableException on purpose -- the
 * caller's reaction is identical -- but stays a distinct class so operators
 * can tell a provider outage from a network problem on our own side.
 */
final class TimeoutException extends TruecallerException
{
    public function errorCode(): string
    {
        return 'TRUECALLER_TIMEOUT';
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
