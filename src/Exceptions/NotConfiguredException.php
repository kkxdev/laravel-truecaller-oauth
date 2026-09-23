<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Exceptions;

/**
 * The package has no client id configured.
 *
 * Deliberately indistinguishable from an outage to the caller: a missing
 * credential is an operational problem, not something a user can act on.
 */
final class NotConfiguredException extends TruecallerException
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
}
