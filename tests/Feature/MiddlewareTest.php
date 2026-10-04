<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Verify cache bypasses and concurrent writes with controlled network promises.
 */
final class MiddlewareTest extends TestCase
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
     * Confirm a header validation failure rejects the promise even when a response is cached.
     */
    public function test_header_callback_rejections_bypass_existing_fresh_responses(): void
    {
        // Use Guzzle's mock transport to preserve its normal callback exception handling.
        $transport = new MockHandler([
            new Response(HttpStatus::HTTP_OK, [], 'cached-response'),
            new Response(HttpStatus::HTTP_OK, [], 'rejected-response'),
        ]);
        $handler = $this->middleware()($transport);
        $request = new Request('GET', self::API_URL);

        // Populate the cache before adding a callback that rejects the next live response.
        self::assertSame('cached-response', (string) $handler($request, [])->wait()->getBody());
        $cache = Cache::store()->getStore();
        $cached = $cache->all();
        $failure = new RuntimeException('Test header validation failure.');

        // Keep the rejected response on the ordinary Guzzle promise path.
        $promise = $handler($request, ['on_headers' =>
            /**
             * Reject the live response from the transport's header hook.
             *
             * @param  ResponseInterface  $response  The response being validated.
             */
            static function (ResponseInterface $response) use ($failure): void {
                // Ensure validation applies to the live response rather than the cached body.
                self::assertSame('rejected-response', (string) $response->getBody());

                // Let Guzzle wrap the application failure in its normal request exception.
                throw $failure;
            },
        ]);
        self::assertInstanceOf(PromiseInterface::class, $promise);

        // Resolve the promise and inspect the original validation failure.
        try {
            $promise->wait();

            // Fail explicitly if the cached response skipped header validation.
            self::fail('The remembered request did not reject the header validation failure.');
        } catch (RequestException $exception) {
            // Confirm Guzzle retained the application's original exception.
            self::assertSame($failure, $exception->getPrevious());
        }

        // Confirm the failed live request did not replace the existing remembered response.
        self::assertCount(0, $transport);
        self::assertSame($cached, $cache->all());
    }

    /**
     * Confirm trailer validation failures reject promises even when a response is cached.
     */
    public function test_trailer_callback_rejections_bypass_existing_fresh_responses(): void
    {
        // Exercise Guzzle's trailer handling only on versions that provide this transport option.
        if (! defined(RequestOptions::class.'::ON_TRAILERS')) {
            self::markTestSkipped('This Guzzle version does not support on_trailers callbacks.');
        }

        // Release in-memory transfers without allocating real cURL handles or reaching the network.
        $factory = Mockery::mock(CurlFactoryInterface::class);
        $factory->shouldReceive('release')->twice();
        $handler = $this->middleware()(
            /**
             * Complete a response through Guzzle's normal trailer callback handling.
             *
             * @param  RequestInterface  $request  The outgoing request entering the transport.
             * @param  array<string, mixed>  $options  The transfer options supplied by the caller.
             * @return PromiseInterface The completed or rejected transfer promise.
             */
            static function (RequestInterface $request, array $options) use ($factory): PromiseInterface {
                // Provide a completed response and invalid digest trailer for validation.
                $easy = new EasyHandle;
                $easy->request = $request;
                $easy->response = new Response(HttpStatus::HTTP_OK, [], 'response-body');
                $easy->trailers = ['Digest: invalid'];
                $easy->options = $options;

                // Let Guzzle invoke the callback and wrap any application validation failure.
                return CurlFactory::finish(new MockHandler, $easy, $factory);
            },
        );

        // Populate the cache before requiring trailer validation on the same request.
        $request = new Request('GET', self::API_URL);
        self::assertSame('response-body', (string) $handler($request, [])->wait()->getBody());
        $cache = Cache::store()->getStore();
        $cached = $cache->all();
        $failure = new RuntimeException('Test trailer validation failure.');

        // Reject the live response through the transport's normal trailer hook.
        $promise = $handler($request, ['on_trailers' =>
            /**
             * Reject a completed response whose digest trailer does not validate.
             *
             * @param  array<string, list<string>>  $trailers  The parsed response trailers.
             * @param  ResponseInterface  $response  The completed live response.
             */
            static function (array $trailers, ResponseInterface $response) use ($failure): void {
                // Confirm Guzzle supplied the live trailers and response before rejecting them.
                self::assertSame(['digest' => ['invalid']], $trailers);
                self::assertSame('response-body', (string) $response->getBody());

                // Preserve the application failure as the cause of Guzzle's rejected promise.
                throw $failure;
            },
        ]);

        // Resolve the promise and confirm the remembered response did not bypass validation.
        try {
            $promise->wait();

            // Fail explicitly if the cached response skipped trailer validation.
            self::fail('The remembered request did not reject the trailer validation failure.');
        } catch (RequestException $exception) {
            // Confirm Guzzle retained the application's original validation exception.
            self::assertSame($failure, $exception->getPrevious());
        }

        // Keep the existing response and expiry unchanged after the rejected live transfer.
        self::assertSame($cached, $cache->all());
    }

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
