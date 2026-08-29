<?php

namespace PhilipAnnis\HttpRemember\Tests\Unit;

use InvalidArgumentException;
use PhilipAnnis\HttpRemember\HttpRememberOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify immutable HTTP cache policy validation.
 */
final class HttpRememberOptionsTest extends TestCase
{
    /**
     * The fixed lifetime accepted by the valid-policy test.
     */
    private const FIXED_LIFETIME_SECONDS = 3600;

    /**
     * The fresh threshold accepted by the valid-policy test.
     */
    private const FRESH_SECONDS = 1800;

    /**
     * The deferred refresh timeout accepted by valid policies.
     */
    private const REFRESH_TIMEOUT_SECONDS = 15;

    /**
     * Confirm an integer produces a fixed policy without a stale period.
     */
    public function test_integer_creates_a_fixed_policy(): void
    {
        // Create a fixed policy using the default cache store.
        $options = new HttpRememberOptions(
            self::FIXED_LIFETIME_SECONDS,
            null,
            self::REFRESH_TIMEOUT_SECONDS,
        );

        // Confirm freshness and retention end at the same boundary.
        self::assertSame(self::FIXED_LIFETIME_SECONDS, $options->fresh);
        self::assertSame(self::FIXED_LIFETIME_SECONDS, $options->lifetime);
        self::assertNull($options->store);
        self::assertSame(self::REFRESH_TIMEOUT_SECONDS, $options->refreshTimeout);
    }

    /**
     * Confirm a threshold pair produces a stale-while-revalidate policy.
     */
    public function test_pair_creates_a_stale_while_revalidate_policy(): void
    {
        // Create a threshold policy using a named cache store.
        $options = new HttpRememberOptions(
            [self::FRESH_SECONDS, self::FIXED_LIFETIME_SECONDS],
            'redis',
            self::REFRESH_TIMEOUT_SECONDS,
        );

        // Confirm each validated setting remains available to the middleware.
        self::assertSame(self::FRESH_SECONDS, $options->fresh);
        self::assertSame(self::FIXED_LIFETIME_SECONDS, $options->lifetime);
        self::assertSame('redis', $options->store);
        self::assertSame(self::REFRESH_TIMEOUT_SECONDS, $options->refreshTimeout);
    }

    /**
     * Confirm immediate staleness is accepted when total lifetime is positive.
     */
    public function test_zero_fresh_threshold_is_valid(): void
    {
        // Create a policy that refreshes every remembered response.
        $options = new HttpRememberOptions(
            [0, self::FIXED_LIFETIME_SECONDS],
            null,
            self::REFRESH_TIMEOUT_SECONDS,
        );

        // Confirm zero remains a valid fresh boundary.
        self::assertSame(0, $options->fresh);
        self::assertSame(self::FIXED_LIFETIME_SECONDS, $options->lifetime);
    }

    /**
     * Confirm invalid cache lifetime policies are rejected.
     *
     * @param  int|array<mixed>  $ttl  The invalid policy under test.
     */
    #[DataProvider('invalidLifetimePolicies')]
    public function test_invalid_lifetime_policy_is_rejected(int|array $ttl): void
    {
        // Expect policy construction to fail before middleware is attached.
        $this->expectException(InvalidArgumentException::class);

        // Attempt to create the invalid policy.
        new HttpRememberOptions($ttl, null, self::REFRESH_TIMEOUT_SECONDS);
    }

    /**
     * Provide malformed fixed and threshold policies.
     *
     * @return iterable<string, array{int|array<mixed>}> Invalid policies keyed by purpose.
     */
    public static function invalidLifetimePolicies(): iterable
    {
        // Reject fixed lifetimes that cannot retain a response.
        yield 'zero fixed lifetime' => [0];
        yield 'negative fixed lifetime' => [-1];

        // Reject arrays that are not exactly one positional pair.
        yield 'empty thresholds' => [[]];
        yield 'one threshold' => [[self::FRESH_SECONDS]];
        yield 'three thresholds' => [[0, self::FRESH_SECONDS, self::FIXED_LIFETIME_SECONDS]];
        yield 'associative thresholds' => [['fresh' => self::FRESH_SECONDS, 'lifetime' => self::FIXED_LIFETIME_SECONDS]];

        // Reject thresholds with invalid units or ordering.
        yield 'fractional threshold' => [[1.5, self::FIXED_LIFETIME_SECONDS]];
        yield 'negative fresh threshold' => [[-1, self::FIXED_LIFETIME_SECONDS]];
        yield 'equal thresholds' => [[self::FIXED_LIFETIME_SECONDS, self::FIXED_LIFETIME_SECONDS]];
        yield 'fresh threshold after lifetime' => [[self::FIXED_LIFETIME_SECONDS, self::FRESH_SECONDS]];
    }

    /**
     * Confirm a non-positive deferred refresh timeout is rejected.
     */
    public function test_invalid_refresh_timeout_is_rejected(): void
    {
        // Expect a timeout that cannot bound network work to fail immediately.
        $this->expectException(InvalidArgumentException::class);

        // Attempt to create a policy with no usable refresh timeout.
        new HttpRememberOptions(self::FIXED_LIFETIME_SECONDS, null, 0);
    }

    /**
     * Confirm a blank cache store name is rejected.
     */
    public function test_blank_cache_store_is_rejected(): void
    {
        // Expect configuration whitespace to remain visible as an error.
        $this->expectException(InvalidArgumentException::class);

        // Attempt to create a policy without a usable store name.
        new HttpRememberOptions(self::FIXED_LIFETIME_SECONDS, '   ', self::REFRESH_TIMEOUT_SECONDS);
    }
}
