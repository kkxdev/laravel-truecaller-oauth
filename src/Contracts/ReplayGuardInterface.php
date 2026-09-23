<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Contracts;

/**
 * Enforces that an authorization code is exchanged at most once.
 *
 * This is a spend record, not a lease: a claim is only given back when the
 * spend provably did not happen. See CacheReplayGuard for why that distinction
 * matters.
 */
interface ReplayGuardInterface
{
    /**
     * @throws \Kkxdev\Truecaller\Exceptions\CodeReplayedException if already claimed
     */
    public function claim(string $authorizationCode): void;

    /** Give a claim back, so the code can be retried. */
    public function release(string $authorizationCode): void;
}
