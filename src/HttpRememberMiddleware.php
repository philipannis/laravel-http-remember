<?php

namespace PhilipAnnis\HttpRemember;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionFunction;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Cache reads, defer stale refreshes, and invalidate groups after live mutations.
 *
 * @internal
 *
 * @phpstan-type Handler callable(RequestInterface, array<string, mixed>): PromiseInterface
 */
final class HttpRememberMiddleware
{
    /**
     * The cache namespace used to isolate stored responses.
     */
    private const CACHE_KEY_PREFIX = 'http-remember:';

    /**
     * Seconds reserved for response buffering and persistence after the network timeout.
     */
    private const REFRESH_LOCK_BUFFER_SECONDS = 10;

    /**
     * Seconds reserved for the generation check and cache write.
     */
    private const WRITE_LOCK_SECONDS = 10;

    /**
     * Create middleware for one immutable request policy.
     *
     * @param  HttpRememberOptions  $settings  The cache lifetimes, store, group, operation intent, and ignored headers.
     * @param  Closure(): bool  $canCache  A guard against later request mutations.
     * @param  Closure(RequestInterface, array<string, mixed>): void  $onHit  Laravel's cache-hit bookkeeping.
     */
    public function __construct(
        private readonly HttpRememberOptions $settings,
        private readonly Closure $canCache,
        private readonly Closure $onHit,
    ) {}

    /**
     * Decorate the next Guzzle handler without blocking asynchronous requests.
     *
     * @param  Handler  $handler  The next handler in the outgoing request stack.
     * @return Handler The handler that serves remembered responses.
     */
    public function __invoke(callable $handler): callable
    {
        // Keep callback bindings local to this handler stack when builders share middleware.
        $canCache = $this->canCache;
        $onHit = $this->onHit;

        // Laravel's next before-sending handler belongs to the builder sending this request.
        if ((new ReflectionFunction($canCache))->getClosureThis() instanceof PendingRequest) {
            $pendingRequest = $handler instanceof Closure ? (new ReflectionFunction($handler))->getClosureThis() : null;

            // Bypass reads when later middleware obscures the builder's preparation handler.
            if (! $pendingRequest instanceof PendingRequest) {
                $canCache = static fn (): bool => false;
            } else {
                // Rebind cloned callbacks without changing the original builder's middleware.
                $canCache = $canCache->bindTo($pendingRequest, PendingRequest::class);
                $onHit = $onHit->bindTo($pendingRequest, PendingRequest::class);
            }
        }

        // Keep the original promise contract on both cache hits and network requests.
        return
            /**
             * Resolve a cached response or invoke the original HTTP handler.
             *
             * @param  RequestInterface  $request  The prepared outgoing request.
             * @param  array<string, mixed>  $options  The Guzzle transfer options.
             * @return PromiseInterface The cached or live response promise.
             */
            function (RequestInterface $request, array $options) use ($handler, $canCache, $onHit): PromiseInterface {
                // Send grouped mutation methods live unless explicitly marked as read operations.
                if ($this->settings->groupHash !== null
                    && $this->settings->operation !== 'read'
                    && ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
                    return $this->sendAndInvalidate($handler, $request, $options);
                }

                // Avoid consuming uploads, streams, or downloads with transfer side effects.
                if ($this->shouldBypassCache($request, $options, $canCache)) {
                    return $handler($request, $options);
                }

                // Keep diagnostics and cache-hit state available when a cache read fails.
                $key = null;
                $response = null;

                // Treat cache failures as misses without intercepting network exceptions.
                try {
                    // Resolve a group only when this request explicitly selects one.
                    $cache = Cache::store($this->settings->store);
                    $group = $this->settings->groupHash === null
                        ? null
                        : HttpRememberGroup::resolve($cache, $this->settings->groupHash);

                    // Fingerprint the prepared request and its current invalidation generation.
                    $key = $this->cacheKey($request, $options, $group);
                    $cached = HttpRememberResponse::restore($cache->get($key));

                    // Enforce hard expiry and skip groups invalidated while reading the response.
                    if ($cached !== null
                        && ! $cached->hasReached($this->settings->lifetime)
                        && ($group === null || $group->isCurrent())) {
                        // Reconstruct the response before inspecting its declared header dependencies.
                        $candidate = $cached->toResponse();

                        // Recheck entries stored before the ignored-header configuration changed.
                        if (! $this->variesOnIgnoredHeaders($candidate)) {
                            $response = $candidate;
                        }
                    }
                } catch (Throwable $exception) {
                    // Report the cache failure without exposing the exception message.
                    $this->logFailure('read', $key, ['exception' => $exception::class]);

                    // Let the live request retain Laravel's normal error handling.
                    return $handler($request, $options);
                }

                // Keep application event failures outside cache failure handling.
                if ($response !== null) {
                    // Restore Laravel's request metadata and events for the cached response.
                    $onHit($request, $options);

                    // Keep a usable stale response even if refresh scheduling fails.
                    if ($cached->hasReached($this->settings->fresh)) {
                        // Contain scheduling failures while preserving the cache hit.
                        try {
                            // Defer a refresh of the generation served to this caller.
                            $this->refreshLater($handler, $request, $options, $cache, $key, $cached, $canCache, $group);
                        } catch (Throwable $exception) {
                            // Report the scheduling failure using safe exception metadata.
                            $this->logFailure('refresh', $key, ['exception' => $exception::class]);
                        }
                    }

                    // Give each cache hit an independent fulfilled response promise.
                    return Create::promiseFor($response);
                }

                // Populate an absent or expired entry only after a successful response.
                return $this->sendAndRemember($handler, $request, $options, $cache, $key, null, $group);
            };
    }

    /**
     * Send a grouped mutation and invalidate its group after a non-error response.
     *
     * @param  Handler  $handler  The next handler in the outgoing request stack.
     * @param  RequestInterface  $request  The mutation that must reach the API.
     * @param  array<string, mixed>  $options  The original Guzzle transfer options.
     * @return PromiseInterface The unchanged live response or transport rejection.
     */
    private function sendAndInvalidate(callable $handler, RequestInterface $request, array $options): PromiseInterface
    {
        // Bind invalidation to the store selected before the mutation starts.
        try {
            $cache = Cache::store($this->settings->store);
        } catch (Throwable $exception) {
            // Preserve the live mutation when its cache store cannot be resolved.
            $this->logFailure('invalidate', null, ['exception' => $exception::class]);

            return $handler($request, $options);
        }

        // Invalidate without buffering, caching, or deferring the mutation response.
        return $handler($request, $options)->then(
            /**
             * Rotate the matching group after the upstream operation completes.
             *
             * @param  ResponseInterface  $response  The live upstream response.
             * @return ResponseInterface The original response received by the caller.
             */
            function (ResponseInterface $response) use ($cache): ResponseInterface {
                // Invalidate redirects before their follow-up reads while preserving failed mutations.
                if ($response->getStatusCode() < HttpStatus::HTTP_OK || $response->getStatusCode() >= HttpStatus::HTTP_BAD_REQUEST) {
                    return $response;
                }

                // Keep invalidation failures separate from the completed HTTP operation.
                try {
                    if (! HttpRememberGroup::invalidate($cache, $this->settings->groupHash)) {
                        $this->logFailure('invalidate', null);
                    }
                } catch (Throwable $exception) {
                    // Report failures without exposing request or group values.
                    $this->logFailure('invalidate', null, ['exception' => $exception::class]);
                }

                // Preserve Laravel's normal handling of the live mutation response.
                return $response;
            },
        );
    }

    /**
     * Determine whether a transfer is unsuitable for response caching.
     *
     * @param  RequestInterface  $request  The prepared outgoing request.
     * @param  array<string, mixed>  $options  The Guzzle transfer options.
     * @param  Closure(): bool  $canCache  The mutation guard bound to the sending builder.
     * @return bool Whether the request must pass through without caching.
     */
    private function shouldBypassCache(RequestInterface $request, array $options, Closure $canCache): bool
    {
        // Avoid caching an identity that a later middleware or callback might change.
        if (! $canCache()) {
            return true;
        }

        // Preserve Guzzle's header and trailer callbacks and their normal rejection handling.
        if (isset($options['on_headers']) || isset($options['on_trailers'])) {
            return true;
        }

        // Preserve streaming, file downloads, multipart uploads, and custom transport behavior.
        if (($options['stream'] ?? false) || isset($options['sink']) || ! empty($options['curl'])
            || ! empty($options['stream_context'])
            || str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'multipart/')) {
            return true;
        }

        // Inspect the upload stream without consuming its contents.
        $body = $request->getBody();

        // Cache only readable, seekable bodies positioned at the beginning of the stream.
        try {
            return ! $body->isReadable() || ! $body->isSeekable() || $body->tell() !== 0;
        } catch (Throwable) {
            // Let the original handler decide how to handle an unusable request stream.
            return true;
        }
    }

    /**
     * Build a credential-sensitive key without storing request details in plaintext.
     *
     * @param  RequestInterface  $request  The request at the middleware's stack position.
     * @param  array<string, mixed>  $options  The Guzzle transfer options.
     * @param  HttpRememberGroup|null  $group  The selected invalidation generation, or no grouping.
     * @return string A namespaced SHA-256 key for this request and lifetime policy.
     *
     * @throws RuntimeException When the request body cannot be fingerprinted.
     */
    private function cacheKey(RequestInterface $request, array $options, ?HttpRememberGroup $group): string
    {
        // Normalize names and omit configured headers without changing the outgoing request.
        $headers = array_diff_key(
            array_change_key_case($request->getHeaders(), CASE_LOWER),
            array_flip($this->settings->ignoredHeaders),
        );
        ksort($headers);

        // Restore the request stream even when hashing an unusual stream fails.
        try {
            // Hash the complete upload body as part of the request's identity.
            $bodyHash = Utils::hash($request->getBody(), 'sha256');
        } finally {
            // Leave the upload ready for the original HTTP handler.
            $request->getBody()->rewind();
        }

        // Separate payloads, credentials, transport variants, and per-request lifetimes.
        $identity = [
            $request->getMethod(),
            (string) $request->getUri()->withFragment(''),
            $request->getProtocolVersion(),
            $headers,
            $bodyHash,
            $options['auth'] ?? null,
            $options['cert'] ?? null,
            $options['ssl_key'] ?? null,
            $options['verify'] ?? true,
            $options['decode_content'] ?? true,
            $options['proxy'] ?? null,
            $this->settings->fresh,
            $this->settings->lifetime,
        ];

        // Preserve ordinary cache keys while separating group identities and generations.
        if ($group !== null) {
            $identity[] = $this->settings->groupHash;
            $identity[] = $group->version;
        }

        // Keep request and group values out of the cache key's plaintext representation.
        return self::CACHE_KEY_PREFIX.hash('sha256', serialize($identity));
    }

    /**
     * Reject responses whose declared variants cannot be represented by the filtered key.
     *
     * @param  ResponseInterface  $response  The live or remembered upstream response.
     * @return bool Whether vary requires an excluded header or unrestricted variation.
     */
    private function variesOnIgnoredHeaders(ResponseInterface $response): bool
    {
        // Handle multiple vary fields and comma-separated, case-insensitive names.
        foreach ($response->getHeader('Vary') as $line) {
            foreach (explode(',', $line) as $header) {
                // Normalize each field independently of its casing and surrounding whitespace.
                $header = strtolower(trim($header));

                // Reject variants that the remembered request fingerprint cannot distinguish.
                if ($header === '*' || in_array($header, $this->settings->ignoredHeaders, true)) {
                    return true;
                }
            }
        }

        // Keep caching available when every declared field remains part of the fingerprint.
        return false;
    }

    /**
     * Send a live request and store its response only when it succeeds.
     *
     * @param  Handler  $handler  The next handler in the outgoing request stack.
     * @param  RequestInterface  $request  The prepared outgoing request.
     * @param  array<string, mixed>  $options  The Guzzle transfer options.
     * @param  Repository  $cache  The selected Laravel cache repository.
     * @param  string  $key  The generated response cache key.
     * @param  HttpRememberResponse|null  $generation  The response to replace, or null for a cache miss.
     * @param  HttpRememberGroup|null  $group  The group generation observed before the transfer.
     * @return PromiseInterface The original response with cache persistence attached.
     */
    private function sendAndRemember(
        callable $handler,
        RequestInterface $request,
        array $options,
        Repository $cache,
        string $key,
        ?HttpRememberResponse $generation = null,
        ?HttpRememberGroup $group = null,
    ): PromiseInterface {
        // Leave rejected promises and Laravel's foreground error handling unchanged.
        return $handler($request, $options)->then(
            /**
             * Store a successful response without turning cache failures into HTTP failures.
             *
             * @param  ResponseInterface  $response  The live upstream response.
             * @return ResponseInterface The unchanged upstream response.
             */
            function (ResponseInterface $response) use ($cache, $key, $generation, $group): ResponseInterface {
                // Keep response capture and cache persistence optional for the caller.
                try {
                    // Preserve declared response variants that the filtered key cannot distinguish.
                    if ($this->variesOnIgnoredHeaders($response)) {
                        return $response;
                    }

                    // Buffer first so a slow stream cannot invalidate the generation check.
                    $cached = HttpRememberResponse::capture($response);

                    // Persist only serializable successful responses.
                    if ($cached === null) {
                        return $response;
                    }

                    // Coordinate foreground and deferred writes without locking network work.
                    $remember =
                        /**
                         * Replace only the generation this request was allowed to populate.
                         *
                         * @return void
                         */
                        function () use ($cache, $key, $generation, $cached, $group): void {
                            // Cancel writes started before a successful mutation or metadata removal.
                            if ($group !== null && ! $group->isCurrent()) {
                                return;
                            }

                            // Recheck after buffering and acquiring any available write lock.
                            $current = HttpRememberResponse::restore($cache->get($key));

                            // Treat hard-expired entries as misses even before backend eviction.
                            if ($generation === null && $current?->hasReached($this->settings->lifetime)) {
                                $current = null;
                            }

                            // Preserve replacements and cancel refreshes whose generation was removed.
                            if ($current?->id() !== $generation?->id()) {
                                return;
                            }

                            // Retain stale-policy generations through the bounded refresh window.
                            $retention = $this->settings->lifetime;
                            if ($this->settings->fresh < $this->settings->lifetime) {
                                $retention += $this->settings->refreshTimeout + self::REFRESH_LOCK_BUFFER_SECONDS;
                            }

                            // Report stores that reject a write without throwing an exception.
                            if (! $cache->put($key, $cached->toArray(), $retention)) {
                                $this->logFailure('write', $key);
                            }
                        };

                    // Check whether the selected store can coordinate concurrent writes.
                    $store = $cache->getStore();

                    // Keep the generation check and persistence together without waiting for a lock.
                    if ($store instanceof LockProvider) {
                        $store->lock($key.':write', self::WRITE_LOCK_SECONDS)->get($remember);
                    } else {
                        // Preserve caching on stores that cannot coordinate separate workers.
                        $remember();
                    }
                } catch (Throwable $exception) {
                    // Preserve the response when capturing or storing it fails.
                    $this->logFailure('write', $key, ['exception' => $exception::class]);
                }

                // Preserve the caller's normal response even if caching was unavailable.
                return $response;
            },
        );
    }

    /**
     * Schedule at most one refresh per key and store in the current Laravel lifecycle.
     *
     * @param  Handler  $handler  The next handler in the outgoing request stack.
     * @param  RequestInterface  $request  The prepared outgoing request to replay.
     * @param  array<string, mixed>  $options  The original Guzzle transfer options.
     * @param  Repository  $cache  The selected Laravel cache repository.
     * @param  string  $key  The generated response cache key.
     * @param  HttpRememberResponse  $generation  The stale response and its original expiry metadata.
     * @param  Closure(): bool  $canCache  The mutation guard bound to the sending builder.
     * @param  HttpRememberGroup|null  $group  The group generation served by the stale response.
     */
    private function refreshLater(
        callable $handler,
        RequestInterface $request,
        array $options,
        Repository $cache,
        string $key,
        HttpRememberResponse $generation,
        Closure $canCache,
        ?HttpRememberGroup $group,
    ): void {
        // Retain the original stream while preparing an independent deferred request.
        $body = $request->getBody();

        // Remember the cursor left by request events before reading the complete upload.
        $position = $body->tell();

        // Copy the body without changing the caller's request stream position.
        try {
            // Include bytes already consumed by request logging or other body inspection.
            $body->rewind();

            // Give the deferred request its own replayable upload stream.
            $request = $request->withBody(Utils::streamFor($body->getContents()));
        } finally {
            // Restore the caller's original cursor even when copying the body fails.
            $body->seek($position);
        }

        // Preserve shorter timeouts while bounding every deferred network timeout.
        foreach (['timeout', 'connect_timeout', 'read_timeout'] as $option) {
            // Guzzle uses zero for unlimited timeouts, which must use the configured cap.
            $timeout = (float) ($options[$option] ?? 0);
            $options[$option] = $timeout > 0 ? min($timeout, $this->settings->refreshTimeout) : $this->settings->refreshTimeout;
        }

        // A foreground transfer delay should not delay the deferred refresh too.
        unset($options['delay']);

        // Deduplicate the resolved store even when its default name changes or is explicit.
        $name = 'http-remember:refresh:'.hash('sha256', serialize([
            spl_object_id($cache->getStore()),
            $key,
        ]));

        // Refresh after the lifecycle completes, including unsuccessful requests and jobs.
        defer(
            /**
             * Refresh the stale generation while retaining it on upstream failures.
             *
             * @return void
             */
            function () use ($handler, $request, $options, $cache, $key, $generation, $canCache, $group): void {
                // Contain refresh failures after the caller has received its response.
                try {
                    // Recheck the generation after acquiring any available refresh lock.
                    $refresh =
                        /**
                         * Fetch only if another worker has not replaced the stale entry.
                         *
                         * @return void
                         */
                        function () use ($handler, $request, $options, $cache, $key, $generation, $canCache, $group): void {
                            // Skip expired responses and groups invalidated before deferred execution.
                            if ($generation->hasReached($this->settings->lifetime)
                                || ($group !== null && ! $group->isCurrent())
                                || HttpRememberResponse::restore($cache->get($key))?->id() !== $generation->id()) {
                                return;
                            }

                            // Cancel refreshes when later builder mutations could change the cached request's identity.
                            if (! $canCache()) {
                                return;
                            }

                            // Wait only during deferred execution, never while serving stale data.
                            $response = $this->sendAndRemember($handler, $request, $options, $cache, $key, $generation, $group)->wait();

                            // Log unsuccessful refreshes while retaining the original expiry.
                            if ($response->getStatusCode() < HttpStatus::HTTP_OK || $response->getStatusCode() >= HttpStatus::HTTP_MULTIPLE_CHOICES) {
                                $this->logFailure('refresh', $key, ['status' => $response->getStatusCode()]);
                            }
                        };

                    // Check whether the selected store can coordinate concurrent workers.
                    $store = $cache->getStore();

                    // Keep the refresh locked through the network timeout and cache write.
                    if ($store instanceof LockProvider) {
                        $lockSeconds = (int) ceil($options['timeout']) + self::REFRESH_LOCK_BUFFER_SECONDS;
                        $store->lock($key.':refresh', $lockSeconds)->get($refresh);
                    } else {
                        // Run the deferred refresh when the store has no lock support.
                        $refresh();
                    }
                } catch (Throwable $exception) {
                    // Record a safe diagnostic without changing the cached response.
                    $this->logFailure('refresh', $key, ['exception' => $exception::class]);
                }
            },
            $name,
        )->always();
    }

    /**
     * Report a cache problem without exposing URLs, headers, bodies, or exception messages.
     *
     * @param  string  $operation  The cache operation that could not be completed.
     * @param  string|null  $key  The hashed cache key, if one was generated.
     * @param  array<string, int|string>  $context  Safe status or exception-class metadata.
     */
    private function logFailure(string $operation, ?string $key, array $context = []): void
    {
        // Keep diagnostics useful without logging request credentials or response contents.
        try {
            Log::warning('HTTP remember '.$operation.' failed.', ['cache_key' => $key] + $context);
        } catch (Throwable) {
            // A logging outage must not interrupt an otherwise usable HTTP response.
        }
    }
}
