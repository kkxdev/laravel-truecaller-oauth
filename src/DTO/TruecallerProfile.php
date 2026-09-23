<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\DTO;

/**
 * A verified Truecaller identity.
 *
 * The phone number is the identity here, and it is the only field guaranteed
 * present: Truecaller users can hide their name, and an email may be absent,
 * unverified, or shared. Callers should match users on phone() and treat
 * everything else as decoration.
 */
final class TruecallerProfile
{
    public function __construct(
        private string $sub,
        private NormalisedPhone $phone,
        private ?string $firstName = null,
        private ?string $lastName = null,
        private ?string $email = null,
        private bool $emailVerified = false,
        private ?string $gender = null,
        private ?string $pictureUrl = null,
        private ?string $isoCountry = null
    ) {
    }

    /** Truecaller's stable subject identifier for this account. */
    public function sub(): string
    {
        return $this->sub;
    }

    public function phone(): NormalisedPhone
    {
        return $this->phone;
    }

    public function firstName(): ?string
    {
        return $this->firstName;
    }

    public function lastName(): ?string
    {
        return $this->lastName;
    }

    public function fullName(): ?string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name === '' ? null : $name;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function emailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function gender(): ?string
    {
        return $this->gender;
    }

    public function pictureUrl(): ?string
    {
        return $this->pictureUrl;
    }

    /** ISO alpha-2 country the number was resolved against, when known. */
    public function isoCountry(): ?string
    {
        return $this->isoCountry;
    }

    /**
     * The minimum worth persisting to recognise this identity again.
     *
     * Only the subject: the access token is single-use and storing it would be
     * a liability, and the phone belongs in the caller's own phone fields.
     */
    public function identityForPersistence(): array
    {
        return ['sub' => $this->sub];
    }
}
