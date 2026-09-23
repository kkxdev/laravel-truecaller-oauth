<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller;

use Kkxdev\Truecaller\Contracts\OAuthClientInterface;
use Kkxdev\Truecaller\Contracts\ReplayGuardInterface;
use Kkxdev\Truecaller\DTO\TruecallerProfile;
use Kkxdev\Truecaller\Exceptions\NotConfiguredException;
use Kkxdev\Truecaller\Exceptions\TruecallerException;
use Kkxdev\Truecaller\Support\ProfileMapper;

/**
 * The package's entire public surface.
 *
 * One call turns the authorization code an app obtained from the Truecaller
 * SDK into a verified identity. What the caller then does with that identity
 * -- look up a user, create one, issue a session -- is deliberately none of
 * this package's business.
 *
 * Security note worth keeping in view: nothing here verifies a signature,
 * because there is none to verify in the OAuth flow. What makes it safe is
 * that the code is single-use at Truecaller, PKCE binds it to the app instance
 * that started the flow, the replay guard below stops it being spent twice,
 * TLS protects it in transit, and the client id stays secret.
 */
final class TruecallerManager
{
    public function __construct(
        private OAuthClientInterface $client,
        private ProfileMapper $mapper,
        private ReplayGuardInterface $replayGuard,
        private ?string $clientId
    ) {
    }

    /**
     * Exchange a PKCE authorization code for a verified Truecaller identity.
     *
     * @throws TruecallerException every failure mode is a subclass, and each
     *                             one carries the code, status and user-safe
     *                             message a caller needs to respond with
     */
    public function authenticate(string $authorizationCode, string $codeVerifier): TruecallerProfile
    {
        if ($this->clientId === null || $this->clientId === '') {
            // Indistinguishable from an outage to the caller on purpose: a
            // missing credential is an operational problem, not a user error.
            throw new NotConfiguredException('No Truecaller client id is configured.');
        }

        $this->replayGuard->claim($authorizationCode);

        try {
            $token = $this->client->exchangeAuthorizationCode($authorizationCode, $codeVerifier);
            $userInfo = $this->client->fetchUserInfo($token->value());
        } catch (TruecallerException $e) {
            // Only give the code back when the spend provably did not happen.
            //
            // A timeout or an outage may have left the code unspent, and
            // burning it would strand a user who did nothing wrong. Anything
            // else -- a rejected code, or any failure after the exchange
            // already succeeded -- means the code is gone at Truecaller, and
            // releasing it would only hand a retrying attacker a free pass.
            //
            // This is why the release lives in a catch and not a finally.
            if ($e->isTransient()) {
                $this->replayGuard->release($authorizationCode);
            }

            throw $e;
        }

        return $this->mapper->map($userInfo);
    }
}
