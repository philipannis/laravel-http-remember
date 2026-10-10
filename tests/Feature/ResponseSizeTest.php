<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify response size budgets through live requests, cache hits, and deferred refreshes.
 */
final class ResponseSizeTest extends TestCase
{
    /**
     * The shared fake endpoint used by identical-request tests.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The small byte budget used to exercise configurable response size boundaries.
     */
    private const MAX_RESPONSE_BYTES = 16;

    /**
     * Confirm the package default rejects bodies larger than one megabyte.
     */
    public function test_default_limit_skips_bodies_above_one_megabyte(): void
    {
        // Use the unpublished package configuration with an oversized ordinary API response.
        $limit = 1_000_000;
        self::assertSame($limit, config('http-remember.max_response_bytes'));
        $contents = str_repeat('x', $limit + 1);
        Http::fake([self::API_URL => Http::response($contents)]);

        // Return complete live responses without populating the cache on either call.
        self::assertSame($contents, Http::remember(60)->get(self::API_URL)->body());
        self::assertSame($contents, Http::remember(60)->get(self::API_URL)->body());
        self::assertSame([], Cache::store()->getStore()->all());
        Http::assertSentCount(2);
    }

    /**
     * Confirm synchronous and asynchronous requests preserve bodies around the configured limit.
     *
     * @param  int  $bodyBytes  The complete live response body size.
     * @param  bool  $async  Whether requests return promises.
     */
    #[DataProvider('responseSizeModes')]
    public function test_configured_limit_preserves_live_responses_and_bounds_cache_entries(int $bodyBytes, bool $async): void
    {
        // Configure a small byte budget and return the same payload on every live request.
        config(['http-remember.max_response_bytes' => self::MAX_RESPONSE_BYTES]);
        $contents = str_repeat('x', $bodyBytes);
        Http::fake([self::API_URL => Http::response($contents)]);

        // Keep both execution modes on their normal response or promise contracts.
        for ($call = 0; $call < 2; $call++) {
            $response = Http::async($async)->remember(60)->get(self::API_URL);
            if ($async) {
                self::assertInstanceOf(PromiseInterface::class, $response);
                $response = $response->wait();
            }

            // Preserve the caller's complete successful response even when it cannot be cached.
            self::assertSame(200, $response->status());
            self::assertSame($contents, $response->body());
        }

        // Reuse only bodies that fit, including the exact inclusive limit.
        $cacheable = $bodyBytes <= self::MAX_RESPONSE_BYTES;
        self::assertCount($cacheable ? 1 : 0, Cache::store()->getStore()->all());
        Http::assertSentCount($cacheable ? 1 : 2);
    }

    /**
     * Provide payload boundaries for both public HTTP execution modes.
     *
     * @return iterable<string, array{int, bool}> Body sizes keyed by boundary and request mode.
     */
    public static function responseSizeModes(): iterable
    {
        // Verify smaller, exact, and oversized bodies without changing promise semantics.
        foreach ([false, true] as $async) {
            $mode = $async ? 'asynchronous' : 'synchronous';
            yield $mode.' below limit' => [self::MAX_RESPONSE_BYTES - 1, $async];
            yield $mode.' at limit' => [self::MAX_RESPONSE_BYTES, $async];
            yield $mode.' above limit' => [self::MAX_RESPONSE_BYTES + 1, $async];
        }
    }

    /**
     * Confirm a smaller budget applies to new writes while existing entries keep their original expiry.
     */
    public function test_lowering_the_limit_preserves_existing_entries_until_expiry(): void
    {
        // Store a payload that fits the original configuration before reducing the budget.
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        $contents = str_repeat('x', self::MAX_RESPONSE_BYTES + 1);
        Http::fakeSequence()->push($contents)->push($contents)->push('smaller-body');
        self::assertSame($contents, Http::remember(60)->get(self::API_URL)->body());
        config(['http-remember.max_response_bytes' => self::MAX_RESPONSE_BYTES]);

        // Continue serving the previously stored body while its original lifetime remains valid.
        Carbon::setTestNow($startedAt->copy()->addSeconds(59));
        self::assertSame($contents, Http::remember(60)->get(self::API_URL)->body());
        self::assertCount(1, Cache::store()->getStore()->all());
        Http::assertSentCount(1);

        // Apply the smaller budget to the next live response after the old entry expires.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));
        self::assertSame($contents, Http::remember(60)->get(self::API_URL)->body());
        self::assertSame([], Cache::store()->getStore()->all());

        // Cache only a new body that fits the active limit and reuse it on the following call.
        self::assertSame('smaller-body', Http::remember(60)->get(self::API_URL)->body());
        self::assertSame('smaller-body', Http::remember(60)->get(self::API_URL)->body());
        self::assertCount(1, Cache::store()->getStore()->all());
        Http::assertSentCount(3);
    }

    /**
     * Confirm applications can raise the budget and cache bodies rejected by the previous setting.
     */
    public function test_raising_the_limit_allows_larger_responses(): void
    {
        // Return a body one byte above the initial configuration without storing it.
        config(['http-remember.max_response_bytes' => self::MAX_RESPONSE_BYTES]);
        $contents = str_repeat('x', self::MAX_RESPONSE_BYTES + 1);
        Http::fake([self::API_URL => Http::response($contents)]);
        self::assertSame($contents, Http::remember(60)->get(self::API_URL)->body());
        self::assertSame([], Cache::store()->getStore()->all());

        // Cache the same payload after increasing the limit and reuse it on the following call.
        config(['http-remember.max_response_bytes' => self::MAX_RESPONSE_BYTES + 1]);
        self::assertSame($contents, Http::remember(60)->get(self::API_URL)->body());
        self::assertSame($contents, Http::remember(60)->get(self::API_URL)->body());
        self::assertCount(1, Cache::store()->getStore()->all());
        Http::assertSentCount(2);
    }

    /**
     * Confirm oversized refreshes keep the original generation without extending its lifetime.
     */
    public function test_oversized_refreshes_preserve_the_original_generation_and_expiry(): void
    {
        // Populate a small response before an oversized refresh and a later valid replacement.
        config(['http-remember.max_response_bytes' => self::MAX_RESPONSE_BYTES]);
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()
            ->push('initial-body')
            ->push(str_repeat('x', self::MAX_RESPONSE_BYTES + 1))
            ->push('updated-body');
        $inspections = 0;
        $pending = Http::remember([10, 60], cacheWhen:
            /**
             * Count only candidates that fit the response size budget.
             */
            static function (Response $response) use (&$inspections): bool {
                $inspections++;

                return true;
            },
        );
        self::assertSame('initial-body', $pending->get(self::API_URL)->body());
        $cache = Cache::store();
        $key = array_key_first($cache->getStore()->all());
        $generation = $cache->get($key);

        // Serve stale data and run the refresh while the original entry is still valid.
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        self::assertSame('initial-body', $pending->get(self::API_URL)->body());
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame($generation, $cache->get($key));
        self::assertSame(1, $inspections);
        Http::assertSentCount(2);

        // Enforce the original hard expiry and replace the entry only with a new valid response.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));
        self::assertSame('updated-body', $pending->get(self::API_URL)->body());
        self::assertSame(2, $inspections);
        Http::assertSentCount(3);
    }

    /**
     * Confirm invalid configuration fails when remember is called rather than during a transfer.
     */
    public function test_non_positive_configuration_is_rejected_immediately(): void
    {
        // Reject a budget that cannot bound the cache copy before any request is sent.
        config(['http-remember.max_response_bytes' => 0]);
        $this->expectException(InvalidArgumentException::class);

        // Resolve the configured size while attaching the package middleware.
        Http::remember();
    }
}
