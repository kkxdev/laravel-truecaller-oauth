<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kkxdev\Truecaller\Contracts\ReplayGuardInterface;
use Kkxdev\Truecaller\Exceptions\CodeReplayedException;

/**
 * Single-use enforcement for authorization codes.
 *
 * The key is the sha256 of the code rather than the code itself, so a cache
 * dump or a KEYS scan never yields something replayable. Only the code goes
 * into the key: scoping it to a tenant would let the same code be spent once
 * per tenant, which is weaker, not stronger.
 *
 * The TTL only has to outlive the provider's own code lifetime. After that the
 * code is dead at the provider anyway, so the guard has nothing left to guard.
 */
final class CacheReplayGuard implements ReplayGuardInterface
{
    public function __construct(
        private CacheRepository $cache,
        private string $keyPrefix,
        private int $ttlSeconds
    ) {
    }

    public function claim(string $authorizationCode): void
    {
        // add() is atomic set-if-absent on every cache driver, so this behaves
        // identically on redis and on the array driver used in tests.
        if (!$this->cache->add($this->key($authorizationCode), 1, $this->ttlSeconds)) {
            throw new CodeReplayedException('This Truecaller authorization code has already been used.');
        }
    }

    public function release(string $authorizationCode): void
    {
        $this->cache->forget($this->key($authorizationCode));
    }

    private function key(string $authorizationCode): string
    {
        return $this->keyPrefix . hash('sha256', $authorizationCode);
    }
}
