<?php

namespace PhilipAnnis\HttpRemember;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Cache successful outgoing responses and defer refreshes of stale entries.
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
     * Create middleware for one immutable request policy.
     *
     * @param  HttpRememberOptions  $settings  The cache lifetimes, store, and refresh limit.
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
        // Keep the original promise contract on both cache hits and network requests.
        return
            /**
             * Resolve a cached response or invoke the original HTTP handler.
             *
             * @param  RequestInterface  $request  The prepared outgoing request.
             * @param  array<string, mixed>  $options  The Guzzle transfer options.
             * @return PromiseInterface The cached or live response promise.
             */
            function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                // Avoid consuming uploads, streams, or downloads with transfer side effects.
                if ($this->shouldBypassCache($request, $options)) {
                    return $handler($request, $options);
                }

                // Keep diagnostics and cache-hit state available when a cache read fails.
                $key = null;
                $response = null;

                // Treat cache failures as misses without intercepting network exceptions.
                try {
                    $key = $this->cacheKey($request, $options);
                    $cache = Cache::store($this->settings->store);
                    $cached = HttpRememberResponse::restore($cache->get($key));

                    // Enforce hard expiry even if a store has not evicted its entry yet.
                    if ($cached !== null && ! $cached->hasReached($this->settings->lifetime)) {
                        $response = $cached->toResponse();
                    }
                } catch (Throwable $exception) {
                    // Report the cache failure without exposing the exception message.
                    $this->logFailure('read', $key, ['exception' => $exception::class]);

                    // Let the live request retain Laravel's normal error handling.
                    return $handler($request, $options);
                }

                // Keep application event failures outside cache failure handling.
                if ($response !== null) {
                    ($this->onHit)($request, $options);

                    // Keep a usable stale response even if refresh scheduling fails.
                    if ($cached->hasReached($this->settings->fresh)) {
                        try {
                            $this->refreshLater($handler, $request, $options, $cache, $key, $cached->id());
                        } catch (Throwable $exception) {
                            $this->logFailure('refresh', $key, ['exception' => $exception::class]);
                        }
                    }

                    // Give each cache hit an independent fulfilled response promise.
                    return Create::promiseFor($response);
                }

                // Populate an absent or expired entry only after a successful response.
                return $this->sendAndRemember($handler, $request, $options, $cache, $key);
            };
    }

    /**
     * Determine whether a transfer is unsuitable for response caching.
     *
     * @param  RequestInterface  $request  The prepared outgoing request.
     * @param  array<string, mixed>  $options  The Guzzle transfer options.
     * @return bool Whether the request must pass through without caching.
     */
    private function shouldBypassCache(RequestInterface $request, array $options): bool
    {
        // Avoid caching an identity that a later middleware or callback might change.
        if (! ($this->canCache)()) {
            return true;
        }

        // Preserve streaming, file downloads, multipart uploads, and custom cURL behavior.
        if (($options['stream'] ?? false) || isset($options['sink']) || ! empty($options['curl'])
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
     * @return string A namespaced SHA-256 key for this request and lifetime policy.
     *
     * @throws RuntimeException When the request body cannot be fingerprinted.
     */
    private function cacheKey(RequestInterface $request, array $options): string
    {
        // Normalize header names and ordering while preserving all header values.
        $headers = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($headers);

        // Restore the request stream even when hashing an unusual stream fails.
        try {
            $bodyHash = Utils::hash($request->getBody(), 'sha256');
        } finally {
            $request->getBody()->rewind();
        }

        // Separate payloads, credentials, transport variants, and per-request lifetimes.
        return self::CACHE_KEY_PREFIX.hash('sha256', serialize([
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
        ]));
    }

    /**
     * Send a live request and store its response only when it succeeds.
     *
     * @param  Handler  $handler  The next handler in the outgoing request stack.
     * @param  RequestInterface  $request  The prepared outgoing request.
     * @param  array<string, mixed>  $options  The Guzzle transfer options.
     * @param  Repository  $cache  The selected Laravel cache repository.
     * @param  string  $key  The generated response cache key.
     * @param  string|null  $generation  A generation to preserve during a deferred refresh.
     * @return PromiseInterface The original response with cache persistence attached.
     */
    private function sendAndRemember(
        callable $handler,
        RequestInterface $request,
        array $options,
        Repository $cache,
        string $key,
        ?string $generation = null,
    ): PromiseInterface {
        // Leave rejected promises and Laravel's foreground error handling unchanged.
        return $handler($request, $options)->then(
            /**
             * Store a successful response without turning cache failures into HTTP failures.
             *
             * @param  ResponseInterface  $response  The live upstream response.
             * @return ResponseInterface The unchanged upstream response.
             */
            function (ResponseInterface $response) use ($cache, $key, $generation): ResponseInterface {
                // Keep response capture and cache persistence optional for the caller.
                try {
                    // Skip stale writes when the observed entry was replaced or invalidated.
                    if ($generation !== null && HttpRememberResponse::restore($cache->get($key))?->id() !== $generation) {
                        return $response;
                    }

                    // Persist only serializable successful responses for the full lifetime.
                    $cached = HttpRememberResponse::capture($response);

                    // Report stores that reject a write without throwing an exception.
                    if ($cached !== null && ! $cache->put($key, $cached->toArray(), $this->settings->lifetime)) {
                        $this->logFailure('write', $key);
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
     * @param  string  $generation  The stale entry's generation identifier.
     */
    private function refreshLater(
        callable $handler,
        RequestInterface $request,
        array $options,
        Repository $cache,
        string $key,
        string $generation,
    ): void {
        // Retain the original stream while preparing an independent deferred request.
        $body = $request->getBody();

        // Copy the body without changing the caller's request stream position.
        try {
            $request = $request->withBody(Utils::streamFor($body->getContents()));
        } finally {
            $body->rewind();
        }

        // Preserve shorter timeouts while bounding every deferred network timeout.
        foreach (['timeout', 'connect_timeout', 'read_timeout'] as $option) {
            // Guzzle uses zero for unlimited timeouts, which must use the configured cap.
            $timeout = (float) ($options[$option] ?? 0);
            $options[$option] = $timeout > 0 ? min($timeout, $this->settings->refreshTimeout) : $this->settings->refreshTimeout;
        }

        // A foreground transfer delay should not delay the deferred refresh too.
        unset($options['delay']);

        // Include the selected store and its namespace in deferred deduplication.
        $name = 'http-remember:refresh:'.hash('sha256', serialize([
            $this->settings->store,
            $cache->getStore()->getPrefix(),
            $key,
        ]));

        // Refresh after the lifecycle completes, including unsuccessful requests and jobs.
        defer(
            /**
             * Refresh the stale generation while retaining it on upstream failures.
             *
             * @return void
             */
            function () use ($handler, $request, $options, $cache, $key, $generation): void {
                // Contain refresh failures after the caller has received its response.
                try {
                    // Recheck the generation after acquiring any available refresh lock.
                    $refresh =
                        /**
                         * Fetch only if another worker has not replaced the stale entry.
                         *
                         * @return void
                         */
                        function () use ($handler, $request, $options, $cache, $key, $generation): void {
                            // Avoid repeating work after another worker replaces the entry.
                            if (HttpRememberResponse::restore($cache->get($key))?->id() !== $generation) {
                                return;
                            }

                            // Wait only during deferred execution, never while serving stale data.
                            $response = $this->sendAndRemember($handler, $request, $options, $cache, $key, $generation)->wait();

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
