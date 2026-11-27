<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercise validators and not-modified responses through deferred refreshes.
 */
final class ConditionalRefreshTest extends TestCase
{
    /**
     * The resource shared by the foreground reads and deferred transfers.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The origin's modification date for the initial representation.
     */
    private const LAST_MODIFIED = 'Thu, 01 Jan 2026 11:00:00 GMT';

    /**
     * Freeze receipt time so freshness and expiry can be checked independently of transport.
     */
    protected function setUp(): void
    {
        // Start the application before fixing the clock used by all refresh scenarios.
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00 UTC'));
    }

    /**
     * Confirm a matching 304 reuses the body and applies the updated freshness policy.
     *
     * @param  array<string, string>  $headers  The original validator headers.
     * @param  array<string, string>  $validation  The validator headers supplied by the 304.
     * @param  string  $header  The expected conditional request header.
     * @param  string  $value  The expected validator value.
     */
    #[DataProvider('validators')]
    public function test_not_modified_renews_the_cached_body(array $headers, array $validation, string $header, string $value): void
    {
        // Give the original response an upstream age, a shorter lifetime, and a complete body.
        $startedAt = Carbon::now();
        Http::fakeSequence()->push('original-body', 200, $headers + [
            'Date' => $startedAt->format(DATE_RFC7231),
            'Age' => '5',
            'Cache-Control' => 'max-age=15, stale-while-revalidate=40',
            'Content-Length' => '13',
            'X-Version' => 'original',
        ])->push('ignored', 304, $validation + [
            'Cache-Control' => 'max-age=30, stale-while-revalidate=25',
            'Connection' => 'X-Transit',
            'Content-Length' => '0',
            'X-Transit' => 'validation-connection',
            'X-Version' => 'revalidated',
        ]);

        // Populate the cache and serve the stale body before validation starts.
        $pending = Http::remember([10, 60]);
        $pending->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        self::assertSame('original-body', $pending->get(self::API_URL)->body());
        Http::assertSentCount(1);
        app(DeferredCallbackCollection::class)->invoke();

        // Inspect the actual conditional transfer and its raw upstream status.
        Http::assertSentCount(2);
        [[$initial], [$request, $reply]] = Http::recorded()->all();
        self::assertSame([], $initial->header($header));
        self::assertSame([$value], $request->header($header));
        self::assertSame([], $request->header($header === 'If-None-Match' ? 'If-Modified-Since' : 'If-None-Match'));
        self::assertSame(304, $reply->status());

        // Serve the original 200 body with renewed freshness and updated end-to-end headers.
        $fresh = $pending->get(self::API_URL);
        self::assertSame(200, $fresh->status());
        self::assertSame('original-body', $fresh->body());
        self::assertSame('13', $fresh->header('Content-Length'));
        self::assertSame('revalidated', $fresh->header('X-Version'));
        self::assertSame('', $fresh->header('X-Transit'));
        self::assertSame('0', $fresh->header('Age'));
        self::assertSame($headers['ETag'] ?? '', $fresh->header('ETag'));
        self::assertCount(0, app(DeferredCallbackCollection::class));

        // Read past the original expiry and stop serving at the renewed origin deadline.
        $cacheOnly = Http::withHeaders(['Cache-Control' => 'only-if-cached'])->remember([10, 60]);
        Carbon::setTestNow($startedAt->copy()->addSeconds(55));
        self::assertSame('original-body', $cacheOnly->get(self::API_URL)->body());
        Carbon::setTestNow($startedAt->copy()->addSeconds(65));
        self::assertSame(504, $cacheOnly->get(self::API_URL)->status());
        Http::assertSentCount(2);
    }

    /**
     * Provide strong tags, weak comparisons, and a safe date fallback.
     *
     * @return iterable<string, array{array<string, string>, array<string, string>, string, string}> Validation cases.
     */
    public static function validators(): iterable
    {
        // Prefer the tag when both validators are available.
        yield 'ETag takes precedence' => [['ETag' => '"v1"', 'Last-Modified' => self::LAST_MODIFIED], [], 'If-None-Match', '"v1"'];

        // Keep a weak stored tag even when the validation reply sends a strong tag.
        yield 'weak ETag' => [['ETag' => 'W/"v1"'], ['ETag' => '"v1"'], 'If-None-Match', 'W/"v1"'];

        // Fall back to the date when the tag would become a wildcard condition.
        yield 'Last-Modified fallback' => [['ETag' => '*', 'Last-Modified' => self::LAST_MODIFIED], ['Last-Modified' => self::LAST_MODIFIED], 'If-Modified-Since', self::LAST_MODIFIED];
    }

    /**
     * Confirm unsuccessful validation preserves the old payload and follows the refresh cooldown.
     *
     * @param  array<string, string>  $headers  The original validator headers.
     * @param  array<string, string>  $validation  The headers supplied by the unconfirmed 304.
     */
    #[DataProvider('unconfirmedValidations')]
    public function test_unconfirmed_304_preserves_original_expiry(array $headers, array $validation): void
    {
        // Keep the original entry available until its application lifetime expires.
        $startedAt = Carbon::now();
        Http::fakeSequence()->push('original', 200, $headers)
            ->push('', 304, $validation + ['Retry-After' => '30'])
            ->push('replacement');
        $pending = Http::remember([10, 60]);
        $pending->get(self::API_URL);
        $key = array_key_first(Cache::store()->getStore()->all());
        $original = Cache::get($key);

        // Start the refresh when the entry becomes stale.
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        $pending->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Apply Retry-After and preserve the original response generation.
        Carbon::setTestNow($startedAt->copy()->addSeconds(35));
        self::assertSame($original, Cache::get($key));
        self::assertSame('original', $pending->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);
        self::assertNotContains('*', Http::recorded()[1][0]->header('If-None-Match'));

        // Fetch live when the original lifetime ends.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));
        self::assertSame('replacement', $pending->get(self::API_URL)->body());
        Http::assertSentCount(3);
    }

    /**
     * Provide unsolicited and contradictory validation responses.
     *
     * @return iterable<string, array{array<string, string>, array<string, string>}> Unconfirmed validation cases.
     */
    public static function unconfirmedValidations(): iterable
    {
        // An unusable stored validator cannot authorize a 304.
        yield 'unsolicited 304' => [['ETag' => '*'], []];

        // A change in the tag or a later modification timestamp conflicts with the request condition.
        yield 'different entity tag' => [['ETag' => '"v1"'], ['ETag' => '"v2"']];
        yield 'later modification' => [['Last-Modified' => self::LAST_MODIFIED], ['Last-Modified' => 'Thu, 01 Jan 2026 11:30:00 GMT']];
    }

    /**
     * Confirm newly restrictive metadata can retire the stored body after validation.
     *
     * @param  array<string, string>  $headers  The origin's new restrictions.
     */
    #[DataProvider('restrictedValidationHeaders')]
    public function test_restrictive_304_retires_the_cached_generation(array $headers): void
    {
        // Store an eligible representation before the origin returns a restrictive 304.
        Http::fakeSequence()->push('original', 200, ['ETag' => '"v1"'])->push('', 304, $headers)->push('live');
        $pending = Http::remember([10, 60]);
        $pending->get(self::API_URL);
        $key = array_key_first(Cache::store()->getStore()->all());

        // Complete the validation after serving the stale response.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        self::assertSame('original', $pending->get(self::API_URL)->body());
        app(DeferredCallbackCollection::class)->invoke();

        // The next request fetches live after the observed generation is retired.
        self::assertNull(Cache::get($key));
        self::assertSame('live', $pending->get(self::API_URL)->body());
        Http::assertSentCount(3);
    }

    /**
     * Provide restrictions that apply even when the body was confirmed unchanged.
     *
     * @return iterable<string, array{array<string, string>}> Ineligible validation metadata.
     */
    public static function restrictedValidationHeaders(): iterable
    {
        // Apply the same origin restrictions and age limits as a regular refresh.
        yield 'no-store' => [['Cache-Control' => 'no-store']];
        yield 'session cookie' => [['Set-Cookie' => 'session=new; Path=/']];
        yield 'expired before receipt' => [['Cache-Control' => 'max-age=5', 'Age' => '6']];

        // Reject a variant tied to the generated conditional header.
        yield 'conditional vary' => [['Vary' => 'If-None-Match']];
    }

    /**
     * Confirm application eligibility receives the reconstructed response on a 304.
     */
    public function test_revalidated_body_and_headers_are_checked_by_cache_when(): void
    {
        // Store a permitted response and let the validation reply change its eligibility header.
        Http::fakeSequence()->push('original', 200, ['ETag' => '"v1"', 'X-Cacheable' => 'yes'])
            ->push('', 304, ['X-Cacheable' => 'no']);
        $inspected = [];
        $pending = Http::remember([10, 60], cacheWhen:
            /**
             * Record the representation supplied to the eligibility callback.
             */
            static function (Response $response) use (&$inspected): bool {
                // Evaluate the latest header against the stored body and original status.
                $inspected[] = [$response->status(), $response->body(), $response->header('X-Cacheable')];

                return $response->header('X-Cacheable') === 'yes';
            },
        );
        $pending->get(self::API_URL);
        $key = array_key_first(Cache::store()->getStore()->all());
        $original = Cache::get($key);

        // Revalidate the stored body after the fresh period.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $pending->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Keep the original lifetime when the new metadata fails the predicate.
        self::assertSame([[200, 'original', 'yes'], [200, 'original', 'no']], $inspected);
        self::assertSame($original, Cache::get($key));
        Http::assertSentCount(2);
    }

    /**
     * Confirm a matching 304 cannot renew a group invalidated during the transfer.
     */
    public function test_in_flight_invalidation_cancels_the_renewal(): void
    {
        // Rotate the group while its conditional refresh is waiting for the origin.
        $group = ['products'];
        $sent = 0;
        Http::fake(
            /**
             * Confirm the old body only after rotating its group generation.
             */
            static function () use ($group, &$sent): PromiseInterface {
                // Seed the response on the first transfer.
                if (++$sent === 1) {
                    return Http::response('original', 200, ['ETag' => '"v1"']);
                }

                // Invalidate before the 304 can reach cache persistence.
                Http::remember(group: $group, invalidate: true);

                return Http::response('', 304);
            },
        );
        Http::remember([10, 60], group: $group)->get(self::API_URL);
        $key = array_key_last(Cache::store()->getStore()->all());
        $original = Cache::get($key);

        // Run the deferred validation under the previously observed generation.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        Http::remember([10, 60], group: $group)->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Preserve the old payload and leave the new generation empty.
        self::assertSame($original, Cache::get($key));
        self::assertSame(504, Http::withHeaders(['Cache-Control' => 'only-if-cached'])
            ->remember([10, 60], group: $group)->get(self::API_URL)->status());
        Http::assertSentCount(2);
    }
}
