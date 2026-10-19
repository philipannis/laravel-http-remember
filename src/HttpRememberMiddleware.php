<?php

namespace PhilipAnnis\HttpRemember;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionFunction;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

use function Illuminate\Support\defer;

/**
 * Cache reads, defer stale refreshes, and invalidate groups after live mutations.
 *
 * @internal
 *
 * @phpstan-type Handler callable(RequestInterface, array<string, mixed>): PromiseInterface
 * @phpstan-type EventLogger Closure(string, string|null, array<string, int|float|string|null>, bool=): void
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
     * Seconds a synchronous cache miss may wait for another caller's response.
     */
    private const MISS_LOCK_WAIT_SECONDS = 1;

    /**
     * Minimum lease for a foreground fetch when its network timeout is unlimited.
     */
    private const MISS_LOCK_SECONDS = 30;

    /**
     * Create middleware for one immutable request policy.
     *
     * @param  HttpRememberOptions  $settings  The cache lifetimes, store, group, operation intent, eligibility predicate, and ignored headers.
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
                // Retain the selected store for event logs even if the default changes before a refresh.
                $store = $this->settings->store ?? config('cache.default');
                $eventLog = $this->eventLogger($request, $store);

                // Send grouped mutation methods live unless explicitly marked as read operations.
                if ($this->settings->groupHash !== null
                    && $this->settings->operation !== 'read'
                    && ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
                    return $this->sendAndInvalidate($handler, $request, $options, $eventLog);
                }

                // Avoid consuming uploads, streams, or downloads with transfer side effects.
                if (($reason = $this->bypassReason($request, $options, $canCache)) !== null) {
                    $eventLog('bypass', null, ['reason' => $reason]);

                    return $this->sendLive($handler, $request, $options, null, $eventLog);
                }

                // Release an acquired miss lock after cache persistence or any foreground failure.
                $lock = null;
                try {
                    // Keep diagnostics and cache-hit state available when a cache read fails.
                    $key = null;
                    $response = null;
                    $reason = 'missing';
                    $attemptedLock = false;

                    // Treat cache failures as misses without intercepting network exceptions.
                    try {
                        // Resolve a group only when this request explicitly selects one.
                        $cache = Cache::store($this->settings->store);
                        $group = $this->settings->groupHash === null
                            ? null
                            : HttpRememberGroup::resolve($cache, $this->settings->groupHash);

                        // Fingerprint the prepared request and its current invalidation generation.
                        $key = $this->cacheKey($request, $options, $group);

                        // Recheck once after waiting so another worker's response avoids a live fetch.
                        do {
                            $payload = $cache->get($key);
                            $cached = HttpRememberResponse::restore($payload);

                            // Describe misses while enforcing hard expiry and current group generations.
                            if ($cached === null) {
                                $reason = $payload === null ? 'missing' : 'invalid_entry';
                            } elseif ($cached->hasReached($this->settings->lifetime)) {
                                $reason = 'expired';
                            } elseif ($group !== null && ! $group->isCurrent()) {
                                $reason = 'group_invalidated';
                            } else {
                                // Reconstruct the response before inspecting its declared header dependencies.
                                $candidate = $cached->toResponse();

                                // Recheck entries stored before the ignored-header configuration changed.
                                if (! $this->variesOnIgnoredHeaders($candidate)) {
                                    $response = $candidate;
                                } else {
                                    $reason = 'vary';
                                }
                            }

                            // Keep hits, asynchronous requests, and stores without locks free from blocking waits.
                            if ($response !== null || $attemptedLock || ! ($options['synchronous'] ?? false)
                                || ! (($cacheStore = $cache->getStore()) instanceof LockProvider)) {
                                break;
                            }

                            // Bound the wait independently of the lease covering the network request and write.
                            $attemptedLock = true;
                            try {
                                $seconds = max(self::MISS_LOCK_SECONDS, (int) ceil($options['timeout'] ?? 0) + self::WRITE_LOCK_SECONDS);
                                $candidateLock = $cacheStore->lock($key.':miss', $seconds);
                                $candidateLock->block(self::MISS_LOCK_WAIT_SECONDS);
                                $lock = $candidateLock;
                            } catch (LockTimeoutException) {
                                // Make one final cache check before falling back to an independent live request.
                            } catch (Throwable $exception) {
                                // Preserve ordinary HTTP behavior when the cache cannot coordinate misses.
                                $this->logFailure('lock', $key, ['exception' => $exception::class], $eventLog);
                            }
                        } while (true);
                    } catch (Throwable $exception) {
                        // Report the cache failure without exposing the exception message.
                        $this->logFailure('read', $key, ['exception' => $exception::class], $eventLog);
                        $eventLog('bypass', $key, ['reason' => 'cache_read_failed']);

                        // Let the live request retain Laravel's normal error handling.
                        return $this->sendLive($handler, $request, $options, $key, $eventLog);
                    }

                    // Keep application event failures outside cache failure handling.
                    if ($response !== null) {
                        // Identify stale cache hits before scheduling their deferred refresh.
                        $stale = $cached->hasReached($this->settings->fresh);

                        // Restore Laravel's request metadata and events for the cached response.
                        $onHit($request, $options);
                        $eventLog('hit', $key, ['reason' => $stale ? 'stale' : 'fresh', 'age' => $cached->age(), 'status' => $response->getStatusCode()]);

                        // Keep a usable stale response even if refresh scheduling fails.
                        if ($stale) {
                            // Contain scheduling failures while preserving the cache hit.
                            try {
                                // Defer a refresh of the generation served to this caller.
                                $this->refreshLater($handler, $request, $options, $cache, $key, $cached, $canCache, $group, $eventLog);
                            } catch (Throwable $exception) {
                                // Report the scheduling failure using safe exception metadata.
                                $this->logFailure('refresh', $key, ['exception' => $exception::class], $eventLog);
                            }
                        }

                        // Give each cache hit an independent fulfilled response promise.
                        return Create::promiseFor($response);
                    }

                    // Populate an absent or expired entry only after an eligible response.
                    $eventLog('miss', $key, ['reason' => $reason]);
                    $promise = $this->sendAndRemember($handler, $request, $options, $cache, $key, $eventLog, null, $group);

                    // Complete synchronous persistence before releasing the miss lock to another worker.
                    return $lock === null ? $promise : Create::promiseFor($promise->wait());
                } finally {
                    // A release failure must not replace a usable response or the original network exception.
                    if ($lock !== null) {
                        try {
                            $lock->release();
                        } catch (Throwable $exception) {
                            $this->logFailure('lock', $key, ['exception' => $exception::class], $eventLog);
                        }
                    }
                }
            };
    }

    /**
     * Apply the response predicate without exposing the caller's body stream to mutation.
     *
     * @param  ResponseInterface  $response  The live foreground or deferred refresh response.
     * @param  HttpRememberResponse  $cached  The captured body used for independent inspection.
     * @param  string  $key  The hashed response cache key used for safe diagnostics.
     * @param  EventLogger  $eventLog  The event log publisher bound to this transfer's store.
     * @return bool Whether the live response can be stored under the application's cache policy.
     */
    private function allowsResponse(ResponseInterface $response, HttpRememberResponse $cached, string $key, Closure $eventLog): bool
    {
        // Keep ordinary caching free from additional response inspection when no predicate is supplied.
        if ($this->settings->cacheWhen === null) {
            return true;
        }

        // Contain predicate failures while preserving the caller's live response.
        try {
            // Preserve live headers while giving the predicate an independent body stream.
            $copy = $response->withBody(Utils::streamFor($cached->toArray()['body']));
            $eligible = ($this->settings->cacheWhen)(new Response($copy));

            // Require an explicit boolean instead of interpreting accidental truthy return values.
            if (! is_bool($eligible)) {
                throw new UnexpectedValueException('The HTTP remember response eligibility callback must return a boolean.');
            }

            // Let the application narrow the responses accepted by the built-in caching rules.
            return $eligible;
        } catch (Throwable $exception) {
            // Reject caching while preserving the live response and omitting sensitive exception details.
            $this->logFailure('eligibility', $key, ['exception' => $exception::class], $eventLog);

            return false;
        }
    }

    /**
     * Send a grouped mutation and invalidate its group after a non-error response.
     *
     * @param  Handler  $handler  The next handler in the outgoing request stack.
     * @param  RequestInterface  $request  The mutation that must reach the API.
     * @param  array<string, mixed>  $options  The original Guzzle transfer options.
     * @param  EventLogger  $eventLog  The event log publisher bound to this transfer's store.
     * @return PromiseInterface The unchanged live response or transport rejection.
     */
    private function sendAndInvalidate(callable $handler, RequestInterface $request, array $options, Closure $eventLog): PromiseInterface
    {
        // Grouped mutations run live and report their cache bypass explicitly.
        $eventLog('bypass', null, ['reason' => 'mutation']);

        // Bind invalidation to the store selected before the mutation starts.
        try {
            $cache = Cache::store($this->settings->store);
        } catch (Throwable $exception) {
            // Preserve the live mutation when its cache store cannot be resolved.
            $this->logFailure('invalidate', null, ['exception' => $exception::class], $eventLog);

            return $this->sendLive($handler, $request, $options, null, $eventLog);
        }

        // Invalidate without buffering, caching, or deferring the mutation response.
        return $this->sendLive($handler, $request, $options, null, $eventLog)->then(
            /**
             * Rotate the matching group after the upstream operation completes.
             *
             * @param  ResponseInterface  $response  The live upstream response.
             * @return ResponseInterface The original response received by the caller.
             */
            function (ResponseInterface $response) use ($cache, $eventLog): ResponseInterface {
                // Invalidate redirects before their follow-up reads while preserving failed mutations.
                if ($response->getStatusCode() < HttpStatus::HTTP_OK || $response->getStatusCode() >= HttpStatus::HTTP_BAD_REQUEST) {
                    return $response;
                }

                // Keep invalidation failures separate from the completed HTTP operation.
                try {
                    if (! HttpRememberGroup::invalidate($cache, $this->settings->groupHash)) {
                        $this->logFailure('invalidate', null, [], $eventLog);
                    } else {
                        $eventLog('invalidated', null, ['status' => $response->getStatusCode()]);
                    }
                } catch (Throwable $exception) {
                    // Report failures without exposing request or group values.
                    $this->logFailure('invalidate', null, ['exception' => $exception::class], $eventLog);
                }

                // Preserve Laravel's normal handling of the live mutation response.
                return $response;
            },
        );
    }

    /**
     * Describe why a transfer is unsuitable for response caching.
     *
     * @param  RequestInterface  $request  The prepared outgoing request.
     * @param  array<string, mixed>  $options  The Guzzle transfer options.
     * @param  Closure(): bool  $canCache  The mutation guard bound to the sending builder.
     * @return string|null The bypass reason, or null when caching can proceed.
     */
    private function bypassReason(RequestInterface $request, array $options, Closure $canCache): ?string
    {
        // Avoid caching an identity that a later middleware or callback might change.
        if (! $canCache()) {
            return 'request_hooks';
        }

        // Preserve Guzzle's header and trailer callbacks and their normal rejection handling.
        if (isset($options['on_headers'])) {
            return 'on_headers';
        }
        if (isset($options['on_trailers'])) {
            return 'on_trailers';
        }

        // Preserve streaming, file downloads, multipart uploads, and custom transport behavior.
        if ($options['stream'] ?? false) {
            return 'stream';
        }
        if (isset($options['sink'])) {
            return 'sink';
        }
        if (! empty($options['curl'])) {
            return 'curl';
        }
        if (! empty($options['stream_context'])) {
            return 'stream_context';
        }
        if (str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'multipart/')) {
            return 'multipart';
        }

        // Inspect the upload stream without consuming its contents.
        $body = $request->getBody();

        // Cache only readable, seekable bodies positioned at the beginning of the stream.
        try {
            return ! $body->isReadable() || ! $body->isSeekable() || $body->tell() !== 0 ? 'request_body' : null;
        } catch (Throwable) {
            // Let the original handler decide how to handle an unusable request stream.
            return 'request_body';
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
     * @param  EventLogger  $eventLog  The event log publisher bound to this transfer's store.
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
        Closure $eventLog,
        ?HttpRememberResponse $generation = null,
        ?HttpRememberGroup $group = null,
    ): PromiseInterface {
        // Report foreground transport failures while deferred errors retain their refresh diagnostics.
        $promise = $generation === null
            ? $this->sendLive($handler, $request, $options, $key, $eventLog)
            : $handler($request, $options);

        // Leave rejected promises and Laravel's foreground error handling unchanged.
        return $promise->then(
            /**
             * Store a successful response without turning cache failures into HTTP failures.
             *
             * @param  ResponseInterface  $response  The live upstream response.
             * @return ResponseInterface The unchanged upstream response.
             */
            function (ResponseInterface $response) use ($cache, $key, $generation, $group, $eventLog): ResponseInterface {
                // Keep every persistence outcome observable after the write lock is released.
                $outcome = 'write_lock_unavailable';

                // Keep response capture and cache persistence optional for the caller.
                try {
                    // Preserve declared response variants that the filtered key cannot distinguish.
                    if ($this->variesOnIgnoredHeaders($response)) {
                        $this->recordStorageOutcome($eventLog, $key, 'vary', $response, $generation);

                        return $response;
                    }

                    // Buffer first so a slow stream cannot invalidate the generation check.
                    $reason = null;
                    $cached = HttpRememberResponse::capture($response, $this->settings->maxResponseBytes, $reason);

                    // Persist only serializable successful responses accepted by the active predicate.
                    if ($cached === null || ! $this->allowsResponse($response, $cached, $key, $eventLog)) {
                        $this->recordStorageOutcome($eventLog, $key, $reason ?? 'response_predicate', $response, $generation);

                        return $response;
                    }

                    // Coordinate foreground and deferred writes without locking network work.
                    $remember =
                        /**
                         * Replace only the generation this request was allowed to populate.
                         *
                         * @return void
                         */
                        function () use ($cache, $key, $generation, $cached, $group, $eventLog, &$outcome): void {
                            // Cancel writes started before a successful mutation or metadata removal.
                            if ($group !== null && ! $group->isCurrent()) {
                                $outcome = 'group_invalidated';

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
                                $outcome = 'generation_changed';

                                return;
                            }

                            // Retain stale-policy generations through the bounded refresh window.
                            $retention = $this->settings->lifetime;
                            if ($this->settings->fresh < $this->settings->lifetime) {
                                $retention += $this->settings->refreshTimeout + self::REFRESH_LOCK_BUFFER_SECONDS;
                            }

                            // Report stores that reject a write without throwing an exception.
                            if (! $cache->put($key, $cached->toArray(), $retention)) {
                                $outcome = 'cache_write_failed';
                                $this->logFailure('write', $key, [], $eventLog);
                            } else {
                                $outcome = 'stored';
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
                    $outcome = 'cache_write_failed';
                    $this->logFailure('write', $key, ['exception' => $exception::class], $eventLog);
                }

                // Publish persistence and refresh results without holding a cache write lock.
                $this->recordStorageOutcome($eventLog, $key, $outcome, $response, $generation);

                // Preserve the caller's normal response even if caching was unavailable.
                return $response;
            },
        );
    }

    /**
     * Publish the final persistence decision and any associated deferred refresh outcome.
     *
     * @param  EventLogger  $eventLog  The event log publisher bound to this transfer's store.
     * @param  string  $key  The hashed response cache key.
     * @param  string  $outcome  Stored or the reason persistence did not complete.
     * @param  ResponseInterface  $response  The unchanged live upstream response.
     * @param  HttpRememberResponse|null  $generation  The refreshed generation, or null for foreground work.
     */
    private function recordStorageOutcome(Closure $eventLog, string $key, string $outcome, ResponseInterface $response, ?HttpRememberResponse $generation): void
    {
        // Report every live response's storage decision without exposing its contents.
        $context = ['reason' => $outcome === 'stored' ? null : $outcome, 'status' => $response->getStatusCode()];
        $eventLog($outcome === 'stored' ? 'stored' : 'not_stored', $key, $context);

        // Unsuccessful upstream refreshes are reported through the existing failure path.
        if ($generation === null || $response->getStatusCode() < HttpStatus::HTTP_OK || $response->getStatusCode() >= HttpStatus::HTTP_MULTIPLE_CHOICES) {
            return;
        }

        // Distinguish a stored replacement from a cancelled refresh or failed persistence.
        $event = match ($outcome) {
            'stored' => 'refresh_succeeded',
            'cache_write_failed' => 'refresh_failed',
            default => 'refresh_skipped',
        };
        $eventLog($event, $key, $context);
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
     * @param  EventLogger  $eventLog  The event log publisher bound to this transfer's store.
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
        Closure $eventLog,
    ): void {
        // Scope cooldowns to the original generation so replacements start without old failures.
        $cooldownKey = $key.':refresh-cooldown:'.$generation->id();
        if (($retryAt = $this->refreshRetryAt($cache, $cooldownKey)) !== null) {
            $eventLog('refresh_skipped', $key, ['reason' => 'cooldown', 'retry_at' => $retryAt]);

            return;
        }

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
            function () use ($handler, $request, $options, $cache, $key, $generation, $canCache, $group, $eventLog, $cooldownKey): void {
                // Contain refresh failures after the caller has received its response.
                try {
                    // Recheck the generation after acquiring any available refresh lock.
                    $refresh =
                        /**
                         * Fetch only if another worker has not replaced the stale entry.
                         *
                         * @return void
                         */
                        function () use ($handler, $request, $options, $cache, $key, $generation, $canCache, $group, $eventLog, $cooldownKey): void {
                            // Skip expired responses and groups invalidated before deferred execution.
                            $reason = match (true) {
                                $generation->hasReached($this->settings->lifetime) => 'expired',
                                $group !== null && ! $group->isCurrent() => 'group_invalidated',
                                HttpRememberResponse::restore($cache->get($key))?->id() !== $generation->id() => 'generation_changed',
                                ! $canCache() => 'request_hooks',
                                default => null,
                            };
                            if ($reason !== null) {
                                $eventLog('refresh_skipped', $key, ['reason' => $reason]);

                                return;
                            }

                            // Recheck under the refresh lock in case another worker has just failed.
                            if (($retryAt = $this->refreshRetryAt($cache, $cooldownKey)) !== null) {
                                $eventLog('refresh_skipped', $key, ['reason' => 'cooldown', 'retry_at' => $retryAt]);

                                return;
                            }

                            // Record actual refresh work after every cancellation check passes.
                            $eventLog('refresh_started', $key, ['age' => $generation->age()]);

                            // Wait only during deferred execution, never while serving stale data.
                            try {
                                $response = $this->sendAndRemember($handler, $request, $options, $cache, $key, $eventLog, $generation, $group)->wait();
                            } catch (Throwable $exception) {
                                // Persist the cooldown before releasing the lock without masking the transport error.
                                $this->coolDownRefresh($cache, $key, $cooldownKey, $generation, $eventLog);

                                throw $exception;
                            }

                            // Log unsuccessful refreshes while retaining the original expiry.
                            if ($response->getStatusCode() < HttpStatus::HTTP_OK || $response->getStatusCode() >= HttpStatus::HTTP_MULTIPLE_CHOICES) {
                                $this->coolDownRefresh($cache, $key, $cooldownKey, $generation, $eventLog, $response);
                                $this->logFailure('refresh', $key, ['status' => $response->getStatusCode()], $eventLog);
                            }
                        };

                    // Check whether the selected store can coordinate concurrent workers.
                    $store = $cache->getStore();

                    // Keep the refresh locked through the network timeout and cache write.
                    if ($store instanceof LockProvider) {
                        $lockSeconds = (int) ceil($options['timeout']) + self::REFRESH_LOCK_BUFFER_SECONDS;
                        if ($store->lock($key.':refresh', $lockSeconds)->get($refresh) === false) {
                            $eventLog('refresh_skipped', $key, ['reason' => 'refresh_lock_unavailable']);
                        }
                    } else {
                        // Run the deferred refresh when the store has no lock support.
                        $refresh();
                    }
                } catch (Throwable $exception) {
                    // Record a safe diagnostic without changing the cached response.
                    $this->logFailure('refresh', $key, ['exception' => $exception::class], $eventLog);
                }
            },
            $name,
        )->always();

        // Each stale hit requests scheduling; Laravel deduplicates execution by callback name.
        $eventLog('refresh_scheduled', $key, ['age' => $generation->age()]);
    }

    /**
     * Read an active cooldown without relying on the store's eviction timing.
     *
     * @param  Repository  $cache  The response's selected cache repository.
     * @param  string  $cooldownKey  The cooldown key for this response generation.
     * @return float|null The next permitted refresh timestamp, or null when no cooldown applies.
     */
    private function refreshRetryAt(Repository $cache, string $cooldownKey): ?float
    {
        // Treat malformed or elapsed metadata as permission to try refreshing again.
        $retryAt = $cache->get($cooldownKey);

        return (is_int($retryAt) || is_float($retryAt)) && is_finite($retryAt)
            && $retryAt > Carbon::now()->getPreciseTimestamp() / 1000000 ? (float) $retryAt : null;
    }

    /**
     * Retain a failed generation's cooldown without extending its response lifetime.
     *
     * @param  Repository  $cache  The response's selected cache repository.
     * @param  string  $key  The hashed response cache key used for diagnostics.
     * @param  string  $cooldownKey  The cooldown key for this response generation.
     * @param  HttpRememberResponse  $generation  The response whose refresh failed.
     * @param  EventLogger  $eventLog  The publisher bound to the failed refresh.
     * @param  ResponseInterface|null  $response  The failed response, when the transport completed.
     */
    private function coolDownRefresh(Repository $cache, string $key, string $cooldownKey, HttpRememberResponse $generation, Closure $eventLog, ?ResponseInterface $response = null): void
    {
        // A metadata failure must not replace the original refresh outcome.
        try {
            $now = Carbon::now()->getPreciseTimestamp() / 1000000;
            $delay = $this->settings->refreshCooldown;
            $retryAfter = trim($response?->getHeaderLine('Retry-After') ?? '');

            // Accept non-negative integer seconds without overflowing on very large values.
            if (ctype_digit($retryAfter)) {
                $delay = max($delay, (float) $retryAfter);
            } elseif ($retryAfter !== '') {
                // Support HTTP dates, including the two obsolete formats accepted by HTTP recipients.
                foreach ([DATE_RFC7231, 'l, d-M-y H:i:s \\G\\M\\T', 'D M j H:i:s Y'] as $format) {
                    $date = DateTimeImmutable::createFromFormat('!'.$format, $retryAfter, new DateTimeZone('UTC'));
                    if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                        $delay = max($delay, $date->getTimestamp() - $now);

                        break;
                    }
                }
            }

            // Cap the deadline at hard expiry even when the server requests a longer delay.
            $delay = min($delay, $this->settings->lifetime - $generation->age());
            if ($delay <= 0) {
                return;
            }

            // Store only a deadline, leaving the response and its original expiry untouched.
            if (! $cache->put($cooldownKey, $now + $delay, (int) ceil($delay))) {
                throw new RuntimeException('The HTTP remember refresh cooldown could not be stored.');
            }
        } catch (Throwable $exception) {
            $this->logFailure('cooldown', $key, ['exception' => $exception::class], $eventLog);
        }
    }

    /**
     * Send a live transfer and report transport failures without replacing their rejection.
     *
     * @param  Handler  $handler  The next handler in the outgoing request stack.
     * @param  RequestInterface  $request  The prepared live request.
     * @param  array<string, mixed>  $options  The unchanged Guzzle transfer options.
     * @param  string|null  $key  The hashed cache key, if one was generated.
     * @param  EventLogger  $eventLog  The event log publisher bound to this transfer's store.
     * @return PromiseInterface The unchanged live response or transport rejection.
     */
    private function sendLive(callable $handler, RequestInterface $request, array $options, ?string $key, Closure $eventLog): PromiseInterface
    {
        // Preserve immediate transport exceptions while reporting their safe class metadata.
        try {
            $promise = $handler($request, $options);
        } catch (Throwable $exception) {
            $eventLog('failed', $key, ['operation' => 'request', 'exception' => $exception::class]);

            throw $exception;
        }

        // Leave fulfilled responses untouched and retain the usual Guzzle rejection path.
        return $promise->then(
            null,
            /**
             * Report transport rejections without replacing their reason.
             *
             * @param  mixed  $reason  The original rejected promise reason.
             * @return PromiseInterface The unchanged transport rejection.
             */
            static function (mixed $reason) use ($key, $eventLog): PromiseInterface {
                $eventLog('failed', $key, ['operation' => 'request', 'exception' => $reason instanceof Throwable ? $reason::class : null]);

                return Create::rejectionFor($reason);
            },
        );
    }

    /**
     * Bind event logs to one transfer's request message, selected store, and invalidation group.
     *
     * @param  RequestInterface  $request  The prepared outgoing request.
     * @param  string|null  $store  The cache store selected before the transfer starts.
     * @return EventLogger The publisher reused by the transfer and its deferred refresh.
     */
    private function eventLogger(RequestInterface $request, ?string $store): Closure
    {
        // Capture immutable identifiers instead of reading mutable defaults during deferred work.
        $groupHash = $this->settings->groupHash;

        // Show the method, shortened hostname, and path without credentials, queries, or fragments.
        $uri = $request->getUri();
        $hostname = strtolower($uri->getHost());
        $hostname = str_starts_with($hostname, 'www.') ? substr($hostname, 4) : $hostname;
        $path = $uri->getPath();
        $message = '['.strtoupper($request->getMethod()).'] '.$hostname.($path === '' ? '/' : $path);

        return
            /**
             * Publish a cache decision using only the supported safe diagnostic fields.
             *
             * @param  string  $event  The decision or lifecycle outcome.
             * @param  string|null  $key  The hashed response key, when available.
             * @param  array<string, int|float|string|null>  $context  Safe outcome metadata.
             * @param  bool  $warning  Whether this failure must remain visible when routine logs are disabled.
             */
            static function (string $event, ?string $key, array $context = [], bool $warning = false) use ($store, $groupHash, $message): void {
                (new HttpRememberEventLog(
                    $event,
                    $key,
                    $store,
                    $groupHash,
                    $context['reason'] ?? null,
                    $context['age'] ?? null,
                    $context['status'] ?? null,
                    $context['operation'] ?? null,
                    $context['exception'] ?? null,
                    $context['retry_at'] ?? null,
                ))->publish($message, $warning);
            };
    }

    /**
     * Report a cache problem without exposing credentials, queries, headers, bodies, or exception messages.
     *
     * @param  string  $operation  The cache operation that could not be completed.
     * @param  string|null  $key  The hashed cache key, if one was generated.
     * @param  array<string, int|string>  $context  Safe status or exception-class metadata.
     * @param  EventLogger  $eventLog  The event log publisher bound to this transfer's store.
     */
    private function logFailure(string $operation, ?string $key, array $context, Closure $eventLog): void
    {
        // Keep the request message and complete event context on failure warnings without duplicating records.
        $eventLog($operation === 'refresh' ? 'refresh_failed' : 'failed', $key, ['operation' => $operation] + $context, true);
    }
}
