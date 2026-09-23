# laravel-truecaller-oauth

Server-side Truecaller OAuth 2.0 (SDK 3.x) login for Laravel 8–12.

An app obtains an authorization code from the Truecaller SDK; this package
exchanges it for a verified identity. What you do with that identity — find a
user, create one, issue a session — stays in your application.

```php
use Kkxdev\Truecaller\Facades\Truecaller;
use Kkxdev\Truecaller\Exceptions\TruecallerException;

try {
    $profile = Truecaller::authenticate($authorizationCode, $codeVerifier);
} catch (TruecallerException $e) {
    return response()->json(['code' => $e->errorCode()], $e->clientStatus());
}

$profile->phone()->e164();            // "+919876543210"
$profile->phone()->dialCode();        // "91"   (bare, never "+91")
$profile->phone()->nationalNumber();  // "9876543210"
$profile->sub();                      // stable Truecaller subject id
```

## Install

```bash
composer require kkxdev/laravel-truecaller-oauth
```

Auto-discovery registers the provider and the `Truecaller` facade. Set the
partner key from the Truecaller developer portal:

```dotenv
TRUECALLER_CLIENT_ID=your-partner-key
TRUECALLER_REGION=noneu          # or "eu"
```

To pin the ISO → dial-code map in your own repo:

```bash
php artisan vendor:publish --tag=truecaller-config
```

## What it guarantees

**A code can be spent once.** `CacheReplayGuard` claims the authorization code
atomically before the exchange. A claim is only given back when the spend
provably did not happen — a timeout or a provider outage — so a transport blip
never burns a code the user could still redeem, while a code Truecaller has
already rejected stays dead.

**A number is normalised or refused, never guessed.** Truecaller returns an
ISO alpha-2 country and an E.164-ish number; most user stores want a bare dial
code plus a bare national number. `PhoneNormaliser` tries the plausible
readings in order and returns the first that satisfies the configured rule for
its dial code, repairing the two malformations that actually occur (a doubled
country code, a leading trunk zero). If nothing validates it throws, because a
guessed dial code writes a number the real owner can never match — which
silently gives them a second account.

**Secrets never reach the log.** `SecretMasker` redacts recursively and
case-insensitively. Authorization codes are logged as a 12-character
fingerprint so one login can be followed across log lines without the code
itself being written down.

## Errors

Every failure is a `TruecallerException` carrying `errorCode()`,
`clientStatus()` and a user-safe `clientMessage()`.

| Exception | Meaning | Status |
|---|---|---|
| `CodeReplayedException` | code already exchanged — restart the SDK flow, do not retry | 409 |
| `CodeRejectedException` | provider rejected the code or PKCE verifier | 422 |
| `TokenRejectedException` | provider rejected the access token | 422 |
| `ProfileIncompleteException` | no subject, or no phone number shared | 422 |
| `PhoneUnsupportedException` | number not normalisable with confidence | 422 |
| `UnavailableException` | provider 5xx/429, or a 200 with no token | 503 |
| `TimeoutException` | connect or read timeout | 503 |
| `NotConfiguredException` | no client id configured | 503 |

`isTransient()` tells you whether retrying the same code could work; it is what
drives the replay guard's release.

## Security model

There is no signature to verify in the OAuth flow, so what makes this safe is:
the authorization code is single-use at Truecaller, PKCE binds it to the app
instance that began the flow, the replay guard stops it being spent twice, TLS
protects it in transit, and `TRUECALLER_CLIENT_ID` stays secret and unlogged.

Access tokens are used once, in the request that obtained them, and are never
cached.

## Configuration to verify before production

The OAuth hosts, paths and userinfo field names come from Truecaller's
developer portal and are the only values here not derivable from your own code.
All are env-overridable, so confirming them is an ops change rather than a code
change. The normaliser accepts `phone_number` as `"+919876543210"`,
`"919876543210"` or the JSON number `919876543210`.

## Tests

```bash
composer install && vendor/bin/phpunit
```

`config.audit.block-insecure` is disabled in `composer.json` because the dev
tree resolves to the Laravel 8 line on PHP 8.0 — the versions this package must
keep working against. It affects the test tree only.

## Licence

MIT
