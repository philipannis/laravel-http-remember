<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use PhilipAnnis\HttpRemember\HttpRememberResponse;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Verify bounded coordination of synchronous cache misses without real sleeps.
 */
final class CacheMissLockTest extends TestCase
{
    /**
     * The resource shared by competing remembered requests.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * Advance the test clock whenever lock contention would pause the caller.
     */
    protected function setUp(): void
    {
        // Boot an isolated cache and HTTP client before controlling retry timing.
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        Sleep::fake(syncWithCarbon: true);
    }

    /**
     * Restore ordinary sleep behavior before completing application cleanup.
     */
    protected function tearDown(): void
    {
        // Keep fake lock retries from leaking into another test.
        Sleep::fake(false);
        parent::tearDown();
    }

    /**
     * Confirm another worker's response is reused after waiting or reaching the wait limit.
     *
     * @param  bool  $release  Whether the other worker releases its lock after storing the response.
     */
    #[DataProvider('lockReleaseModes')]
    public function test_waiting_misses_reuse_another_workers_response(bool $release): void
    {
        // Retain the request's cache key while making its next call a miss.
        Http::fakeSequence()->push('initial-body');
        $key = $this->emptyEntry();
        $cache = Cache::store();
        $owner = $cache->lock($key.':miss', 60);
        self::assertTrue($owner->get());
        $requests = [];
        app('events')->listen(RequestSending::class,
            /**
             * Record Laravel's bookkeeping when the waiting caller receives a cache hit.
             */
            static function (RequestSending $event) use (&$requests): void {
                $requests[] = $event->request;
            },
        );

        // Simulate the other worker completing its response during the bounded wait.
        Sleep::whenFakingSleep(
            /**
             * Populate the shared entry without letting the waiting request reach the transport.
             */
            static function () use ($cache, $key, $owner, $release): void {
                $cached = HttpRememberResponse::capture(new Response(200, ['X-Upstream' => 'other-worker'], 'shared-body'));
                $cache->put($key, $cached->toArray(), 60);
                if ($release) {
                    $owner->release();
                }
            },
        );
        $response = Http::remember(60)->get(self::API_URL);

        // Reconstruct the cached response and preserve the sending caller's metadata.
        self::assertSame('shared-body', $response->body());
        self::assertSame('other-worker', $response->header('X-Upstream'));
        self::assertCount(1, $requests);
        self::assertSame(self::API_URL, $requests[0]->url());
        Http::assertSentCount(1);
        if ($release) {
            Sleep::assertSleptTimes(1);
            self::assertSame([], $cache->getStore()->locks);
        } else {
            // Reaching the wait limit must not release a lock owned by another worker.
            self::assertTrue($owner->isOwnedByCurrentProcess());
            $owner->release();
        }
    }

    /**
     * Provide both ordinary completion and a response stored just before lock release.
     *
     * @return iterable<string, array{bool}> Other-worker outcomes keyed by release behavior.
     */
    public static function lockReleaseModes(): iterable
    {
        // Recheck the entry after acquisition and after a bounded acquisition timeout.
        yield 'released' => [true];
        yield 'still held' => [false];
    }

    /**
     * Confirm an unfinished competing fetch cannot delay the caller indefinitely.
     */
    public function test_wait_timeout_falls_back_to_a_live_request(): void
    {
        // Keep the competing lock held without ever supplying its response.
        Http::fakeSequence()->push('initial-body')->push('fallback-body');
        $key = $this->emptyEntry();
        $owner = Cache::lock($key.':miss', 60);
        self::assertTrue($owner->get());
        $startedAt = Carbon::now();

        // Return the caller's live result after at most one second of lock waiting.
        self::assertSame('fallback-body', Http::remember(60)->get(self::API_URL)->body());
        self::assertTrue(Carbon::now()->greaterThan($startedAt));
        self::assertTrue(Carbon::now()->lessThanOrEqualTo($startedAt->copy()->addSecond()));
        self::assertTrue($owner->isOwnedByCurrentProcess());
        Http::assertSentCount(2);
        $owner->release();
    }

    /**
     * Confirm the foreground lease covers its network timeout and remains held through persistence.
     */
    public function test_miss_lock_covers_the_live_request_and_cache_write(): void
    {
        // Observe the held lease while a longer foreground transfer is in progress.
        $leaseSeconds = null;
        Http::fake(
            /**
             * Inspect coordination before completing the successful upstream response.
             */
            static function () use (&$leaseSeconds) {
                $locks = Cache::store()->getStore()->locks;
                $leaseSeconds = Carbon::now()->diffInSeconds(array_values($locks)[0]['expiresAt']);

                return Http::response('live-body');
            },
        );
        $lockedDuringWrite = false;
        app('events')->listen(WritingKey::class,
            /**
             * Verify the network owner's miss lock still protects the response being stored.
             */
            static function (WritingKey $event) use (&$lockedDuringWrite): void {
                $lockedDuringWrite = isset(Cache::store()->getStore()->locks[$event->key.':miss']);
            },
        );

        // Keep cache persistence inside the lease and release it before returning to the caller.
        self::assertSame('live-body', Http::timeout(120)->remember(60)->get(self::API_URL)->body());
        self::assertSame(130.0, $leaseSeconds);
        self::assertTrue($lockedDuringWrite);
        self::assertSame([], Cache::store()->getStore()->locks);
        Sleep::assertNeverSlept();
    }

    /**
     * Confirm unsuccessful foreground results release the lock before a later request retries.
     *
     * @param  string  $failure  The transport, status, or eligibility failure preceding success.
     */
    #[DataProvider('foregroundFailures')]
    public function test_foreground_failures_release_the_miss_lock(string $failure): void
    {
        // Fail once before allowing the next caller to cache a successful response.
        $sequence = Http::fakeSequence();
        if ($failure === 'connection') {
            $sequence->pushFailedConnection('Test connection failure.');
        } else {
            $sequence->push('rejected-body', $failure === 'status' ? 503 : 200);
        }
        $sequence->push('accepted-body');
        $pending = Http::remember(60, cacheWhen:
            /**
             * Decline application-level errors without changing ordinary live responses.
             */
            static fn ($response): bool => $response->body() !== 'rejected-body',
        );

        // Preserve the original connection exception or unsuccessful live result.
        try {
            $response = $pending->get(self::API_URL);
            self::assertNotSame('connection', $failure);
            self::assertSame('rejected-body', $response->body());
        } catch (ConnectionException $exception) {
            self::assertSame('connection', $failure);
            self::assertSame('Test connection failure.', $exception->getMessage());
        }

        // A later caller must acquire immediately instead of waiting for the failed owner's lease.
        self::assertSame([], Cache::store()->getStore()->locks);
        self::assertSame('accepted-body', $pending->get(self::API_URL)->body());
        self::assertSame('accepted-body', $pending->get(self::API_URL)->body());
        Sleep::assertNeverSlept();
        Http::assertSentCount(2);
    }

    /**
     * Provide failures that must retain normal HTTP behavior and release coordination.
     *
     * @return iterable<string, array{string}> Failure paths keyed by their origin.
     */
    public static function foregroundFailures(): iterable
    {
        // Release after rejected promises, unsuccessful statuses, and ineligible successful responses.
        yield 'connection' => ['connection'];
        yield 'status' => ['status'];
        yield 'predicate' => ['predicate'];
    }

    /**
     * Confirm cache hits return promptly even when another worker holds the miss lock.
     *
     * @param  int  $age  The cached response's age before the competing request.
     */
    #[DataProvider('cacheHitAges')]
    public function test_fresh_and_stale_hits_do_not_wait_for_miss_locks(int $age): void
    {
        // Keep a usable response while another worker owns the same request's miss lock.
        Http::fakeSequence()->push('initial-body')->push('refreshed-body');
        Http::remember([10, 60])->get(self::API_URL);
        $cache = Cache::store();
        $key = array_key_first($cache->getStore()->all());
        $owner = $cache->lock($key.':miss', 60);
        self::assertTrue($owner->get());
        Carbon::setTestNow(Carbon::now()->addSeconds($age));

        // Fresh hits and stale hits retain their existing immediate response behavior.
        self::assertSame('initial-body', Http::remember([10, 60])->get(self::API_URL)->body());
        self::assertTrue($owner->isOwnedByCurrentProcess());
        Sleep::assertNeverSlept();
        Http::assertSentCount(1);
        $owner->release();
        app(DeferredCallbackCollection::class)->invoke();
    }

    /**
     * Provide the fresh and stale boundaries for otherwise usable responses.
     *
     * @return iterable<string, array{int}> Cache ages keyed by freshness.
     */
    public static function cacheHitAges(): iterable
    {
        // Neither cache-hit mode should acquire or wait for a miss lock.
        yield 'fresh' => [0];
        yield 'stale' => [10];
    }

    /**
     * Confirm lock backend failures cannot replace a successful response or disable caching.
     *
     * @param  bool  $acquire  Whether acquisition fails instead of releasing the completed fetch.
     */
    #[DataProvider('lockFailureModes')]
    public function test_lock_failures_preserve_the_live_response(bool $acquire): void
    {
        // Fail only miss coordination while retaining a functioning in-memory response cache.
        $store = Mockery::mock(ArrayStore::class)->makePartial();
        $lock = Mockery::mock(Lock::class);
        $store->shouldReceive('lock')->with(Mockery::pattern('/:miss$/'), Mockery::type('int'))->once()->andReturn($lock);
        $attempt = $lock->shouldReceive('block')->with(1)->once();
        if ($acquire) {
            $attempt->andThrow(new RuntimeException('Test lock acquisition failure.'));
        } else {
            $attempt->andReturnTrue();
            $lock->shouldReceive('release')->once()->andThrow(new RuntimeException('Test lock release failure.'));
        }
        Cache::shouldReceive('store')->with(null)->andReturn(new Repository($store));
        Http::fakeSequence()->push('live-body');

        // Return and remember the live response despite an unavailable coordination operation.
        self::assertSame('live-body', Http::remember(60)->get(self::API_URL)->body());
        self::assertSame('live-body', Http::remember(60)->get(self::API_URL)->body());
        Sleep::assertNeverSlept();
        Http::assertSentCount(1);
    }

    /**
     * Provide the two lock operations whose failures must remain optional to callers.
     *
     * @return iterable<string, array{bool}> Lock failures keyed by operation.
     */
    public static function lockFailureModes(): iterable
    {
        // Preserve live responses when either starting or finishing coordination fails.
        yield 'acquisition' => [true];
        yield 'release' => [false];
    }

    /**
     * Confirm waiting cannot revive responses from a group invalidated during contention.
     */
    public function test_group_invalidation_during_wait_cancels_the_old_generations_write(): void
    {
        // Hold an older group's miss lock while the waiting caller prepares the same read.
        $group = ['tenant:42', 'products'];
        Http::fakeSequence()->push('initial-body')->push('live-body')->push('replacement-body');
        $key = $this->emptyEntry($group);
        $cache = Cache::store();
        $owner = $cache->lock($key.':miss', 60);
        self::assertTrue($owner->get());
        Sleep::whenFakingSleep(
            /**
             * Complete an older response and invalidate its group before releasing the waiter.
             */
            static function () use ($cache, $key, $owner, $group): void {
                $cached = HttpRememberResponse::capture(new Response(200, [], 'older-body'));
                $cache->put($key, $cached->toArray(), 60);
                Http::remember(group: $group, invalidate: true);
                $owner->release();
            },
        );

        // Return live data without serving or overwriting the invalidated generation.
        self::assertSame('live-body', Http::remember(60, group: $group)->get(self::API_URL)->body());
        self::assertSame('older-body', $cache->get($key)['body']);
        self::assertSame('replacement-body', Http::remember(60, group: $group)->get(self::API_URL)->body());
        self::assertSame('replacement-body', Http::remember(60, group: $group)->get(self::API_URL)->body());
        Http::assertSentCount(3);
    }

    /**
     * Confirm asynchronous requests do not block on another worker's miss lock.
     */
    public function test_async_requests_preserve_nonblocking_misses(): void
    {
        // Keep a synchronous owner's lock held while an asynchronous caller sends independently.
        Http::fakeSequence()->push('initial-body')->push('async-body');
        $key = $this->emptyEntry();
        $owner = Cache::lock($key.':miss', 60);
        self::assertTrue($owner->get());

        // Resolve the usual promise without inserting synchronous lock waits.
        self::assertSame('async-body', Http::async()->remember(60)->get(self::API_URL)->wait()->body());
        self::assertTrue($owner->isOwnedByCurrentProcess());
        Sleep::assertNeverSlept();
        Http::assertSentCount(2);
        $owner->release();
    }

    /**
     * Remove the initial response while retaining its fingerprint and optional group metadata.
     *
     * @param  list<string>|null  $group  The invalidation scope selected by the remembered request.
     * @return string The response cache key used by the competing workers.
     */
    private function emptyEntry(?array $group = null): string
    {
        // Populate one response after any group record so its key is the last inserted entry.
        Http::remember(60, group: $group)->get(self::API_URL);
        $cache = Cache::store();
        $key = array_key_last($cache->getStore()->all());
        $cache->forget($key);

        return $key;
    }
}
