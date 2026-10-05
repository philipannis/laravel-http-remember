<?php

namespace PhilipAnnis\HttpRemember;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Track an invalidation group without scanning keys or requiring cache tags.
 *
 * @internal
 */
final readonly class HttpRememberGroup
{
    /**
     * The namespace used for persistent group generation records.
     */
    private const CACHE_KEY_PREFIX = 'http-remember:group:';

    /**
     * Retain the generation observed before a remembered request starts.
     *
     * @param  Repository  $cache  The selected Laravel cache repository.
     * @param  string  $key  The hashed group generation key.
     * @param  string  $version  The generation used in response cache keys.
     */
    private function __construct(
        private Repository $cache,
        private string $key,
        public string $version,
    ) {}

    /**
     * Resolve the current generation, creating a new one when its record is missing.
     *
     * @param  Repository  $cache  The selected Laravel cache repository.
     * @param  string  $hash  The normalized group identity.
     * @return self The generation used by this request and any deferred refresh.
     *
     * @throws RuntimeException When the store cannot retain a group generation.
     */
    public static function resolve(Repository $cache, string $hash): self
    {
        // Keep group values out of storage keys and diagnostics.
        $key = self::CACHE_KEY_PREFIX.$hash;
        $version = $cache->get($key);

        // A random replacement prevents missing metadata from reviving older responses.
        if (! is_string($version) || $version === '') {
            $version = Str::random();

            // Retain the generation until it is invalidated or removed by the store.
            if (! $cache->forever($key, $version)) {
                throw new RuntimeException('The HTTP remember group generation could not be stored.');
            }
        }

        // Concurrent initialization may discard extra writes but cannot reuse an older generation.
        return new self($cache, $key, $version);
    }

    /**
     * Invalidate every response using exactly the supplied group identity.
     *
     * @param  Repository  $cache  The selected Laravel cache repository.
     * @param  string  $hash  The normalized group identity.
     * @return bool Whether the store accepted the replacement generation.
     */
    public static function invalidate(Repository $cache, string $hash): bool
    {
        // Make old responses unreachable while allowing their normal lifetimes to expire.
        return $cache->forever(self::CACHE_KEY_PREFIX.$hash, Str::random());
    }

    /**
     * Determine whether a mutation or metadata removal has invalidated this request.
     *
     * @return bool Whether this generation can still be read or populated.
     */
    public function isCurrent(): bool
    {
        // Require the exact generation instead of treating missing metadata as natural expiry.
        return $this->cache->get($this->key) === $this->version;
    }
}
