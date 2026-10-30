<?php

namespace PhilipAnnis\HttpRemember;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Throwable;

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

        // Provide a dedicated rotating log while preserving application overrides for this channel.
        if ($this->app['config']->get('logging.channels.http-remember') === null) {
            $this->app['config']->set('logging.channels.http-remember', [
                'driver' => 'daily',
                'path' => $this->app->storagePath('logs/http-remember.log'),
                'level' => 'debug',
                'days' => 7,
            ]);
        }
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
             * Remember reads or invalidate a group immediately or after successful mutations.
             *
             * @param  int|array{int, int}|null  $ttl  A lifetime, thresholds, or configured default.
             * @param  string|null  $store  A cache store override, or the configured default.
             * @param  list<string>|null  $group  The complete invalidation group. Null or an empty list disables grouping.
             * @param  'read'|HttpRememberOperation|null  $operation  Explicit read intent, or the internal marker for automatic method detection.
             * @param  (callable(Response): bool)|null  $cacheWhen  An optional response eligibility predicate.
             * @param  bool  $invalidate  Whether to invalidate the supplied group immediately without an HTTP request.
             * @return PendingRequest The same request builder for further chaining.
             *
             * @throws InvalidArgumentException When a cache policy is invalid.
             */
            function (
                int|array|null $ttl = null,
                ?string $store = null,
                ?array $group = null,
                string|HttpRememberOperation|null $operation = HttpRememberOperation::Automatic,
                ?callable $cacheWhen = null,
                bool $invalidate = false,
            ): PendingRequest {
                /** @var PendingRequest $this */

                // Reject explicit null while allowing later named arguments to omit operation.
                if ($operation === null) {
                    throw new InvalidArgumentException('The HTTP remember operation must be read when provided.');
                }

                // Resolve configuration when called so requests do not share mutable policies.
                $settings = new HttpRememberOptions(
                    $ttl ?? config('http-remember.ttl_seconds'),
                    $store ?? config('http-remember.store'),
                    config('http-remember.refresh_timeout_seconds'),
                    $group,
                    $operation === HttpRememberOperation::Automatic ? null : $operation,
                    $cacheWhen,
                    config('http-remember.ignored_headers'),
                    config('http-remember.max_response_bytes'),
                    config('http-remember.refresh_cooldown_seconds'),
                    config('http-remember.max_request_bytes'),
                );

                // Require an explicit group instead of treating direct invalidation as a cache-wide flush.
                if ($invalidate && $settings->groupHash === null) {
                    throw new InvalidArgumentException('The HTTP remember invalidation group must not be empty.');
                }

                // Invalidate once when remember is called, independently of later HTTP transfers.
                if ($invalidate) {
                    $store = $settings->store ?? config('cache.default');

                    // Keep direct invalidation failures consistent with live mutation failures.
                    try {
                        $invalidated = HttpRememberGroup::invalidate(Cache::store($settings->store), $settings->groupHash);
                        $event = $invalidated ? 'invalidated' : 'failed';
                        (new HttpRememberEventLog($event, null, $store, $settings->groupHash, operation: 'invalidate'))
                            ->publish('HTTP remember group invalidation.', ! $invalidated);
                    } catch (Throwable $exception) {
                        // Preserve the configured builder while reporting only safe failure metadata.
                        (new HttpRememberEventLog('failed', null, $store, $settings->groupHash, operation: 'invalidate', exception: $exception::class))
                            ->publish('HTTP remember group invalidation failed.', true);
                    }
                }

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
                        // Exclude the package observer while rejecting application preparation callbacks.
                        $internalCallbacks = HttpRememberServiceProvider::INTERNAL_BEFORE_SENDING_CALLBACK_COUNT
                            + (int) in_array(HttpRememberRequestObserver::CALLBACK, $this->beforeSendingCallbacks->all(), true);

                        // Laravel's internal callback and the observer do not change request identity.
                        return $this->beforeSendingCallbacks->count() === $internalCallbacks
                            && $this->middleware->last() instanceof HttpRememberMiddleware;
                    };

                // Retain the callback collection shared by the builder and its clones.
                $beforeSendingCallbacks = $this->beforeSendingCallbacks;
                $prepareInvalidation =
                    /**
                     * Observe the final method without repeating Laravel's preparation callbacks.
                     */
                    static function () use ($beforeSendingCallbacks): void {
                        // Reorder the observer before each transfer so newly added callbacks run first.
                        HttpRememberRequestObserver::register($beforeSendingCallbacks);
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
                return $this->withMiddleware(new HttpRememberMiddleware($settings, $canCache, $onHit, $prepareInvalidation));
            },
        );
    }
}
