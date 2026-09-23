<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Exceptions;

/**
 * Truecaller rejected the authorization code or the PKCE verifier.
 *
 * Expired codes and mismatched verifiers are deliberately indistinguishable
 * here; the provider's own error is logged for support to tell them apart.
 */
final class CodeRejectedException extends TruecallerException
{
    public function errorCode(): string
    {
        return 'TRUECALLER_CODE_INVALID';
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
