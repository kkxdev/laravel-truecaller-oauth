<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kkxdev\Truecaller\Exceptions\CodeRejectedException;
use Kkxdev\Truecaller\Exceptions\TimeoutException;
use Kkxdev\Truecaller\Exceptions\TokenRejectedException;
use Kkxdev\Truecaller\Exceptions\UnavailableException;
use Kkxdev\Truecaller\Http\Adapters\LaravelHttpOAuthClient;
use Kkxdev\Truecaller\Support\EndpointResolver;
use Kkxdev\Truecaller\Support\SecretMasker;

/**
 * The only tests that touch the wire. Everything about the request shape that
 * Truecaller actually sees is pinned here, because it is the one thing that
 * cannot be inferred from the rest of the codebase.
 */
class LaravelHttpOAuthClientTest extends TestCase
{
    private function client(?string $region = null): LaravelHttpOAuthClient
    {
        return new LaravelHttpOAuthClient(
            new EndpointResolver(
                $region ?? (string) config('truecaller.region'),
                (array) config('truecaller.hosts'),
                (array) config('truecaller.endpoints')
            ),
            new SecretMasker((array) config('truecaller.logging.mask_keys')),
            'test-client-id',
            15,
            5
        );
    }

    // ---- request shape

    public function testTokenExchangePostsFormEncodedPkceParameters(): void
    {
        Http::fake([
            'oauth-noneu.test/v1/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600], 200),
        ]);

        $token = $this->client()->exchangeAuthorizationCode('code-1', 'verifier-1');

        $this->assertSame('tok', $token->value());
        $this->assertSame(600, $token->expiresIn());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://oauth-noneu.test/v1/token', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertStringContainsString(
                'application/x-www-form-urlencoded',
                $request->header('Content-Type')[0] ?? ''
            );
            $this->assertSame('authorization_code', $request['grant_type']);
            $this->assertSame('test-client-id', $request['client_id']);
            $this->assertSame('code-1', $request['code']);
            $this->assertSame('verifier-1', $request['code_verifier']);

            return true;
        });
    }

    public function testUserinfoIsFetchedWithTheBearerAccessToken(): void
    {
        Http::fake([
            'oauth-noneu.test/v1/userinfo' => Http::response(['sub' => 'tc-1', 'phone_number' => '+919876543210'], 200),
        ]);

        $userInfo = $this->client()->fetchUserInfo('access-token-value');

        $this->assertSame('tc-1', $userInfo['sub']);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://oauth-noneu.test/v1/userinfo', $request->url());
            $this->assertSame('GET', $request->method());
            $this->assertSame('Bearer access-token-value', $request->header('Authorization')[0] ?? '');

            return true;
        });
    }

    public function testRegionConfigSelectsTheEuHost(): void
    {
        // Calling the wrong regional stack fails in a way that looks like a bad
        // credential, so this is worth pinning.
        Http::fake(['oauth-eu.test/*' => Http::response(['access_token' => 'tok'], 200)]);

        $this->client('eu')->exchangeAuthorizationCode('code-1', 'verifier-1');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://oauth-eu.test/v1/token');
    }

    // ---- failure translation

    public function testInvalidGrantBecomesCodeRejected(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(CodeRejectedException::class);

        $this->client()->exchangeAuthorizationCode('code-1', 'verifier-1');
    }

    public function testServerErrorFromTokenEndpointBecomesUnavailable(): void
    {
        Http::fake(['*' => Http::response(['error' => 'server_error'], 500)]);

        $this->expectException(UnavailableException::class);

        $this->client()->exchangeAuthorizationCode('code-1', 'verifier-1');
    }

    public function testThrottlingBecomesUnavailableRatherThanARejection(): void
    {
        // 429 is transient: the caller must be told to retry, not that the
        // user's login was invalid.
        Http::fake(['*' => Http::response([], 429)]);

        $this->expectException(UnavailableException::class);

        $this->client()->exchangeAuthorizationCode('code-1', 'verifier-1');
    }

    public function testSuccessWithNoAccessTokenBecomesUnavailable(): void
    {
        Http::fake(['*' => Http::response(['token_type' => 'Bearer'], 200)]);

        $this->expectException(UnavailableException::class);

        $this->client()->exchangeAuthorizationCode('code-1', 'verifier-1');
    }

    public function testConnectionFailureBecomesTimeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->expectException(TimeoutException::class);

        $this->client()->exchangeAuthorizationCode('code-1', 'verifier-1');
    }

    public function testUnauthorisedUserinfoBecomesTokenRejected(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_token'], 401)]);

        $this->expectException(TokenRejectedException::class);

        $this->client()->fetchUserInfo('access-token-value');
    }

    public function testServerErrorFromUserinfoBecomesUnavailable(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $this->expectException(UnavailableException::class);

        $this->client()->fetchUserInfo('access-token-value');
    }

    public function testTransientFailuresAreFlaggedForTheReplayGuardAndRejectionsAreNot(): void
    {
        // This flag is what decides whether an authorization code is given back.
        $this->assertTrue((new TimeoutException('x'))->isTransient());
        $this->assertTrue((new UnavailableException('x'))->isTransient());
        $this->assertFalse((new CodeRejectedException('x'))->isTransient());
        $this->assertFalse((new TokenRejectedException('x'))->isTransient());
    }
}
