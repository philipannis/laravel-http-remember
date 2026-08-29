<?php

namespace PhilipAnnis\HttpRemember;

use InvalidArgumentException;

/**
 * Describe an immutable HTTP cache policy using durations in seconds.
 *
 * @internal
 */
final readonly class HttpRememberOptions
{
    /**
     * The age at which a cached response becomes stale.
     */
    public int $fresh;

    /**
     * The total lifetime of a cached response, including its fresh period.
     */
    public int $lifetime;

    /**
     * Create a fixed-expiry or stale-while-revalidate policy.
     *
     * @param  int|array{int, int}  $ttl  A positive lifetime or [fresh, total lifetime].
     * @param  string|null  $store  A configured Laravel cache store, or the default store.
     * @param  int  $refreshTimeout  The maximum deferred network timeout in seconds.
     *
     * @throws InvalidArgumentException When a duration or cache store name is invalid.
     */
    public function __construct(
        int|array $ttl,
        public ?string $store,
        public int $refreshTimeout,
    ) {
        // Require a positive timeout for deferred network work.
        if ($refreshTimeout <= 0) {
            throw new InvalidArgumentException('The HTTP remember refresh timeout must be a positive number of seconds.');
        }

        // Reject empty store names so configuration mistakes remain visible.
        if ($store !== null && trim($store) === '') {
            throw new InvalidArgumentException('The HTTP remember cache store must be a non-empty name or null.');
        }

        // Treat an integer as a lifetime with no stale period.
        if (is_int($ttl)) {
            // Reject zero and negative lifetimes instead of silently disabling caching.
            if ($ttl <= 0) {
                throw new InvalidArgumentException('The HTTP remember lifetime must be a positive number of seconds.');
            }

            // End freshness and retention together when the fixed lifetime expires.
            $this->fresh = $this->lifetime = $ttl;

            // Skip threshold-pair validation for a completed fixed policy.
            return;
        }

        // Accept only a positional pair: fresh seconds followed by total lifetime seconds.
        if (! array_is_list($ttl) || count($ttl) !== 2) {
            throw new InvalidArgumentException('The HTTP remember thresholds must be [fresh seconds, total lifetime seconds].');
        }

        // Name the thresholds before validating their units and order.
        [$fresh, $lifetime] = $ttl;

        // Keep duration units explicit by requiring whole seconds for both thresholds.
        if (! is_int($fresh) || ! is_int($lifetime)) {
            throw new InvalidArgumentException('The HTTP remember thresholds must contain integer seconds.');
        }

        // Allow immediate staleness but require a later, positive hard expiry.
        if ($fresh < 0 || $lifetime <= $fresh) {
            throw new InvalidArgumentException('The HTTP remember fresh threshold must be non-negative and below the total lifetime.');
        }

        // Retain the validated thresholds for request and refresh decisions.
        $this->fresh = $fresh;
        $this->lifetime = $lifetime;
    }
}
