<?php

namespace PhilipAnnis\HttpRemember;

use Closure;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Describe an immutable HTTP cache policy using durations in seconds.
 *
 * @internal
 */
final readonly class HttpRememberOptions
{
    /**
     * The default maximum cached response body size in bytes.
     */
    public const DEFAULT_MAX_RESPONSE_BYTES = 1_000_000;

    /**
     * The age at which a cached response becomes stale.
     */
    public int $fresh;

    /**
     * The total lifetime of a cached response, including its fresh period.
     */
    public int $lifetime;

    /**
     * The sorted values identifying one invalidation group.
     *
     * @var list<string>|null
     */
    public ?array $group;

    /**
     * The hashed group identity shared across URLs, credentials, and lifetimes.
     */
    public ?string $groupHash;

    /**
     * The optional predicate applied before storing live responses or deferred refreshes.
     *
     * @var (Closure(Response): bool)|null
     */
    public ?Closure $cacheWhen;

    /**
     * The lowercase request header names excluded from cache fingerprints.
     *
     * @var list<string>
     */
    public array $ignoredHeaders;

    /**
     * Create a fixed-expiry or stale-while-revalidate policy.
     *
     * @param  int|array{int, int}  $ttl  A positive lifetime or [fresh, total lifetime].
     * @param  string|null  $store  A configured Laravel cache store, or the default store.
     * @param  int  $refreshTimeout  The maximum deferred network timeout in seconds.
     * @param  list<string>|null  $group  The complete invalidation group; null or an empty list disables grouping.
     * @param  'read'|null  $operation  Read caches any eligible method; null uses automatic detection.
     * @param  (callable(Response): bool)|null  $cacheWhen  An optional response eligibility predicate.
     * @param  list<string>  $ignoredHeaders  Request header names excluded from cache fingerprints.
     * @param  int  $maxResponseBytes  The maximum cached response body size in bytes.
     *
     * @throws InvalidArgumentException When a duration, cache store, group, operation, header name, or response size is invalid.
     */
    public function __construct(
        int|array $ttl,
        public ?string $store,
        public int $refreshTimeout,
        ?array $group = null,
        public ?string $operation = null,
        ?callable $cacheWhen = null,
        array $ignoredHeaders = [],
        public int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
    ) {
        // Keep every supported callable in an immutable closure without rebinding its scope.
        $this->cacheWhen = $cacheWhen === null ? null : Closure::fromCallable($cacheWhen);

        // Require a positive timeout for deferred network work.
        if ($refreshTimeout <= 0) {
            throw new InvalidArgumentException('The HTTP remember refresh timeout must be a positive number of seconds.');
        }

        // Require a bounded response budget instead of silently allowing unlimited buffering.
        if ($maxResponseBytes <= 0) {
            throw new InvalidArgumentException('The HTTP remember maximum response size must be a positive number of bytes.');
        }

        // Reject empty store names so configuration mistakes remain visible.
        if ($store !== null && trim($store) === '') {
            throw new InvalidArgumentException('The HTTP remember cache store must be a non-empty name or null.');
        }

        // Reject malformed configuration before it can change a request's cache identity.
        if (! array_is_list($ignoredHeaders)) {
            throw new InvalidArgumentException('The HTTP remember ignored headers must be a list of valid header names.');
        }

        // Require HTTP field-name tokens instead of silently accepting names that cannot match.
        foreach ($ignoredHeaders as $header) {
            if (! is_string($header) || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $header) !== 1) {
                throw new InvalidArgumentException('The HTTP remember ignored headers must be a list of valid header names.');
            }
        }

        // Match field names case-insensitively without repeatedly normalizing configuration.
        $this->ignoredHeaders = array_values(array_unique(array_map(strtolower(...), $ignoredHeaders)));

        // Reserve null for automatic detection and accept only explicit read intent.
        if ($operation !== null && $operation !== 'read') {
            throw new InvalidArgumentException('The HTTP remember operation must be read when provided.');
        }

        // Treat an empty group as ordinary remembering, just like omission.
        $group = $group === [] ? null : $group;

        // Require a positional list instead of silently discarding group keys.
        if ($group !== null && ! array_is_list($group)) {
            throw new InvalidArgumentException('The HTTP remember group must be a list of non-empty strings.');
        }

        // Preserve exact values while rejecting names that cannot identify a group.
        foreach ($group ?? [] as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('The HTTP remember group must be a list of non-empty strings.');
            }
        }

        // Make ordering irrelevant without joining values with an ambiguous delimiter.
        if ($group !== null) {
            sort($group, SORT_STRING);
        }
        $this->group = $group;
        $this->groupHash = $group === null ? null : hash('sha256', serialize($group));

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
