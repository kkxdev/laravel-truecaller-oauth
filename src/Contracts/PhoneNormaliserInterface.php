<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Contracts;

use Kkxdev\Truecaller\DTO\NormalisedPhone;

interface PhoneNormaliserInterface
{
    /**
     * Split a provider-supplied number into a bare dial code and national number.
     *
     * @param string|int|null $rawPhone   as it arrived, with or without "+"
     * @param string|null     $isoCountry ISO alpha-2, e.g. "IN"
     *
     * @throws \Kkxdev\Truecaller\Exceptions\ProfileIncompleteException no number at all
     * @throws \Kkxdev\Truecaller\Exceptions\PhoneUnsupportedException  not normalisable with confidence
     */
    public function normalise($rawPhone, ?string $isoCountry): NormalisedPhone;
}
