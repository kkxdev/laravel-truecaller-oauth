<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Tests;

use Kkxdev\Truecaller\Providers\TruecallerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('truecaller.client_id', 'test-client-id');
        config()->set('truecaller.region', 'noneu');
        config()->set('truecaller.hosts', [
            'noneu' => 'https://oauth-noneu.test',
            'eu'    => 'https://oauth-eu.test',
        ]);
        config()->set('truecaller.endpoints', [
            'token'    => '/v1/token',
            'userinfo' => '/v1/userinfo',
        ]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TruecallerServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        config()->set('cache.default', 'array');
    }

    /**
     * The ISO map and rules as shipped, so tests exercise the real defaults
     * rather than a convenient subset.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    protected function shippedPhoneConfig(): array
    {
        return [
            (array) config('truecaller.iso_to_dial_code'),
            (array) config('truecaller.national_number_rules'),
        ];
    }
}
