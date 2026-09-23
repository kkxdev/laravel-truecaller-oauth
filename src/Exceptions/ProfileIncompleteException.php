<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Exceptions;

/**
 * The profile came back without the fields an identity needs.
 *
 * Raised when there is no subject, or no usable phone number -- typically
 * because the phone scope was not granted. Never fall back to the email as an
 * identity: two people can share an email on a profile, and merging on it
 * silently joins two accounts.
 */
final class ProfileIncompleteException extends TruecallerException
{
    public function errorCode(): string
    {
        return 'PHONE_NOT_AVAILABLE';
    }

    public function clientStatus(): int
    {
        return 422;
    }

    public function clientMessage(): string
    {
        return 'Truecaller did not share a phone number.';
    }
}
