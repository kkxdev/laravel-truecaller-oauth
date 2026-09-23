<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Support;

use Kkxdev\Truecaller\Contracts\PhoneNormaliserInterface;
use Kkxdev\Truecaller\DTO\TruecallerProfile;
use Kkxdev\Truecaller\Exceptions\ProfileIncompleteException;

/**
 * Turns a raw userinfo payload into a profile.
 *
 * Reads defensively: field names have drifted across Truecaller SDK versions,
 * names may be nested rather than flat, and a hidden Truecaller profile simply
 * omits things. Only the subject and the phone number are required, because
 * those are the only two fields an identity actually needs.
 */
final class ProfileMapper
{
    public function __construct(
        private PhoneNormaliserInterface $phoneNormaliser
    ) {
    }

    /**
     * @param array<string, mixed> $userInfo
     *
     * @throws ProfileIncompleteException
     * @throws \Kkxdev\Truecaller\Exceptions\PhoneUnsupportedException
     */
    public function map(array $userInfo): TruecallerProfile
    {
        $sub = $this->firstString($userInfo, ['sub', 'id', 'user_id', 'userId']);

        if ($sub === null) {
            // A profile with no subject is a broken contract, not a user we
            // can choose to trust anyway.
            throw new ProfileIncompleteException('Truecaller returned a profile with no subject.');
        }

        $rawPhone = $this->firstScalar($userInfo, ['phone_number', 'phoneNumber', 'phone']);
        $isoCountry = $this->firstString($userInfo, [
            'phone_number_country_code',
            'phoneNumberCountryCode',
            'country_code',
            'countryCode',
        ]);

        $phone = $this->phoneNormaliser->normalise($rawPhone, $isoCountry);

        return new TruecallerProfile(
            $sub,
            $phone,
            $this->firstName($userInfo),
            $this->lastName($userInfo),
            $this->email($userInfo),
            $this->emailVerified($userInfo),
            $this->gender($userInfo),
            $this->firstString($userInfo, ['picture', 'avatarUrl', 'avatar_url', 'pictureUrl']),
            $isoCountry !== null ? strtoupper($isoCountry) : null
        );
    }

    private function firstName(array $userInfo): ?string
    {
        return $this->firstString($userInfo, ['given_name', 'givenName', 'first_name', 'firstName'])
            ?? $this->nestedString($userInfo, 'name', ['given_name', 'givenName', 'first', 'first_name']);
    }

    private function lastName(array $userInfo): ?string
    {
        return $this->firstString($userInfo, ['family_name', 'familyName', 'last_name', 'lastName'])
            ?? $this->nestedString($userInfo, 'name', ['family_name', 'familyName', 'last', 'last_name']);
    }

    private function email(array $userInfo): ?string
    {
        $email = $this->firstString($userInfo, ['email'])
            ?? $this->nestedString($userInfo, 'onlineIdentities', ['email'])
            ?? $this->nestedString($userInfo, 'online_identities', ['email']);

        if ($email === null) {
            return null;
        }

        $email = strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private function emailVerified(array $userInfo): bool
    {
        $verified = $userInfo['email_verified'] ?? $userInfo['emailVerified'] ?? false;

        if (is_string($verified)) {
            return in_array(strtolower($verified), ['1', 'true', 'yes'], true);
        }

        return (bool) $verified;
    }

    /**
     * Truecaller's sentinel for "not set" has been spelled both ways in the
     * wild, and either would otherwise be stored as if it were a real answer.
     */
    private function gender(array $userInfo): ?string
    {
        $gender = $this->firstString($userInfo, ['gender']);

        if ($gender === null) {
            return null;
        }

        if (in_array(strtolower($gender), ['notset', 'nonset', 'unknown', 'n'], true)) {
            return null;
        }

        return $gender;
    }

    /**
     * @param list<string> $keys
     */
    private function firstString(array $source, array $keys): ?string
    {
        $value = $this->firstScalar($source, $keys);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param list<string> $keys
     *
     * @return scalar|null
     */
    private function firstScalar(array $source, array $keys)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];

            if (is_scalar($value) && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param list<string> $keys
     */
    private function nestedString(array $source, string $parent, array $keys): ?string
    {
        $nested = $source[$parent] ?? null;

        return is_array($nested) ? $this->firstString($nested, $keys) : null;
    }
}
