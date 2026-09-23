<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Exceptions;

/**
 * Truecaller rejected the access token when fetching the profile.
 */
final class TokenRejectedException extends TruecallerException
{
    public function errorCode(): string
    {
        return 'TRUECALLER_TOKEN_REJECTED';
    }

    public function clientStatus(): int
    {
        return 422;
    }

    public function clientMessage(): string
    {
        return 'Invalid or expired Truecaller authorization.';
    }
}
