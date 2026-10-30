<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;
use PhilipAnnis\HttpRemember\HttpRememberResponse;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify invalid cached HTTP headers can be replaced through the normal cache miss path.
 */
final class MalformedCacheEntryTest extends TestCase
{
    /**
     * The resource shared by corrupted entries and their replacement responses.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * Confirm malformed header names and values allow a live response to repair the same key.
     *
     * @param  array<string, list<string>>  $headers  The invalid header data written into the cache.
     * @param  bool  $async  Whether the caller receives a response promise.
     */
    #[DataProvider('malformedHeaders')]
    public function test_malformed_headers_are_replaced_and_the_new_response_is_reused(array $headers, bool $async): void
    {
        // Populate a stale-capable entry before replacing only its serialized headers.
        Carbon::setTestNow('2026-01-01 12:00:00');
        Http::fakeSequence()->push('original')->push('replacement', 201, ['X-Upstream' => 'updated']);
        $pending = Http::async($async)->remember([10, 60]);
        $initial = $pending->get(self::API_URL);
        self::assertSame('original', ($async ? $initial->wait() : $initial)->body());
        $cache = Cache::store();
        $key = array_key_first($cache->getStore()->all());
        $payload = $cache->get($key);
        $payload['headers'] = $headers;
        $cache->put($key, $payload, 60);

        // Distinguish invalid data from cache outages without scheduling a stale refresh.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $events = [];
        app('events')->listen(HttpRememberEventLog::class,
            /**
             * Record the cache decisions made while repairing and reusing the entry.
             */
            static function (HttpRememberEventLog $event) use (&$events): void {
                $events[] = $event;
            },
        );

        // Return the live response unchanged and persist it over the malformed generation.
        $response = $pending->get(self::API_URL);
        $response = $async ? $response->wait() : $response;
        self::assertSame('replacement', $response->body());
        self::assertSame(201, $response->status());
        self::assertSame('updated', $response->header('X-Upstream'));
        self::assertSame('replacement', $cache->get($key)['body']);
        self::assertNotSame($payload['id'], $cache->get($key)['id']);

        // Reuse the repaired response through an ordinary cache hit without another transfer.
        $remembered = $pending->get(self::API_URL);
        self::assertSame('replacement', ($async ? $remembered->wait() : $remembered)->body());
        self::assertSame(['miss', 'stored', 'hit'], array_column($events, 'event'));
        self::assertSame('invalid_entry', $events[0]->reason);
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);
    }

    /**
     * Provide corrupted header representations for both public response execution modes.
     *
     * @return iterable<string, array{array<string, list<string>>, bool}> Invalid headers and request modes.
     */
    public static function malformedHeaders(): iterable
    {
        // Exercise invalid names, invalid string values, and unusable empty value lists.
        foreach ([false, true] as $async) {
            $mode = $async ? 'asynchronous' : 'synchronous';
            yield 'header name '.$mode => [['Invalid Header' => ['value']], $async];
            yield 'header value '.$mode => [['X-Test' => ["value\r\nInjected: value"]], $async];
            yield 'empty value list '.$mode => [['X-Test' => []], $async];
        }
    }

    /**
     * Confirm cache-only requests reject malformed headers without preventing later repair.
     */
    public function test_cache_only_requests_preserve_the_network_prohibition_before_repair(): void
    {
        // Corrupt an existing fixed entry while leaving a replacement available for a later live read.
        Http::fakeSequence()->push('original')->push('replacement');
        Http::remember(60)->get(self::API_URL);
        $cache = Cache::store();
        $key = array_key_first($cache->getStore()->all());
        $payload = $cache->get($key);
        $payload['headers'] = ['Invalid Header' => ['value']];
        $cache->put($key, $payload, 60);

        // Treat the entry as unavailable without fetching or deleting anything during a cache-only read.
        $response = Http::async()->withHeaders(['Cache-Control' => 'only-if-cached'])->remember(60)->get(self::API_URL)->wait();
        self::assertSame(504, $response->status());
        self::assertSame($payload, $cache->get($key));
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(1);

        // Let the next ordinary read replace the malformed entry and reuse its replacement.
        self::assertSame('replacement', Http::remember(60)->get(self::API_URL)->body());
        self::assertSame('replacement', $cache->get($key)['body']);
        self::assertSame('replacement', Http::remember(60)->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm repair cannot overwrite another worker's valid replacement.
     */
    public function test_repair_preserves_a_concurrent_valid_replacement(): void
    {
        // Replace the malformed cache slot while its foreground repair request is still in flight.
        $calls = 0;
        Http::fake(
            /**
             * Simulate a separate worker committing before the older response can be persisted.
             */
            static function () use (&$calls): PromiseInterface {
                // Populate the original entry before the test corrupts its serialized headers.
                if (++$calls === 1) {
                    return Http::response('original');
                }

                // Write a complete valid generation before completing the caller's live response.
                $cache = Cache::store();
                $key = array_key_first($cache->getStore()->all());
                $replacement = HttpRememberResponse::capture(new PsrResponse(200, [], 'concurrent-replacement'));
                $cache->put($key, $replacement->toArray(), 60);

                return Http::response('older-live-response');
            },
        );

        // Force a miss using invalid HTTP syntax rather than changing the entry's generation shape.
        Http::remember(60)->get(self::API_URL);
        $cache = Cache::store();
        $key = array_key_first($cache->getStore()->all());
        $payload = $cache->get($key);
        $payload['headers'] = ['Invalid Header' => ['value']];
        $cache->put($key, $payload, 60);

        // Preserve the live caller's result while keeping the concurrent worker's cached response.
        self::assertSame('older-live-response', Http::remember(60)->get(self::API_URL)->body());
        self::assertSame('concurrent-replacement', $cache->get($key)['body']);
        self::assertSame('concurrent-replacement', Http::remember(60)->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }
}
