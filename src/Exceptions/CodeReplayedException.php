<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Exceptions;

/**
 * The authorization code was already exchanged.
 *
 * Clients must not retry on this: an authorization code is single-use, so the
 * only recovery is to restart the SDK flow and obtain a fresh one.
 */
final class CodeReplayedException extends TruecallerException
{
    public function errorCode(): string
    {
        return 'TRUECALLER_CODE_REPLAYED';
    }

    public function clientStatus(): int
    {
        return 409;
    }

    public function clientMessage(): string
    {
        return 'This Truecaller login has already been used.';
    }
}
