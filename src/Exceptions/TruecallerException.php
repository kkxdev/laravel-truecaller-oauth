<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Exceptions;

use RuntimeException;

/**
 * Base for every failure this package raises.
 *
 * Each subclass owns the three things an HTTP caller needs to turn it into a
 * response -- a stable machine code, a status, and a message that is safe to
 * show a user -- so an integrating controller's whole failure path is one
 * catch block rather than a growing match statement.
 *
 * Messages never contain a phone number, a token, or an authorization code.
 */
abstract class TruecallerException extends RuntimeException
{
    /** Stable machine-readable code for clients to branch on. */
    abstract public function errorCode(): string;

    /** Status to report to the caller. */
    abstract public function clientStatus(): int;

    /** User-safe message. Never contains anything sensitive. */
    abstract public function clientMessage(): string;

    /**
     * True when retrying the same authorization code could plausibly succeed.
     *
     * This is what decides whether the replay guard is released: a transport
     * failure must not burn a code the user could still redeem, while a code
     * Truecaller has rejected stays dead.
     */
    public function isTransient(): bool
    {
        return false;
    }
}
