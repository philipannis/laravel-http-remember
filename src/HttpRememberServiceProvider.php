<?php

namespace PhilipAnnis\HttpRemember;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Register optional configuration and the fluent HTTP remember macro.
 *
 * @author Philip Annis
 *
 * @link https://github.com/philipannis
 */
class HttpRememberServiceProvider extends ServiceProvider
{
    /**
     * The number of built-in callbacks that prepare Laravel's request metadata and events.
     * Public because Laravel rebinds the macro's scope to PendingRequest.
     *
     * @internal
     */
    public const INTERNAL_BEFORE_SENDING_CALLBACK_COUNT = 1;

    /**
     * Register defaults without requiring configuration to be published.
     */
    public function register(): void
    {
        // Merge package defaults with application configuration.
        $this->mergeConfigFrom(__DIR__.'/../config/http-remember.php', 'http-remember');
    }

    /**
     * Make remember available on the HTTP facade and pending request chains.
     */
    public function boot(): void
    {
        // Publish configuration only when the application explicitly requests it.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/http-remember.php' => $this->app->configPath('http-remember.php'),
            ], 'http-remember-config');
        }

        // Register the macro on PendingRequest so every fluent entry point works.
        PendingRequest::macro('remember',
            /**
             * Remember successful responses using an optional per-request policy.
             *
             * @param  int|array{int, int}|null  $ttl  A lifetime, thresholds, or configured default.
             * @param  string|null  $store  A cache store override, or the configured default.
             * @return PendingRequest The same request builder for further chaining.
             *
             * @throws \InvalidArgumentException When a cache policy is invalid.
             */
            function (int|array|null $ttl = null, ?string $store = null): PendingRequest {
                /** @var PendingRequest $this */

                // Resolve configuration when called so requests do not share mutable policies.
                $settings = new HttpRememberOptions(
                    $ttl ?? config('http-remember.ttl'),
                    $store ?? config('http-remember.store'),
                    config('http-remember.refresh_timeout'),
                );

                // Replace an earlier policy instead of nesting multiple cache layers.
                $this->middleware = $this->middleware->reject(
                    /**
                     * Identify middleware previously installed by this macro.
                     *
                     * @param  callable  $middleware  An existing request middleware.
                     * @return bool Whether this package owns the middleware.
                     */
                    static fn (callable $middleware): bool => $middleware instanceof HttpRememberMiddleware,
                );

                // Bypass caching when later hooks could change the request's identity.
                $canCache =
                    /**
                     * Check the final builder state before fingerprinting a request.
                     *
                     * @return bool Whether all request mutations precede the cache layer.
                     */
                    function (): bool {
                        // Laravel adds one internal callback; additional callbacks can mutate requests.
                        return $this->beforeSendingCallbacks->count() === HttpRememberServiceProvider::INTERNAL_BEFORE_SENDING_CALLBACK_COUNT
                            && $this->middleware->last() instanceof HttpRememberMiddleware;
                    };

                // Preserve Laravel's request metadata and events on a cache hit.
                $onHit =
                    /**
                     * Populate the builder without fabricating network transfer statistics.
                     *
                     * @param  RequestInterface  $request  The request served from cache.
                     * @param  array<string, mixed>  $options  The Guzzle transfer options.
                     */
                    function (RequestInterface $request, array $options): void {
                        // Run Laravel's internal callback after the custom-callback guard passes.
                        $this->transferStats = null;
                        $this->runBeforeSendingCallbacks($request, $options);
                    };

                // Attach caching only to the request builder that opted in.
                return $this->withMiddleware(new HttpRememberMiddleware($settings, $canCache, $onHit));
            },
        );
    }
}
