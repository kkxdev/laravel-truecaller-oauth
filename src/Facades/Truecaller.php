<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Kkxdev\Truecaller\DTO\TruecallerProfile authenticate(string $authorizationCode, string $codeVerifier)
 *
 * @see \Kkxdev\Truecaller\TruecallerManager
 */
class Truecaller extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'truecaller';
    }
}
