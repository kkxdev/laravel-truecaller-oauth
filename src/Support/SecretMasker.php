<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Support;

/**
 * Redacts credentials before anything reaches a log.
 *
 * Masking has to be recursive because provider payloads nest (an address, a
 * name object), and case-insensitive because the same field arrives as
 * access_token from the token endpoint and accessToken from an SDK. Anything
 * not on the list passes through untouched -- an error body with no
 * error_description is useless to support.
 */
final class SecretMasker
{
    public const REDACTED = '***MASKED***';

    /** @var array<string, true> */
    private array $maskKeys;

    /**
     * @param list<string> $maskKeys
     */
    public function __construct(array $maskKeys)
    {
        $this->maskKeys = array_fill_keys(
            array_map(static fn ($key): string => strtolower((string) $key), $maskKeys),
            true
        );
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public function mask(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && isset($this->maskKeys[strtolower($key)])) {
                $context[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->mask($value);
            }
        }

        return $context;
    }

    /**
     * A stable, non-reversible handle for one secret.
     *
     * Lets a single authorization code be followed across log lines -- claimed,
     * exchanged, rejected -- without the code itself ever being written down.
     */
    public static function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 12);
    }
}
