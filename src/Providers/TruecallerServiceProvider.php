<?php

declare(strict_types=1);

namespace Kkxdev\Truecaller\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Kkxdev\Truecaller\Contracts\OAuthClientInterface;
use Kkxdev\Truecaller\Contracts\PhoneNormaliserInterface;
use Kkxdev\Truecaller\Contracts\ReplayGuardInterface;
use Kkxdev\Truecaller\Http\Adapters\LaravelHttpOAuthClient;
use Kkxdev\Truecaller\Support\CacheReplayGuard;
use Kkxdev\Truecaller\Support\EndpointResolver;
use Kkxdev\Truecaller\Support\PhoneNormaliser;
use Kkxdev\Truecaller\Support\ProfileMapper;
use Kkxdev\Truecaller\Support\SecretMasker;
use Kkxdev\Truecaller\TruecallerManager;
use Psr\Log\LoggerInterface;

/**
 * Wires the package up.
 *
 * Deferred, because a login endpoint is a small slice of most applications and
 * nothing here needs to exist until someone actually authenticates.
 *
 * Applications that want the package's protocol logs in a file of their own
 * set truecaller.logging.channel; otherwise the framework's default logger is
 * used.
 */
class TruecallerServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/truecaller.php', 'truecaller');

        $this->app->singleton(EndpointResolver::class, function ($app) {
            return new EndpointResolver(
                (string) config('truecaller.region', 'noneu'),
                (array) config('truecaller.hosts', []),
                (array) config('truecaller.endpoints', [])
            );
        });

        $this->app->singleton(SecretMasker::class, function ($app) {
            return new SecretMasker((array) config('truecaller.logging.mask_keys', []));
        });

        $this->app->singleton(PhoneNormaliserInterface::class, function ($app) {
            return new PhoneNormaliser(
                (array) config('truecaller.iso_to_dial_code', []),
                (array) config('truecaller.national_number_rules', [])
            );
        });

        $this->app->singleton(ProfileMapper::class, function ($app) {
            return new ProfileMapper($app->make(PhoneNormaliserInterface::class));
        });

        $this->app->singleton(ReplayGuardInterface::class, function ($app) {
            $store = config('truecaller.replay.cache_store');

            return new CacheReplayGuard(
                $store ? $app['cache']->store($store) : $app['cache']->store(),
                (string) config('truecaller.replay.cache_key_prefix', 'truecaller:code:'),
                (int) config('truecaller.replay.ttl_seconds', 900)
            );
        });

        $this->app->singleton(OAuthClientInterface::class, function ($app) {
            return new LaravelHttpOAuthClient(
                $app->make(EndpointResolver::class),
                $app->make(SecretMasker::class),
                config('truecaller.client_id'),
                (int) config('truecaller.http.timeout', 15),
                (int) config('truecaller.http.connect_timeout', 5),
                $this->logger($app)
            );
        });

        $this->app->singleton(TruecallerManager::class, function ($app) {
            return new TruecallerManager(
                $app->make(OAuthClientInterface::class),
                $app->make(ProfileMapper::class),
                $app->make(ReplayGuardInterface::class),
                config('truecaller.client_id')
            );
        });

        $this->app->alias(TruecallerManager::class, 'truecaller');
    }

    /**
     * The configured channel, or the application's default logger.
     *
     * Resolved here rather than through a contextual binding because the
     * client is constructed directly above: container context only applies to
     * dependencies the container itself injects.
     */
    private function logger($app): LoggerInterface
    {
        $channel = config('truecaller.logging.channel');

        return $channel ? Log::channel((string) $channel) : $app->make(LoggerInterface::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/truecaller.php' => config_path('truecaller.php'),
            ], 'truecaller-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            EndpointResolver::class,
            SecretMasker::class,
            PhoneNormaliserInterface::class,
            ProfileMapper::class,
            ReplayGuardInterface::class,
            OAuthClientInterface::class,
            TruecallerManager::class,
            'truecaller',
        ];
    }
}
