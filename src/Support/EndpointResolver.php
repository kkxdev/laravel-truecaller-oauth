<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Support;

/**
 * Resolves the region-specific base host and joins it to a path.
 *
 * Truecaller runs separate EU and non-EU OAuth stacks, and calling the wrong
 * one for a user fails in a way that looks like a bad credential, so the
 * region is explicit configuration rather than anything inferred.
 */
final class EndpointResolver
{
    /**
     * @param array<string, string> $hosts     region => base URL
     * @param array<string, string> $endpoints name => path
     */
    public function __construct(
        private string $region,
        private array $hosts,
        private array $endpoints
    ) {
    }

    public function token(): string
    {
        return $this->url('token');
    }

    public function userinfo(): string
    {
        return $this->url('userinfo');
    }

    public function host(): string
    {
        $region = strtolower(trim($this->region));

        $host = $this->hosts[$region] ?? reset($this->hosts);

        return rtrim((string) $host, '/');
    }

    private function url(string $name): string
    {
        $path = (string) ($this->endpoints[$name] ?? '');

        return $this->host() . '/' . ltrim($path, '/');
    }
}
