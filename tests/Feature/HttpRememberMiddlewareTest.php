<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PhilipAnnis\HttpRemember\HttpRememberMiddleware;
use PhilipAnnis\HttpRemember\HttpRememberOptions;
use PhilipAnnis\HttpRemember\Tests\TestCase;

/**
 * Verify concurrent cache writes with manually controlled network promises.
 */
final class HttpRememberMiddlewareTest extends TestCase
{
    /**
     * The shared fake endpoint used by identical-request tests.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The total duration of the fixed test cache policy.
     */
    private const LIFETIME_SECONDS = 60;

    /**
     * The deferred network timeout used by the test policy.
     */
    private const REFRESH_TIMEOUT_SECONDS = 15;

    /**
     * Confirm an earlier cache miss cannot overwrite another caller's cached response.
     */
    public function test_overlapping_cache_misses_preserve_the_first_stored_response(): void
    {
        // Hold both network responses until their completion order can be controlled.
        $firstTransfer = new Promise;
        $secondTransfer = new Promise;
        $transfers = [$firstTransfer, $secondTransfer];
        $handler = $this->middleware()(
            /**
             * Return the next unresolved network response.
             *
             * @return PromiseInterface The manually controlled transfer promise.
             */
            static function () use (&$transfers): PromiseInterface {
                // Keep both requests in flight before either response is remembered.
                return array_shift($transfers);
            },
        );

        // Start identical asynchronous requests while the cache is empty.
        $request = new Request('GET', self::API_URL);
        $first = $handler($request, []);
        $second = $handler($request, []);

        // Complete the later request first and let it populate the cache.
        $secondTransfer->resolve(new Response(HttpStatus::HTTP_OK, [], 'newer-response'));
        self::assertSame('newer-response', (string) $second->wait()->getBody());

        // Finish the older request without changing its caller's response.
        $firstTransfer->resolve(new Response(HttpStatus::HTTP_OK, [], 'older-response'));
        self::assertSame('older-response', (string) $first->wait()->getBody());

        // Confirm the slower response did not replace the already remembered generation.
        self::assertSame('newer-response', (string) $handler($request, [])->wait()->getBody());
        self::assertSame([], $transfers);
    }

    /**
     * Confirm another writer cannot interleave between the cache check and persistence.
     */
    public function test_overlapping_cache_writes_do_not_interleave(): void
    {
        // Hold two identical cache misses before beginning either persistence callback.
        $firstTransfer = new Promise;
        $secondTransfer = new Promise;
        $transfers = [$firstTransfer, $secondTransfer];
        $handler = $this->middleware()(
            /**
             * Return the next unresolved network response.
             *
             * @return PromiseInterface The manually controlled transfer promise.
             */
            static function () use (&$transfers): PromiseInterface {
                // Preserve the overlap until the test explicitly resolves each transfer.
                return array_shift($transfers);
            },
        );

        // Start both requests before either response can populate the cache.
        $request = new Request('GET', self::API_URL);
        $first = $handler($request, []);
        $second = $handler($request, []);
        $writes = 0;

        // Complete the other request immediately before the first cache write commits.
        app('events')->listen(WritingKey::class,
            /**
             * Simulate a concurrent worker attempting to write in the same critical section.
             *
             * @return void
             */
            static function () use ($secondTransfer, $second, &$writes): void {
                // Run the interleaving once even if both requests attempt persistence.
                if (++$writes > 1) {
                    return;
                }

                // Resolve the competing transfer while the first write is underway.
                $secondTransfer->resolve(new Response(HttpStatus::HTTP_OK, [], 'competing-response'));
                self::assertSame('competing-response', (string) $second->wait()->getBody());
            },
        );

        // Finish the first request and read the value it remembered.
        $firstTransfer->resolve(new Response(HttpStatus::HTTP_OK, [], 'first-response'));
        self::assertSame('first-response', (string) $first->wait()->getBody());
        self::assertSame(1, $writes);
        self::assertSame('first-response', (string) $handler($request, [])->wait()->getBody());
        self::assertSame([], $transfers);
    }

    /**
     * Confirm a cache store without lock support still remembers successful responses.
     */
    public function test_stores_without_locks_can_still_remember_responses(): void
    {
        // Delegate persistence to memory while exposing only the basic store contract.
        $memory = Cache::store()->getStore();
        $store = Mockery::mock(Store::class);
        $store->shouldReceive('get')->andReturnUsing($memory->get(...));
        $store->shouldReceive('put')->once()->andReturnUsing($memory->put(...));
        Cache::shouldReceive('store')->with(null)->andReturn(new Repository($store));

        // Count network requests independently of cache reads and writes.
        $sent = 0;
        $handler = $this->middleware()(
            /**
             * Return a successful response from the controlled network handler.
             *
             * @return PromiseInterface The successful live response promise.
             */
            static function () use (&$sent): PromiseInterface {
                // Record each live request so the cache hit remains observable.
                $sent++;

                // Fulfill the transfer with a response that can be remembered.
                return Create::promiseFor(new Response(HttpStatus::HTTP_OK, [], 'response-body'));
            },
        );

        // Confirm repeated requests reuse the response even without a lock provider.
        $request = new Request('GET', self::API_URL);
        self::assertSame('response-body', (string) $handler($request, [])->wait()->getBody());
        self::assertSame('response-body', (string) $handler($request, [])->wait()->getBody());
        self::assertSame(1, $sent);
    }

    /**
     * Confirm cache writes replace hard-expired entries retained by a backend.
     */
    public function test_hard_expired_entries_can_be_replaced_before_backend_eviction(): void
    {
        // Freeze time and return a new body for each request that reaches the handler.
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        $sent = 0;
        $handler = $this->middleware()(
            /**
             * Return a distinct response for each controlled network request.
             *
             * @return PromiseInterface The successful live response promise.
             */
            static function () use (&$sent): PromiseInterface {
                // Distinguish the original cached body from its replacement.
                return Create::promiseFor(new Response(HttpStatus::HTTP_OK, [], (string) ++$sent));
            },
        );

        // Populate the original response before extending its backend retention.
        $request = new Request('GET', self::API_URL);
        self::assertSame('1', (string) $handler($request, [])->wait()->getBody());

        // Retain the payload past its metadata expiry to simulate delayed backend eviction.
        $cache = Cache::store();
        $key = array_key_first($cache->getStore()->all());
        $cache->put($key, $cache->get($key), self::LIFETIME_SECONDS * 2);
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::LIFETIME_SECONDS));

        // Confirm hard expiry fetches and remembers the replacement response.
        self::assertSame('2', (string) $handler($request, [])->wait()->getBody());
        self::assertSame('2', (string) $handler($request, [])->wait()->getBody());
        self::assertSame(2, $sent);
    }

    /**
     * Create the same fixed cache policy for each controlled request.
     *
     * @return HttpRememberMiddleware The cache layer without Laravel's fake transport.
     */
    private function middleware(): HttpRememberMiddleware
    {
        // Isolate promise timing from the fake HTTP client's transfer statistics.
        return new HttpRememberMiddleware(
            new HttpRememberOptions(self::LIFETIME_SECONDS, null, self::REFRESH_TIMEOUT_SECONDS),
            /**
             * Allow the unmodified test request to use the cache.
             *
             * @return bool Whether the test request can be cached.
             */
            static fn (): bool => true,
            /**
             * Leave cache-hit bookkeeping outside the controlled handler test.
             */
            static function (): void {},
        );
    }
}
