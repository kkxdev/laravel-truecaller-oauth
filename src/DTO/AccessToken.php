<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\DTO;

/**
 * A Truecaller access token.
 *
 * Deliberately short-lived and never cached: it is used once, in the same
 * request that obtained it, to fetch the profile. Caching it would buy nothing
 * and would turn a stolen cache entry into an impersonation primitive.
 *
 * __toString() is not implemented on purpose, so the token cannot land in a
 * log line or an exception message by accident.
 */
final class AccessToken
{
    public function __construct(
        private string $value,
        private ?int $expiresIn = null,
        private ?string $tokenType = null,
        private ?string $scope = null
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            (string) ($payload['access_token'] ?? ''),
            isset($payload['expires_in']) ? (int) $payload['expires_in'] : null,
            isset($payload['token_type']) ? (string) $payload['token_type'] : null,
            isset($payload['scope']) ? (string) $payload['scope'] : null
        );
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isUsable(): bool
    {
        return $this->value !== '';
    }

    public function expiresIn(): ?int
    {
        return $this->expiresIn;
    }

    public function tokenType(): ?string
    {
        return $this->tokenType;
    }

    public function scope(): ?string
    {
        return $this->scope;
    }
}
