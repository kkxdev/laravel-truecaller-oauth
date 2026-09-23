<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Kkxdev\Truecaller\Contracts\OAuthClientInterface;
use Kkxdev\Truecaller\DTO\AccessToken;
use Kkxdev\Truecaller\Exceptions\CodeRejectedException;
use Kkxdev\Truecaller\Exceptions\CodeReplayedException;
use Kkxdev\Truecaller\Exceptions\NotConfiguredException;
use Kkxdev\Truecaller\Exceptions\ProfileIncompleteException;
use Kkxdev\Truecaller\Exceptions\TimeoutException;
use Kkxdev\Truecaller\Exceptions\UnavailableException;
use Kkxdev\Truecaller\Support\CacheReplayGuard;
use Kkxdev\Truecaller\Support\PhoneNormaliser;
use Kkxdev\Truecaller\Support\ProfileMapper;
use Kkxdev\Truecaller\TruecallerManager;
use Throwable;

/**
 * Orchestration, and above all the replay guard's release rules -- the part
 * where getting it wrong either strands honest users or hands an attacker
 * unlimited retries.
 *
 * The client is faked with an anonymous class rather than a mocking library so
 * the call log is a plain assertion rather than an expectation DSL.
 */
class TruecallerManagerTest extends TestCase
{
    /** @var array<string, mixed> */
    private const USER_INFO = [
        'sub'                       => 'tc-sub-1',
        'given_name'                => 'Asha',
        'phone_number'              => '+919876543210',
        'phone_number_country_code' => 'IN',
    ];

    private CacheReplayGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new CacheReplayGuard(new Repository(new ArrayStore()), 'truecaller:code:', 900);
    }

    /**
     * @param array<string, mixed>|Throwable $userInfo
     */
    private function client($userInfo = self::USER_INFO, ?Throwable $exchangeFailure = null): OAuthClientInterface
    {
        return new class($userInfo, $exchangeFailure) implements OAuthClientInterface {
            public array $calls = [];

            public function __construct(private $userInfo, private ?Throwable $exchangeFailure)
            {
            }

            public function exchangeAuthorizationCode(string $authorizationCode, string $codeVerifier): AccessToken
            {
                $this->calls[] = ['exchange', $authorizationCode, $codeVerifier];

                if ($this->exchangeFailure !== null) {
                    throw $this->exchangeFailure;
                }

                return new AccessToken('access-token-value', 600, 'Bearer', 'openid profile phone');
            }

            public function fetchUserInfo(string $accessToken): array
            {
                $this->calls[] = ['userinfo', $accessToken];

                if ($this->userInfo instanceof Throwable) {
                    throw $this->userInfo;
                }

                return $this->userInfo;
            }
        };
    }

    private function manager(OAuthClientInterface $client, ?string $clientId = 'test-client-id'): TruecallerManager
    {
        [$isoMap, $rules] = $this->shippedPhoneConfig();

        return new TruecallerManager(
            $client,
            new ProfileMapper(new PhoneNormaliser($isoMap, $rules)),
            $this->guard,
            $clientId
        );
    }

    // ---- the happy path

    public function testAuthenticateExchangesTheCodeThenFetchesUserinfo(): void
    {
        $client = $this->client();

        $profile = $this->manager($client)->authenticate('code-1', 'verifier-1');

        $this->assertSame('tc-sub-1', $profile->sub());
        $this->assertSame('+919876543210', $profile->phone()->e164());
        $this->assertSame(
            [['exchange', 'code-1', 'verifier-1'], ['userinfo', 'access-token-value']],
            $client->calls,
            'order matters, and the PKCE verifier must be forwarded verbatim'
        );
    }

    // ---- replay

    public function testSecondExchangeOfTheSameCodeIsRejectedAsReplay(): void
    {
        $client = $this->client();
        $manager = $this->manager($client);

        $manager->authenticate('code-1', 'verifier-1');

        try {
            $manager->authenticate('code-1', 'verifier-1');
            $this->fail('the second exchange should have been rejected');
        } catch (CodeReplayedException $e) {
            $this->assertCount(2, $client->calls, 'the replayed attempt must never reach Truecaller');
        }
    }

    public function testDifferentCodesDoNotCollideInTheReplayGuard(): void
    {
        $manager = $this->manager($this->client());

        $manager->authenticate('code-1', 'verifier-1');
        $manager->authenticate('code-2', 'verifier-2');

        $this->addToAssertionCount(1);
    }

    public function testReplayGuardIsReleasedWhenTruecallerIsUnreachableSoTheUserCanRetry(): void
    {
        // A transport blip must not burn a code the user could still redeem.
        $failing = $this->manager($this->client(self::USER_INFO, new TimeoutException('timeout')));

        try {
            $failing->authenticate('code-1', 'verifier-1');
        } catch (TimeoutException $e) {
            // expected
        }

        $recovered = $this->manager($this->client())->authenticate('code-1', 'verifier-1');

        $this->assertSame('tc-sub-1', $recovered->sub());
    }

    public function testReplayGuardIsAlsoReleasedOnAProviderOutage(): void
    {
        $failing = $this->manager($this->client(self::USER_INFO, new UnavailableException('502')));

        try {
            $failing->authenticate('code-1', 'verifier-1');
        } catch (UnavailableException $e) {
            // expected
        }

        $this->assertSame('tc-sub-1', $this->manager($this->client())->authenticate('code-1', 'verifier-1')->sub());
    }

    public function testReplayGuardIsNotReleasedWhenTruecallerRejectsTheCode(): void
    {
        // A dead code stays dead; releasing would give a retrying attacker a
        // free pass at the exchange endpoint.
        $failing = $this->manager($this->client(self::USER_INFO, new CodeRejectedException('invalid_grant')));

        try {
            $failing->authenticate('code-1', 'verifier-1');
        } catch (CodeRejectedException $e) {
            // expected
        }

        $this->expectException(CodeReplayedException::class);

        $this->manager($this->client())->authenticate('code-1', 'verifier-1');
    }

    public function testReplayGuardIsNotReleasedWhenUserinfoFailsAfterASuccessfulExchange(): void
    {
        // The code is already spent at Truecaller, so a retry cannot work.
        $failing = $this->manager($this->client(new ProfileIncompleteException('no phone')));

        try {
            $failing->authenticate('code-1', 'verifier-1');
        } catch (ProfileIncompleteException $e) {
            // expected
        }

        $this->expectException(CodeReplayedException::class);

        $this->manager($this->client())->authenticate('code-1', 'verifier-1');
    }

    // ---- short circuits

    public function testMissingClientIdThrowsNotConfiguredWithoutCallingTruecaller(): void
    {
        $client = $this->client();

        try {
            $this->manager($client, null)->authenticate('code-1', 'verifier-1');
            $this->fail('a missing client id should have short-circuited');
        } catch (NotConfiguredException $e) {
            $this->assertSame([], $client->calls, 'nothing should reach Truecaller without a credential');
        }
    }

    public function testNotConfiguredIsIndistinguishableFromAnOutageToTheCaller(): void
    {
        $e = new NotConfiguredException('x');

        $this->assertSame('TRUECALLER_UNAVAILABLE', $e->errorCode());
        $this->assertSame(503, $e->clientStatus());
    }

    public function testMissingClientIdDoesNotConsumeTheAuthorizationCode(): void
    {
        try {
            $this->manager($this->client(), null)->authenticate('code-1', 'verifier-1');
        } catch (NotConfiguredException $e) {
            // expected
        }

        $this->assertSame('tc-sub-1', $this->manager($this->client())->authenticate('code-1', 'verifier-1')->sub());
    }

    public function testUserinfoWithoutAPhoneNumberSurfacesAsProfileIncomplete(): void
    {
        $info = self::USER_INFO;
        unset($info['phone_number']);

        $this->expectException(ProfileIncompleteException::class);

        $this->manager($this->client($info))->authenticate('code-1', 'verifier-1');
    }
}
