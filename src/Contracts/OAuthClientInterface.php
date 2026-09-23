<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Contracts;

use Kkxdev\Truecaller\DTO\AccessToken;

/**
 * The network boundary: the only seam in this package.
 *
 * Everything downstream of it is a pure function of an array, so this is the
 * one thing worth faking in a test.
 */
interface OAuthClientInterface
{
    /**
     * Exchange a PKCE authorization code for an access token.
     *
     * @throws \Kkxdev\Truecaller\Exceptions\CodeRejectedException  provider rejected the code or verifier
     * @throws \Kkxdev\Truecaller\Exceptions\UnavailableException   5xx, 429, or a 200 carrying no token
     * @throws \Kkxdev\Truecaller\Exceptions\TimeoutException       connect or read timeout
     */
    public function exchangeAuthorizationCode(string $authorizationCode, string $codeVerifier): AccessToken;

    /**
     * Fetch the raw userinfo payload. Interpreting it is ProfileMapper's job.
     *
     * @return array<string, mixed>
     *
     * @throws \Kkxdev\Truecaller\Exceptions\TokenRejectedException
     * @throws \Kkxdev\Truecaller\Exceptions\UnavailableException
     * @throws \Kkxdev\Truecaller\Exceptions\TimeoutException
     */
    public function fetchUserInfo(string $accessToken): array;
}
