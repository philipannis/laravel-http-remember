<?php

namespace PhilipAnnis\HttpRemember\Tests\Unit;

use InvalidArgumentException;
use PhilipAnnis\HttpRemember\HttpRememberOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Verify immutable HTTP cache policy validation.
 */
final class OptionsTest extends TestCase
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
        self::assertNull($options->operation);
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

    /**
     * Confirm unsupported operation values cannot create a request policy.
     *
     * @param  mixed  $operation  The unsupported operation value.
     * @param  class-string<\Throwable>  $exception  The expected validation or type error.
     */
    #[DataProvider('invalidOperations')]
    public function test_invalid_operation_is_rejected(mixed $operation, string $exception): void
    {
        // Reject unsupported values before a request policy can reach the middleware.
        $this->expectException($exception);

        // Attempt to select an invalid operation using an otherwise valid cache policy.
        new HttpRememberOptions(self::FIXED_LIFETIME_SECONDS, null, self::REFRESH_TIMEOUT_SECONDS, operation: $operation);
    }

    /**
     * Provide operation values outside the supported read override.
     *
     * @return iterable<string, array{mixed, class-string<\Throwable>}> Invalid operations keyed by purpose.
     */
    public static function invalidOperations(): iterable
    {
        // Reject unknown names without guessing or normalizing the caller's intent.
        yield 'empty name' => ['', InvalidArgumentException::class];
        yield 'removed write name' => ['write', InvalidArgumentException::class];
        yield 'automatic name' => ['auto', InvalidArgumentException::class];
        yield 'method name' => ['POST', InvalidArgumentException::class];
        yield 'uppercase read' => ['READ', InvalidArgumentException::class];
        yield 'padded write' => ['write ', InvalidArgumentException::class];

        // Reject scalar coercions and values incompatible with the string parameter.
        yield 'true' => [true, InvalidArgumentException::class];
        yield 'false' => [false, InvalidArgumentException::class];
        yield 'integer' => [1, InvalidArgumentException::class];
        yield 'array' => [[], TypeError::class];
    }

    /**
     * Confirm group order cannot change the normalized invalidation identity.
     */
    public function test_group_order_is_normalized(): void
    {
        // Select the same group in opposite orders without changing its values.
        $first = new HttpRememberOptions(60, null, 15, ['tenant:42', 'products']);
        $second = new HttpRememberOptions(60, null, 15, ['products', 'tenant:42']);

        // Preserve one sorted list and one hash for both requests.
        self::assertSame(['products', 'tenant:42'], $first->group);
        self::assertSame($first->group, $second->group);
        self::assertSame($first->groupHash, $second->groupHash);
    }

    /**
     * Confirm omission and an explicitly empty group produce the same policy.
     */
    public function test_empty_group_is_the_same_as_omission(): void
    {
        // Compare ordinary remembering with an explicitly selected empty group.
        $omitted = new HttpRememberOptions(60, null, 15);
        $empty = new HttpRememberOptions(60, null, 15, []);

        // Normalize both cases to ordinary remembering without an invalidation identity.
        self::assertNull($omitted->group);
        self::assertNull($omitted->groupHash);
        self::assertSame($omitted->group, $empty->group);
        self::assertSame($omitted->groupHash, $empty->groupHash);
    }

    /**
     * Confirm delimiter characters, duplicate values, case, and whitespace remain distinct.
     */
    public function test_group_values_are_preserved_exactly(): void
    {
        // Select groups that must not alias through joining, trimming, or deduplication.
        $groups = [['a:b', 'c'], ['a', 'b:c'], ['a:b', 'c', 'c'], ['A:b', 'c'], ['a:b ', 'c']];
        $hashes = [];

        // Collect each complete group's identity for comparison.
        foreach ($groups as $group) {
            $hashes[] = (new HttpRememberOptions(60, null, 15, $group))->groupHash;
        }

        // Every different complete list must have its own identity.
        self::assertCount(count($groups), array_unique($hashes));
    }

    /**
     * Confirm malformed group values fail before middleware can be attached.
     *
     * @param  array<mixed>  $group  The invalid group definition.
     */
    #[DataProvider('invalidGroups')]
    public function test_invalid_group_is_rejected(array $group): void
    {
        // Keep invalid identities visible during policy construction.
        $this->expectException(InvalidArgumentException::class);

        // Attempt to select values that cannot form a supported group.
        new HttpRememberOptions(60, null, 15, $group);
    }

    /**
     * Provide group definitions with unsupported keys or values.
     *
     * @return iterable<string, array{array<mixed>}> Invalid groups keyed by purpose.
     */
    public static function invalidGroups(): iterable
    {
        // Require non-empty string values in a positional list.
        yield 'associative list' => [['tenant' => '42']];
        yield 'empty value' => [['']];
        yield 'whitespace value' => [['   ']];
        yield 'integer value' => [[42]];
        yield 'null value' => [[null]];
        yield 'nested group' => [[['products']]];
    }
}
