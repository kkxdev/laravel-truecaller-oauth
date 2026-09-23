<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Http\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kkxdev\Truecaller\Contracts\OAuthClientInterface;
use Kkxdev\Truecaller\DTO\AccessToken;
use Kkxdev\Truecaller\Exceptions\CodeRejectedException;
use Kkxdev\Truecaller\Exceptions\TimeoutException;
use Kkxdev\Truecaller\Exceptions\TokenRejectedException;
use Kkxdev\Truecaller\Exceptions\UnavailableException;
use Kkxdev\Truecaller\Support\EndpointResolver;
use Kkxdev\Truecaller\Support\SecretMasker;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The two calls that make up a Truecaller login.
 *
 * Failures are translated into typed exceptions rather than a false return,
 * because the caller has to tell "retry later" apart from "this code is dead"
 * -- the two have opposite consequences for the replay guard.
 */
final class LaravelHttpOAuthClient implements OAuthClientInterface
{
    /** Provider error codes that mean the code or verifier itself is bad. */
    private const REJECTION_ERRORS = [
        'invalid_grant',
        'invalid_request',
        'invalid_client',
        'unauthorized_client',
        'unsupported_grant_type',
        'invalid_scope',
    ];

    private LoggerInterface $logger;

    public function __construct(
        private EndpointResolver $endpoints,
        private SecretMasker $masker,
        private ?string $clientId,
        private int $timeout = 15,
        private int $connectTimeout = 5,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function exchangeAuthorizationCode(string $authorizationCode, string $codeVerifier): AccessToken
    {
        $url = $this->endpoints->token();
        $fingerprint = SecretMasker::fingerprint($authorizationCode);

        $this->logger->info('Truecaller: exchanging authorization code', [
            'url'              => $url,
            'code_fingerprint' => $fingerprint,
        ]);

        $response = $this->send(
            fn (): Response => $this->http()->asForm()->post($url, [
                'grant_type'    => 'authorization_code',
                'client_id'     => (string) $this->clientId,
                'code'          => $authorizationCode,
                'code_verifier' => $codeVerifier,
            ]),
            $url
        );

        if (!$response->successful()) {
            throw $this->tokenFailure($response, $url, $fingerprint);
        }

        $token = AccessToken::fromArray((array) $response->json());

        if (!$token->isUsable()) {
            // A 200 with no token is not something a retry fixes for this
            // request, but it is a provider fault, so treat it as an outage.
            $this->logger->error('Truecaller: token response carried no access token', [
                'url'              => $url,
                'code_fingerprint' => $fingerprint,
                'body'             => $this->masker->mask((array) $response->json()),
            ]);

            throw new UnavailableException('Truecaller returned no access token.');
        }

        $this->logger->info('Truecaller: authorization code exchanged', [
            'code_fingerprint' => $fingerprint,
            'expires_in'       => $token->expiresIn(),
            'scope'            => $token->scope(),
        ]);

        return $token;
    }

    public function fetchUserInfo(string $accessToken): array
    {
        $url = $this->endpoints->userinfo();

        $response = $this->send(
            fn (): Response => $this->http()->withToken($accessToken)->get($url),
            $url
        );

        if ($response->status() === 401 || $response->status() === 403) {
            $this->logger->warning('Truecaller: access token rejected at userinfo', [
                'url'    => $url,
                'status' => $response->status(),
            ]);

            throw new TokenRejectedException('Truecaller rejected the access token.');
        }

        if (!$response->successful()) {
            $this->logger->error('Truecaller: userinfo request failed', [
                'url'    => $url,
                'status' => $response->status(),
                'body'   => $this->masker->mask((array) $response->json()),
            ]);

            throw new UnavailableException('Truecaller could not return the profile.');
        }

        $userInfo = (array) $response->json();

        $this->logger->info('Truecaller: profile fetched', [
            'fields' => array_keys($userInfo),
        ]);

        return $userInfo;
    }

    /**
     * Shared transport-failure translation.
     *
     * @param callable(): Response $call
     */
    private function send(callable $call, string $url): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            $this->logger->error('Truecaller: connection failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            throw new TimeoutException('Could not reach Truecaller.', 0, $e);
        } catch (RequestException $e) {
            if ($e->response !== null) {
                return $e->response;
            }

            $this->logger->error('Truecaller: request failed with no response', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            throw new TimeoutException('Truecaller did not respond.', 0, $e);
        } catch (Throwable $e) {
            $this->logger->error('Truecaller: unexpected transport failure', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            throw new UnavailableException('Truecaller is unavailable.', 0, $e);
        }
    }

    /**
     * A 4xx from the token endpoint usually means the code or the PKCE
     * verifier is wrong, and the two are deliberately indistinguishable to the
     * end user. The provider's own error is logged verbatim -- it is not
     * sensitive -- so support can tell an expired code from a broken verifier.
     */
    private function tokenFailure(Response $response, string $url, string $fingerprint): UnavailableException|CodeRejectedException
    {
        $body = (array) $response->json();
        $providerError = (string) ($body['error'] ?? '');

        $context = [
            'url'              => $url,
            'code_fingerprint' => $fingerprint,
            'status'           => $response->status(),
            'provider_error'   => $providerError,
            'body'             => $this->masker->mask($body),
        ];

        $isRejection = in_array($providerError, self::REJECTION_ERRORS, true)
            || in_array($response->status(), [400, 401], true);

        if ($isRejection) {
            $this->logger->warning('Truecaller: authorization code rejected', $context);

            return new CodeRejectedException('Truecaller rejected the authorization code.');
        }

        $this->logger->error('Truecaller: token endpoint failed', $context);

        return new UnavailableException('Truecaller could not complete the exchange.');
    }

    private function http(): PendingRequest
    {
        // PendingRequest::connectTimeout() does not exist on Laravel 8, which
        // this package still supports, so the connect timeout goes through the
        // underlying Guzzle option instead -- that works on every version.
        return Http::timeout($this->timeout)
            ->withOptions(['connect_timeout' => $this->connectTimeout])
            ->acceptJson()
            ->withHeaders(['Cache-Control' => 'no-cache']);
    }
}
