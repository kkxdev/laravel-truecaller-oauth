<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Tests;

use Kkxdev\Truecaller\Support\SecretMasker;

class SecretMaskerTest extends TestCase
{
    private function masker(): SecretMasker
    {
        return new SecretMasker((array) config('truecaller.logging.mask_keys'));
    }

    public function testAuthorizationCodeAndVerifierAreNeverWrittenToTheLog(): void
    {
        $masked = $this->masker()->mask([
            'authorization_code' => 'super-secret-code',
            'code_verifier'      => 'super-secret-verifier',
            'url'                => 'https://oauth.test/v1/token',
        ]);

        $this->assertSame(SecretMasker::REDACTED, $masked['authorization_code']);
        $this->assertSame(SecretMasker::REDACTED, $masked['code_verifier']);
        $this->assertSame('https://oauth.test/v1/token', $masked['url'], 'non-secret context must survive');
        $this->assertStringNotContainsString('super-secret', json_encode($masked));
    }

    public function testCamelCaseAliasesAreMaskedToo(): void
    {
        // The SDK emits camelCase; the token endpoint emits snake_case.
        $masked = $this->masker()->mask([
            'authorizationCode' => 'a',
            'codeVerifier'      => 'b',
            'accessToken'       => 'c',
        ]);

        foreach ($masked as $value) {
            $this->assertSame(SecretMasker::REDACTED, $value);
        }
    }

    public function testNestedSecretsAreMaskedRecursively(): void
    {
        // Provider payloads nest, so a non-recursive mask leaks.
        $masked = $this->masker()->mask([
            'body' => [
                'access_token' => 'tok',
                'profile'      => ['phone_number' => '+919876543210', 'email' => 'a@b.com'],
            ],
        ]);

        $this->assertSame(SecretMasker::REDACTED, $masked['body']['access_token']);
        $this->assertSame(SecretMasker::REDACTED, $masked['body']['profile']['phone_number']);
        $this->assertSame(SecretMasker::REDACTED, $masked['body']['profile']['email']);
    }

    public function testTheClientIdIsMaskedBecauseItIsTheOnlyClientCredential(): void
    {
        $masked = $this->masker()->mask(['client_id' => 'partner-key']);

        $this->assertSame(SecretMasker::REDACTED, $masked['client_id']);
    }

    public function testProviderErrorDescriptionsSurviveSoSupportCanDiagnose(): void
    {
        $masked = $this->masker()->mask([
            'error'             => 'invalid_grant',
            'error_description' => 'authorization code expired',
        ]);

        $this->assertSame('invalid_grant', $masked['error']);
        $this->assertSame('authorization code expired', $masked['error_description']);
    }

    public function testFingerprintIsStableAndDoesNotRevealTheSecret(): void
    {
        $code = 'authorization-code-value';

        $this->assertSame(SecretMasker::fingerprint($code), SecretMasker::fingerprint($code));
        $this->assertNotSame(SecretMasker::fingerprint($code), SecretMasker::fingerprint($code . 'x'));
        $this->assertSame(12, strlen(SecretMasker::fingerprint($code)));
        $this->assertStringNotContainsString($code, SecretMasker::fingerprint($code));
    }
}
