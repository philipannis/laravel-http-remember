<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use ArrayObject;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Verify direct group invalidation through the existing remember macro.
 */
final class GroupInvalidationTest extends TestCase
{
    /**
     * The resource shared by remembered response variants.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The complete identity shared by related cached reads.
     */
    private const GROUP = ['tenant:42', 'products'];

    /**
     * Confirm immediate invalidation removes access to matching variants without sending an HTTP request.
     */
    public function test_group_invalidation_preserves_unrelated_cached_responses(): void
    {
        // Give every live response a version so cache reuse remains observable.
        $version = 0;
        Http::fake(static function () use (&$version) {
            // Keep the fake independent of URL, credentials, and lifetime variants.
            return Http::response(['version' => ++$version]);
        });
        $events = $this->eventLogs();
        $list = Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $detail = Http::withToken('another-token')->remember(300, group: self::GROUP)->get(self::API_URL.'/42');
        $other = Http::remember(60, group: ['products'])->get(self::API_URL);
        $ungrouped = Http::remember(60)->get(self::API_URL);

        // Sorting the complete group must match the identity captured by remembered reads.
        $pending = Http::remember(group: array_reverse(self::GROUP), invalidate: true);
        self::assertInstanceOf(PendingRequest::class, $pending);
        Http::assertSentCount(4);
        self::assertSame('invalidated', $events[8]->event);
        self::assertSame('invalidate', $events[8]->operation);
        self::assertSame('array', $events[8]->store);
        self::assertNull($events[8]->cacheKey);
        self::assertNull($events[8]->status);

        // Invalidation affects every variant of that exact group while preserving other cache scopes.
        self::assertNotSame($list->json(), Http::remember(60, group: self::GROUP)->get(self::API_URL)->json());
        self::assertNotSame($detail->json(), Http::withToken('another-token')->remember(300, group: self::GROUP)->get(self::API_URL.'/42')->json());
        self::assertSame($other->json(), Http::remember(60, group: ['products'])->get(self::API_URL)->json());
        self::assertSame($ungrouped->json(), Http::remember(60)->get(self::API_URL)->json());
        Http::assertSentCount(6);
    }

    /**
     * Confirm immediate invalidation runs once and leaves the returned builder normally cacheable.
     *
     * @param  bool  $async  Whether the returned builder uses asynchronous requests.
     */
    #[DataProvider('requestModes')]
    public function test_invalidation_is_immediate_and_does_not_repeat_on_reused_builders(bool $async): void
    {
        // A group can be invalidated before any response has been cached.
        $events = $this->eventLogs();
        Http::fakeSequence()->push('initial')->push('replacement');
        $pending = Http::async($async)->remember(60, group: self::GROUP, invalidate: true);
        self::assertSame(['invalidated'], array_column($events->getArrayCopy(), 'event'));
        Http::assertNothingSent();

        // The returned builder keeps the usual lifetime and invalidates only at the explicit macro call.
        $first = $pending->get(self::API_URL);
        $first = $async ? $first->wait() : $first;
        $cached = $pending->get(self::API_URL);
        $cached = $async ? $cached->wait() : $cached;
        self::assertSame('initial', $first->body());
        self::assertSame($first->body(), $cached->body());
        self::assertSame(['invalidated', 'miss', 'stored', 'hit'], array_column($events->getArrayCopy(), 'event'));
        Http::assertSentCount(1);

        // A second explicit invalidation makes the next ordinary read fetch a replacement.
        Http::remember(group: self::GROUP, invalidate: true);
        self::assertSame('replacement', Http::remember(60, group: self::GROUP)->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm direct invalidation follows the configured package store and explicit overrides.
     */
    public function test_invalidation_respects_package_and_explicit_stores(): void
    {
        // Keep identical group identities independent across two cache repositories.
        config(['cache.stores.secondary' => ['driver' => 'array'], 'http-remember.store' => 'secondary']);
        Http::fakeSequence()->push('secondary')->push('array')->push('new-secondary')->push('new-array');
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        Http::remember(60, store: 'array', group: self::GROUP)->get(self::API_URL);

        // An omitted store follows package configuration without invalidating the application default.
        Http::remember(group: self::GROUP, invalidate: true);
        self::assertSame('array', Http::remember(60, store: 'array', group: self::GROUP)->get(self::API_URL)->body());
        self::assertSame('new-secondary', Http::remember(60, group: self::GROUP)->get(self::API_URL)->body());

        // An explicit store override rotates only the matching repository's group generation.
        Http::remember(store: 'array', group: self::GROUP, invalidate: true);
        self::assertSame('new-array', Http::remember(60, store: 'array', group: self::GROUP)->get(self::API_URL)->body());
        self::assertSame('new-secondary', Http::remember(60, group: self::GROUP)->get(self::API_URL)->body());
        Http::assertSentCount(4);
    }

    /**
     * Confirm the default invalidation flag does not rotate a group while configuring a builder.
     */
    public function test_false_invalidation_keeps_the_existing_generation(): void
    {
        // Configuration without an explicit invalidation request keeps cached reads usable.
        Http::fakeSequence()->push('initial');
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $events = $this->eventLogs();
        Http::remember(group: self::GROUP, invalidate: false);
        self::assertCount(0, $events);
        self::assertSame('initial', Http::remember(60, group: self::GROUP)->get(self::API_URL)->body());
        Http::assertSentCount(1);
    }

    /**
     * Confirm immediate invalidation cancels a pending stale refresh before it contacts the API.
     */
    public function test_invalidation_cancels_pending_stale_refreshes(): void
    {
        // Schedule stale work before a local application operation invalidates its group.
        $start = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($start);
        Http::fakeSequence()->push('initial')->push('replacement');
        Http::remember([10, 60], group: self::GROUP)->get(self::API_URL);
        Carbon::setTestNow($start->copy()->addSeconds(10));
        self::assertSame('initial', Http::remember([10, 60], group: self::GROUP)->get(self::API_URL)->body());
        self::assertCount(1, app(DeferredCallbackCollection::class));

        // The queued callback observes the new group generation and cannot repopulate it.
        Http::remember(group: self::GROUP, invalidate: true);
        app(DeferredCallbackCollection::class)->invoke();
        Http::assertSentCount(1);
        self::assertSame('replacement', Http::remember([10, 60], group: self::GROUP)->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm in-flight reads cannot populate a group invalidated before their response arrives.
     *
     * @param  bool  $async  Whether the live read is returned as a promise.
     */
    #[DataProvider('requestModes')]
    public function test_invalidation_cancels_in_flight_read_writes(bool $async): void
    {
        // Invalidate the captured group while the first foreground transfer waits for its response.
        $transfer = null;
        $transfer = new Promise(static function () use (&$transfer): void {
            // Run direct invalidation after the request has selected its original group generation.
            Http::remember(group: self::GROUP, invalidate: true);
            $transfer->resolve(new Response(200, [], 'older'));
        });
        $reads = 0;
        Http::fake(static function () use (&$reads, $transfer): PromiseInterface {
            // Keep a distinct response available for the next read's replacement generation.
            return ++$reads === 1 ? $transfer : Http::response('fresh');
        });
        $older = Http::async($async)->remember(60, group: self::GROUP)->get(self::API_URL);
        $older = $async ? $older->wait() : $older;

        // The older caller retains its live result while subsequent reads cache only the fresh result.
        self::assertSame('older', $older->body());
        self::assertSame('fresh', Http::remember(60, group: self::GROUP)->get(self::API_URL)->body());
        self::assertSame('fresh', Http::remember(60, group: self::GROUP)->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm absent, empty, and malformed groups are rejected before any cache access.
     *
     * @param  array|null  $group  The group that cannot identify an invalidation scope.
     */
    #[DataProvider('invalidGroups')]
    public function test_invalid_groups_do_not_access_the_cache(?array $group): void
    {
        // Invalid input must never turn direct invalidation into a cache-wide flush.
        Cache::shouldReceive('store')->never();
        $this->expectException(InvalidArgumentException::class);
        Http::remember(group: $group, invalidate: true);
    }

    /**
     * Provide groups that cannot identify a complete invalidation scope.
     *
     * @return iterable<string, array{array|null}> Invalid group arguments.
     */
    public static function invalidGroups(): iterable
    {
        // Empty groups remain valid for ordinary remembering but cannot request direct invalidation.
        yield 'missing group' => [null];
        yield 'empty group' => [[]];
        yield 'associative group' => [['name' => 'products']];
        yield 'blank group value' => [['products', ' ']];
        yield 'non-string group value' => [['products', 42]];
    }

    /**
     * Confirm rejected invalidation settings cannot replace a builder's valid existing policy.
     */
    public function test_invalid_invalidation_preserves_the_previous_policy(): void
    {
        // Keep a cached response attached to a builder with a known lifetime and group.
        Http::fakeSequence()->push('initial');
        $pending = Http::remember(60, group: self::GROUP);
        $pending->get(self::API_URL);

        // Reject an empty invalidation scope before replacing the original middleware.
        try {
            $pending->remember(1, group: [], invalidate: true);
            self::fail('The request accepted an empty invalidation group.');
        } catch (InvalidArgumentException) {
            self::assertSame('initial', $pending->get(self::API_URL)->body());
        }
        Http::assertSentCount(1);
    }

    /**
     * Confirm backend failures are logged without replacing the configured request builder.
     *
     * @param  bool  $throw  Whether group persistence throws instead of returning false.
     */
    #[DataProvider('cacheWriteFailures')]
    public function test_invalidation_failures_preserve_the_builder(bool $throw): void
    {
        // Reject the generation write without requiring an external cache service.
        $cache = Mockery::mock(Repository::class);
        $write = $cache->shouldReceive('forever')->once();
        if ($throw) {
            $write->andThrow(new RuntimeException('private invalidation failure'));
        } else {
            $write->andReturnFalse();
        }
        Cache::shouldReceive('store')->once()->with(null)->andReturn($cache);
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('http-remember')->andReturn($logger);
        $logger->shouldReceive('warning')->once();
        $events = $this->eventLogs();

        // Keep direct invalidation diagnostics independent of HTTP requests and sensitive group values.
        $pending = Http::remember(group: self::GROUP, invalidate: true);
        self::assertInstanceOf(PendingRequest::class, $pending);
        self::assertCount(1, $events);
        self::assertSame('failed', $events[0]->event);
        self::assertSame('invalidate', $events[0]->operation);
        self::assertSame('array', $events[0]->store);
        self::assertSame($throw ? RuntimeException::class : null, $events[0]->exception);
        self::assertStringNotContainsString('private', json_encode($events[0]->toArray()));
        self::assertStringNotContainsString('tenant:42', json_encode($events[0]->toArray()));
        Http::assertNothingSent();
    }

    /**
     * Provide thrown and non-throwing cache write failures.
     *
     * @return iterable<string, array{bool}> Invalidation failure modes.
     */
    public static function cacheWriteFailures(): iterable
    {
        // Both failures keep the usual builder return value and safe warning diagnostics.
        yield 'exception' => [true];
        yield 'rejected write' => [false];
    }

    /**
     * Confirm an unavailable store cannot replace the normal fluent macro return value.
     */
    public function test_store_resolution_failures_are_reported(): void
    {
        // Report an unavailable repository before any HTTP request is sent.
        $events = $this->eventLogs();
        $pending = Http::remember(store: 'missing', group: self::GROUP, invalidate: true);
        self::assertInstanceOf(PendingRequest::class, $pending);
        self::assertSame('failed', $events[0]->event);
        self::assertSame('missing', $events[0]->store);
        self::assertNotNull($events[0]->exception);
        Http::assertNothingSent();
    }

    /**
     * Confirm the same API invalidates generations stored by a real persistent file cache.
     */
    public function test_group_invalidation_works_with_the_file_store(): void
    {
        // Give the selected repository a private temporary directory.
        $path = sys_get_temp_dir().'/http-remember-invalidation-'.bin2hex(random_bytes(8));
        config(['cache.stores.invalidation-files' => ['driver' => 'file', 'path' => $path]]);
        Http::fakeSequence()->push('initial')->push('replacement');

        // Chain an ordinary read after invalidation without repeating it on the next send.
        try {
            Http::remember(60, store: 'invalidation-files', group: self::GROUP)->get(self::API_URL);
            $pending = Http::remember(60, store: 'invalidation-files', group: self::GROUP, invalidate: true);
            self::assertSame('replacement', $pending->get(self::API_URL)->body());
            self::assertSame('replacement', $pending->get(self::API_URL)->body());
            Http::assertSentCount(2);
        } finally {
            // Remove only this test's cache files.
            (new Filesystem)->deleteDirectory($path);
        }
    }

    /**
     * Provide immediate and asynchronous public request modes.
     *
     * @return iterable<string, array{bool}> Request modes keyed by their promise behavior.
     */
    public static function requestModes(): iterable
    {
        // Invalidation must happen at the macro call before either request mode starts its transfer.
        yield 'synchronous' => [false];
        yield 'asynchronous' => [true];
    }

    /**
     * Collect immutable cache events without enabling routine log output.
     *
     * @return ArrayObject<int, HttpRememberEventLog> The observed cache events.
     */
    private function eventLogs(): ArrayObject
    {
        // Keep observation independent from Laravel's normal HTTP and cache events.
        $events = new ArrayObject;
        app('events')->listen(HttpRememberEventLog::class,
            /**
             * Retain each immutable record in dispatch order.
             */
            static function (HttpRememberEventLog $event) use ($events): void {
                $events[] = $event;
            },
        );

        return $events;
    }
}
