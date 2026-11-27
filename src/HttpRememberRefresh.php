<?php

namespace PhilipAnnis\HttpRemember;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallback;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Schedule stale refreshes and run their redirect chains within one lock and timeout.
 *
 * @internal
 *
 * @phpstan-import-type Handler from HttpRememberMiddleware
 * @phpstan-import-type EventLogger from HttpRememberMiddleware
 */
final class HttpRememberRefresh
{
    /**
     * Seconds reserved for response buffering and persistence after the network timeout.
     */
    public const LOCK_BUFFER_SECONDS = 10;

    /**
     * Share request identity and persistence rules with foreground transfers.
     *
     * @param  HttpRememberOptions  $settings  The policy captured when remember was called.
     * @param  Closure  $bypassReason  The middleware's request eligibility check.
     * @param  Closure  $cacheKey  The middleware's credential-sensitive fingerprint.
     * @param  Closure  $sendAndRemember  The middleware's guarded persistence path.
     * @param  Closure  $eventLogger  The middleware's publisher factory for redirect destinations.
     * @param  Closure  $logFailure  The middleware's safe failure publisher.
     */
    public function __construct(
        private readonly HttpRememberOptions $settings,
        private readonly Closure $bypassReason,
        private readonly Closure $cacheKey,
        private readonly Closure $sendAndRemember,
        private readonly Closure $eventLogger,
        private readonly Closure $logFailure,
    ) {}

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
     * @param  string|null  $storeName  The store name captured before the stale request starts.
     */
    public function schedule(
        callable $handler,
        RequestInterface $request,
        array $options,
        Repository $cache,
        string $key,
        HttpRememberResponse $generation,
        Closure $canCache,
        ?HttpRememberGroup $group,
        Closure $eventLog,
        ?string $storeName,
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

            // Copy bounded chunks into a temporary stream that can spill larger uploads to disk.
            $copy = Utils::streamFor();
            Utils::copyToStream($body, $copy, $this->settings->maxRequestBytes);

            // Reject incomplete temporary writes instead of replaying a truncated upload.
            if ($copy->tell() !== $body->tell()) {
                throw new RuntimeException('The HTTP remember request body could not be copied completely.');
            }

            // Recheck the budget in case request events changed the body after fingerprinting.
            if (! $body->eof() && ($body->read(1) !== '' || ! $body->eof())) {
                $eventLog('refresh_skipped', $key, ['reason' => 'request_size']);

                return;
            }

            // Give the deferred request its own complete, replayable upload stream.
            $copy->rewind();
            $request = $request->withBody($copy);
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

        // Start an independent redirect chain without repeating the foreground delay.
        unset($options['delay'], $options['__redirect_count']);

        // Preserve destination cookies without letting deferred redirects mutate the caller's jar.
        if (($options['cookies'] ?? null) instanceof CookieJarInterface) {
            $options['cookies'] = new CookieJar(false, $options['cookies']->toArray());
        }

        // Deduplicate the resolved store even when its default name changes or is explicit.
        $name = 'http-remember:refresh:'.hash('sha256', serialize([
            spl_object_id($cache->getStore()),
            $key,
        ]));

        // Replace the named queue slot immediately without reindexing unrelated deferred work.
        defer()[$name] = new DeferredCallback(
            /**
             * Refresh the stale generation while retaining it on upstream failures.
             *
             * @return void
             */
            function () use ($handler, $request, $options, $cache, $key, $generation, $canCache, $group, $eventLog, $cooldownKey, $storeName): void {
                // Contain refresh failures after the caller has received its response.
                try {
                    // Recheck the generation after acquiring any available refresh lock.
                    $refresh =
                        /**
                         * Fetch only if another worker has not replaced the stale entry.
                         *
                         * @return void
                         */
                        function () use ($handler, $request, $options, $cache, $key, $generation, $canCache, $group, $eventLog, $cooldownKey, $storeName): void {
                            // Skip expired responses and groups invalidated before deferred execution.
                            $reason = match (true) {
                                $generation->hasReached($generation->lifetimeFor($this->settings->lifetime)) => 'expired',
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
                                $response = $this->sendRefresh($handler, $request, $options, $cache, $key, $generation, $group, $canCache, $eventLog, $storeName);
                            } catch (Throwable $exception) {
                                // Persist the cooldown before releasing the lock without masking the transport error.
                                $this->coolDownRefresh($cache, $key, $cooldownKey, $generation, $eventLog);

                                throw $exception;
                            }

                            // Log unsuccessful refreshes while retaining the original expiry.
                            if ($response->getStatusCode() < HttpStatus::HTTP_OK || $response->getStatusCode() >= HttpStatus::HTTP_MULTIPLE_CHOICES) {
                                $this->coolDownRefresh($cache, $key, $cooldownKey, $generation, $eventLog, $response);
                                ($this->logFailure)('refresh', $key, ['status' => $response->getStatusCode()], $eventLog);
                            }
                        };

                    // Check whether the selected store can coordinate concurrent workers.
                    $store = $cache->getStore();

                    // Keep the refresh locked through the network timeout and cache write.
                    if ($store instanceof LockProvider) {
                        $lockSeconds = (int) ceil($options['timeout']) + self::LOCK_BUFFER_SECONDS;
                        if ($store->lock($key.':refresh', $lockSeconds)->get($refresh) === false) {
                            $eventLog('refresh_skipped', $key, ['reason' => 'refresh_lock_unavailable']);
                        }
                    } else {
                        // Run the deferred refresh when the store has no lock support.
                        $refresh();
                    }
                } catch (Throwable $exception) {
                    // Record a safe diagnostic without changing the cached response.
                    ($this->logFailure)('refresh', $key, ['exception' => $exception::class], $eventLog);
                }
            },
            $name,
            always: true,
        );

        // Keep every eligible stale hit observable while pending refresh copies remain bounded.
        $eventLog('refresh_scheduled', $key, ['age' => $generation->age()]);
    }

    /**
     * Follow deferred redirects while keeping destination credentials and cache entries separate.
     *
     * @param  Handler  $handler  The original handler below Guzzle's redirect and cookie middleware.
     * @param  RequestInterface  $request  The independent prepared request being refreshed.
     * @param  array<string, mixed>  $options  The bounded transfer options and isolated cookie jar.
     * @param  Repository  $cache  The cache repository selected by the stale caller.
     * @param  string  $key  The source response's cache key.
     * @param  HttpRememberResponse  $generation  The source generation allowed to complete this refresh.
     * @param  HttpRememberGroup|null  $group  The group generation served by the stale response.
     * @param  Closure(): bool  $canCache  The mutation guard bound to the sending builder.
     * @param  EventLogger  $eventLog  The publisher bound to the source request.
     * @param  string|null  $storeName  The store name captured before the stale request starts.
     * @return ResponseInterface The final response after applying the caller's redirect policy.
     */
    private function sendRefresh(
        callable $handler,
        RequestInterface $request,
        array $options,
        Repository $cache,
        string $key,
        HttpRememberResponse $generation,
        ?HttpRememberGroup $group,
        Closure $canCache,
        Closure $eventLog,
        ?string $storeName,
    ): ResponseInterface {
        // Keep the entire chain inside the timeout covered by the existing refresh lock.
        $deadline = hrtime(true) / 1_000_000_000 + $options['timeout'];

        // Track the final destination and storage decision across sequential redirect hops.
        $first = true;
        $destinationKey = $key;
        $outcome = null;

        // Reuse the source generation check before destination writes and source removal.
        $refreshSourceIsCurrent =
            /**
             * Cancel destination writes when the source response was removed or replaced.
             *
             * @return bool Whether the original source generation is still present.
             */
            static fn (): bool => HttpRememberResponse::restore($cache->get($key))?->id() === $generation->id();

        // Bind each transfer to the original cache store, group, and refresh deadline.
        $send =
            /**
             * Fetch each hop live and retain successful responses under their own request identity.
             *
             * @param  RequestInterface  $nextRequest  The request after redirect and cookie preparation.
             * @param  array<string, mixed>  $nextOptions  The caller's options for this hop.
             * @return PromiseInterface The unchanged upstream response with optional persistence.
             */
            function (RequestInterface $nextRequest, array $nextOptions) use ($handler, $cache, $key, $generation, $group, $canCache, $refreshSourceIsCurrent, $eventLog, $storeName, $deadline, &$first, &$destinationKey, &$outcome): PromiseInterface {
                // Preserve the first hop's exact timeout and spend only its remainder on redirects.
                $firstHop = $first;
                $first = false;
                if (! $firstHop) {
                    // Include time spent in earlier transfers and redirect callbacks.
                    $remaining = $deadline - hrtime(true) / 1_000_000_000;

                    // Stop before starting another transfer once the shared budget is exhausted.
                    if ($remaining <= 0) {
                        throw new ConnectException('The HTTP remember refresh timeout was exhausted during redirects.', $nextRequest);
                    }

                    // Preserve shorter limits while capping every timeout at the remaining budget.
                    foreach (['timeout', 'connect_timeout', 'read_timeout'] as $option) {
                        $nextOptions[$option] = min($nextOptions[$option], $remaining);
                    }
                }

                // Recheck hooks and request bodies that a redirect callback may have changed.
                $bodyHash = '';
                if (($reason = ($this->bypassReason)($nextRequest, $nextOptions, $canCache, $bodyHash)) !== null) {
                    // Keep bypassed destinations from retiring the source cache entry.
                    $destinationKey = null;
                    $outcome = $reason;
                    $eventLog('bypass', null, ['reason' => $reason]);

                    // Preserve the live transfer even when this hop cannot be cached.
                    return $handler($nextRequest, $nextOptions);
                }

                // Never cache a destination's session response under the source URL's credentials.
                $destinationKey = ($this->cacheKey)($nextRequest, $nextOptions, $group, $bodyHash);

                // Validate only the first GET when its prepared identity matches the stored representation.
                $transfer = $handler;
                if ($firstHop && $destinationKey === $key && $nextRequest->getMethod() === 'GET'
                    && ($validator = $generation->validator()) !== null) {
                    $transfer = $this->conditionalHandler($handler, $generation, ...$validator);
                }

                // Replace the source generation or populate the destination under the same safeguards.
                return ($this->sendAndRemember)(
                    $transfer,
                    $nextRequest,
                    $nextOptions,
                    $cache,
                    $destinationKey,
                    $destinationKey === $key ? $eventLog : ($this->eventLogger)($nextRequest, $storeName),
                    $destinationKey === $key ? $generation : null,
                    $group,
                    $refreshSourceIsCurrent,
                    $outcome,
                    HttpRememberResponse::restore($cache->get($destinationKey)),
                );
            };

        // Restore Guzzle's standard redirect, cookie, and body preparation around the inner handler.
        $redirect = Middleware::redirect()(Middleware::cookies()(Middleware::prepareBody()($send)));

        // Wait for the entire chain only while the deferred callback is running.
        $response = $redirect($request, $options)->wait();

        // Ordinary refreshes already report their outcome, and failed chains retain the source response.
        if ($destinationKey === $key || $response->getStatusCode() < HttpStatus::HTTP_OK || $response->getStatusCode() >= HttpStatus::HTTP_MULTIPLE_CHOICES) {
            return $response;
        }

        // Retire redirected sources after destination storage or a policy that forbids further cache use.
        if (in_array($outcome, ['stored', 'generation_changed', 'response_cache_control', 'response_cookie', 'response_partial', 'response_expired', 'vary'], true)) {
            // Contain cache removal failures without treating a completed transfer as a network failure.
            try {
                // Recheck the source inside the same write lock used by foreground replacements.
                $forget =
                    /**
                     * Preserve concurrent source replacements and invalidated groups.
                     *
                     * @return bool Whether the original source entry was removed.
                     */
                    static function () use ($cache, $key, $group, $refreshSourceIsCurrent): bool {
                        // Leave newer responses and invalidated groups untouched.
                        if (($group !== null && ! $group->isCurrent()) || ! $refreshSourceIsCurrent()) {
                            return false;
                        }

                        // Report stores that reject removal without throwing an exception.
                        if (! $cache->forget($key)) {
                            throw new RuntimeException('The HTTP remember redirected source could not be removed.');
                        }

                        // Confirm that later source requests must follow the live redirect.
                        return true;
                    };

                // Coordinate source removal without waiting when the selected store supports locks.
                $store = $cache->getStore();
                $forgotten = $store instanceof LockProvider
                    ? $store->lock($key.':write', HttpRememberMiddleware::WRITE_LOCK_SECONDS)->get($forget)
                    : $forget();

                // Publish the source refresh outcome after releasing any write lock.
                $eventLog($forgotten ? 'refresh_succeeded' : 'refresh_skipped', $key, ['reason' => $forgotten ? null : 'generation_changed', 'status' => $response->getStatusCode()]);
            } catch (Throwable $exception) {
                // Keep cache failures separate from the successfully completed redirect chain.
                ($this->logFailure)('refresh', $key, ['exception' => $exception::class], $eventLog);
            }
        } else {
            // Explain why an ineligible or unstored destination left the source entry in place.
            $eventLog('refresh_skipped', $key, ['reason' => $outcome, 'status' => $response->getStatusCode()]);
        }

        // Preserve the final response for the existing refresh failure and cooldown handling.
        return $response;
    }

    /**
     * Revalidate one stored representation through the normal response persistence path.
     *
     * @param  Handler  $handler  The transfer handler below Guzzle's redirect middleware.
     * @param  HttpRememberResponse  $generation  The stored representation being validated.
     * @param  string  $header  The selected conditional request header.
     * @param  string  $value  The validator sent for this representation.
     * @return Handler The transfer with source-only validation attached.
     */
    private function conditionalHandler(callable $handler, HttpRememberResponse $generation, string $header, string $value): Closure
    {
        // Let the cache and redirect layers keep the original request identity.
        return
            /**
             * Add the validator only to the request passed to the transport.
             */
            static function (RequestInterface $request, array $options) use ($handler, $generation, $header, $value): PromiseInterface {
                // Keep the validator below redirect handling so follow-up requests cannot inherit it.
                return $handler($request->withHeader($header, $value), $options)->then(
                    /**
                     * Reconstruct the representation confirmed by a not-modified response.
                     */
                    static function (ResponseInterface $response) use ($generation, $header, $value): ResponseInterface {
                        // Let normal replacements and failures keep their live response.
                        if ($response->getStatusCode() !== HttpStatus::HTTP_NOT_MODIFIED) {
                            return $response;
                        }

                        // Route an unconfirmed 304 to cooldown handling even if it supplied a Location.
                        return $generation->revalidate($response, $header, $value) ?? $response->withoutHeader('Location');
                    },
                );
            };
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
            } elseif ($retryAfter !== '' && ($date = HttpRememberCachePolicy::httpDate($retryAfter)) !== null) {
                // Translate a valid HTTP date to a delay measured from this failure.
                $delay = max($delay, $date - $now);
            }

            // Cap the deadline at hard expiry even when the server requests a longer delay.
            $delay = min($delay, $generation->lifetimeFor($this->settings->lifetime) - $generation->age());
            if ($delay <= 0) {
                return;
            }

            // Store only a deadline, leaving the response and its original expiry untouched.
            if (! $cache->put($cooldownKey, $now + $delay, (int) ceil($delay))) {
                throw new RuntimeException('The HTTP remember refresh cooldown could not be stored.');
            }
        } catch (Throwable $exception) {
            ($this->logFailure)('cooldown', $key, ['exception' => $exception::class], $eventLog);
        }
    }
}
