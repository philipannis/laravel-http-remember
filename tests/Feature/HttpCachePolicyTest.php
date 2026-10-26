<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\HttpRememberResponse;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Verify conservative HTTP cache controls through Laravel's public request API.
 */
final class HttpCachePolicyTest extends TestCase
{
    /**
     * The endpoint shared by requests with different cache controls and credentials.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The stable response receipt time used for freshness and expiry assertions.
     */
    private const STARTED_AT = '2026-01-01 12:00:00';

    /**
     * Confirm origin restrictions cannot be overridden by a successful response predicate.
     *
     * @param  array<string, string|list<string>>  $headers  Headers that prohibit caching.
     * @param  int  $status  The upstream response status.
     */
    #[DataProvider('restrictedResponses')]
    public function test_restricted_responses_remain_live(array $headers, int $status = 200): void
    {
        // Return successful bodies that would otherwise be admitted by an application predicate.
        Http::fakeSequence()->push('first', $status, $headers)->push('second', $status, $headers);
        $inspected = 0;
        $pending = Http::remember(60, cacheWhen:
            /**
             * Record whether a built-in restriction incorrectly reached application filtering.
             */
            static function (Response $response) use (&$inspected): bool {
                // A predicate may narrow eligibility but must not override HTTP restrictions.
                $inspected++;

                return true;
            },
        );

        // Keep both live responses intact and retain neither response in the selected store.
        self::assertSame('first', $pending->get(self::API_URL)->body());
        self::assertSame('second', $pending->get(self::API_URL)->body());
        self::assertSame(0, $inspected);
        self::assertSame([], Cache::store()->getStore()->all());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);
    }

    /**
     * Provide privacy, validation, session, partial-content, and malformed-policy restrictions.
     *
     * @return iterable<string, array{array<string, string|list<string>>, int?}> Ineligible responses.
     */
    public static function restrictedResponses(): iterable
    {
        // Treat both ordinary and qualified privacy directives conservatively.
        yield 'no-store' => [['Cache-Control' => 'public, no-store']];
        yield 'no-cache' => [['Cache-Control' => 'no-cache']];
        yield 'qualified no-cache' => [['Cache-Control' => 'no-cache="ETag, X-Secret"']];
        yield 'private' => [['Cache-Control' => 'private, max-age=60']];
        yield 'qualified private' => [['Cache-Control' => 'private="X-Secret"']];
        yield 'mixed case fields' => [['Cache-Control' => ['public', 'No-StOrE']]];
        yield 'must-understand' => [['Cache-Control' => 'must-understand']];

        // Do not retain newly issued sessions or incomplete representations.
        yield 'session cookie' => [['Set-Cookie' => 'session=secret; Path=/']];
        yield 'legacy session cookie' => [['Set-Cookie2' => 'session=secret']];
        yield 'partial status' => [[], 206];
        yield 'partial header' => [['Content-Range' => 'bytes 0-3/10']];

        // Ambiguous or invalid lifetime data must never extend a response's usable lifetime.
        yield 'duplicate age' => [['Cache-Control' => 'max-age=60, max-age=120']];
        yield 'negative age' => [['Cache-Control' => 'max-age=-1']];
        yield 'fractional age' => [['Cache-Control' => 'max-age=1.5']];
        yield 'missing age value' => [['Cache-Control' => 'max-age']];
        yield 'unterminated quote' => [['Cache-Control' => 'extension="unterminated, no-store']];
        yield 'invalid date' => [['Date' => 'tomorrow']];
        yield 'invalid upstream age' => [['Age' => '-1']];
        yield 'multiple upstream ages' => [['Age' => ['1', '2']]];
        yield 'invalid expiry' => [['Expires' => '0']];
        yield 'expired maximum age' => [['Cache-Control' => 'max-age=0']];
        yield 'already aged response' => [['Cache-Control' => 'max-age=30', 'Age' => '30']];
    }

    /**
     * Confirm cache prohibitions and caller-managed validation bypass an existing entry too.
     *
     * @param  array<string, string>  $headers  Request headers requiring a live transfer.
     */
    #[DataProvider('restrictedRequests')]
    public function test_request_restrictions_bypass_existing_entries(array $headers): void
    {
        // Populate a normal entry before adding request-only cache restrictions.
        Http::fakeSequence()->push('cached')->push('live-first')->push('live-second');
        Http::remember([0, 60])->get(self::API_URL);
        $original = Cache::store()->getStore()->all();
        $pending = Http::withHeaders($headers)->remember([0, 60]);

        // A request requiring the origin neither reuses nor writes cache data or schedules refreshes.
        self::assertSame('live-first', $pending->get(self::API_URL)->body());
        self::assertSame('live-second', $pending->get(self::API_URL)->body());
        self::assertSame($original, Cache::store()->getStore()->all());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(3);
    }

    /**
     * Provide request controls that need the ordinary live HTTP path.
     *
     * @return iterable<string, array{array<string, string>}> Request header sets.
     */
    public static function restrictedRequests(): iterable
    {
        // Honor explicit origin checks and the legacy request fallback.
        yield 'no-store' => [['Cache-Control' => 'no-store']];
        yield 'no-cache' => [['Cache-Control' => 'no-cache']];
        yield 'zero maximum age' => [['Cache-Control' => 'max-age=0']];
        yield 'pragma' => [['Pragma' => 'no-cache']];
        yield 'malformed age' => [['Cache-Control' => 'max-age=invalid']];
        yield 'duplicate age' => [['Cache-Control' => 'max-age=10, max-age=20']];
        yield 'invalid minimum freshness' => [['Cache-Control' => 'min-fresh=-1']];

        // Preserve caller-supplied validators and range semantics without synthesizing responses.
        yield 'range' => [['Range' => 'bytes=0-3']];
        yield 'if-range' => [['If-Range' => '"version-1"']];
        yield 'if-match' => [['If-Match' => '"version-1"']];
        yield 'if-none-match' => [['If-None-Match' => '"version-1"']];
        yield 'if-modified-since' => [['If-Modified-Since' => 'Thu, 01 Jan 2026 11:00:00 GMT']];
        yield 'if-unmodified-since' => [['If-Unmodified-Since' => 'Thu, 01 Jan 2026 11:00:00 GMT']];
    }

    /**
     * Confirm origin freshness and age shorten a longer configured lifetime.
     *
     * @param  array<string, string>  $headers  Origin freshness and age fields.
     * @param  int  $expiresAfter  Seconds after receipt when the entry must expire.
     */
    #[DataProvider('freshnessLimits')]
    public function test_origin_freshness_caps_the_application_lifetime(array $headers, int $expiresAfter): void
    {
        // Freeze receipt time so each origin deadline can be tested exactly at its boundary.
        $started = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($started);
        Http::fakeSequence()->push('initial', 200, $headers)->push('updated');
        Http::remember(60)->get(self::API_URL);

        // Reuse the response immediately before expiry without extending its original deadline.
        Carbon::setTestNow($started->copy()->addSeconds($expiresAfter - 1));
        self::assertSame('initial', Http::remember(60)->get(self::API_URL)->body());
        Http::assertSentCount(1);

        // Contact the origin when its effective lifetime ends, even though the application allows longer.
        Carbon::setTestNow($started->copy()->addSeconds($expiresAfter));
        self::assertSame('updated', Http::remember(60)->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Provide freshness precedence and age calculations with predictable deadlines.
     *
     * @return iterable<string, array{array<string, string>, int}> Origin fields and remaining lifetimes.
     */
    public static function freshnessLimits(): iterable
    {
        // Prefer shared max-age over max-age and max-age over Expires.
        yield 'maximum age' => [['Cache-Control' => 'max-age=10'], 10];
        yield 'quoted maximum age' => [['Cache-Control' => 'max-age="10"'], 10];
        yield 'shared maximum age' => [['Cache-Control' => 'max-age=50, s-maxage=5'], 5];
        yield 'expires overridden' => [['Cache-Control' => 'max-age=10', 'Expires' => 'Thu, 01 Jan 2026 13:00:00 GMT'], 10];
        yield 'expires with date' => [['Date' => 'Thu, 01 Jan 2026 12:00:00 GMT', 'Expires' => 'Thu, 01 Jan 2026 12:00:10 GMT'], 10];
        yield 'expires without date' => [['Expires' => 'Thu, 01 Jan 2026 12:00:10 GMT'], 10];

        // Include age accumulated upstream and apparent age derived from Date.
        yield 'upstream age' => [['Cache-Control' => 'max-age=20', 'Age' => '10'], 10];
        yield 'apparent age' => [['Cache-Control' => 'max-age=20', 'Date' => 'Thu, 01 Jan 2026 11:59:50 GMT'], 10];
        yield 'larger upstream age' => [['Cache-Control' => 'max-age=20', 'Age' => '15', 'Date' => 'Thu, 01 Jan 2026 11:59:50 GMT'], 5];
        yield 'application upper bound' => [['Cache-Control' => 'max-age=999999999999999999999999999'], 60];
    }

    /**
     * Confirm an origin-provided stale window controls both refresh scheduling and hard expiry.
     */
    public function test_origin_stale_window_is_bounded_by_its_own_deadline(): void
    {
        // Let the origin shorten the fresh threshold and explicitly permit twenty stale seconds.
        $started = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($started);
        Http::fakeSequence()->push('initial', 200, ['Cache-Control' => 'max-age=10, stale-while-revalidate=20'])->push('updated');
        Http::remember([30, 60])->get(self::API_URL);

        // Schedule a refresh when the origin's freshness ends, before the application's fresh threshold.
        Carbon::setTestNow($started->copy()->addSeconds(10));
        self::assertSame('initial', Http::remember([30, 60])->get(self::API_URL)->body());
        self::assertCount(1, app(DeferredCallbackCollection::class));
        Http::assertSentCount(1);

        // Hard expiry remains enforceable while the backend retains metadata for pending refreshes.
        Carbon::setTestNow($started->copy()->addSeconds(30));
        self::assertNotEmpty(Cache::store()->getStore()->all());
        app(DeferredCallbackCollection::class)->invoke();
        Http::assertSentCount(1);
        self::assertSame('updated', Http::remember([30, 60])->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm mandatory validation defeats a configured or origin-provided stale window.
     *
     * @param  string  $control  The response's freshness and validation controls.
     */
    #[DataProvider('validationControls')]
    public function test_validation_controls_require_a_live_response_when_stale(string $control): void
    {
        // Configure a stale period that the response is allowed to narrow or prohibit.
        Carbon::setTestNow(self::STARTED_AT);
        Http::fakeSequence()->push('initial', 200, ['Cache-Control' => $control])->push('updated');
        Http::remember([10, 60])->get(self::API_URL);
        Carbon::setTestNow(Carbon::now()->addSeconds(10));

        // Resolve stale data in the foreground instead of serving it and scheduling a refresh.
        self::assertSame('updated', Http::remember([10, 60])->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);
    }

    /**
     * Provide controls that disallow the configured stale response.
     *
     * @return iterable<string, array{string}> Response Cache-Control values.
     */
    public static function validationControls(): iterable
    {
        // Revalidation requirements take precedence over both kinds of stale allowance.
        yield 'must revalidate' => ['max-age=50, must-revalidate, stale-while-revalidate=30'];
        yield 'proxy revalidate' => ['max-age=50, proxy-revalidate'];
        yield 'shared revalidation' => ['s-maxage=50, stale-while-revalidate=30'];
        yield 'origin expiry without stale permission' => ['max-age=10'];
    }

    /**
     * Confirm a restrictive refresh retires the previously reusable response.
     *
     * @param  array<string, string|list<string>>  $headers  Newly restrictive response fields.
     * @param  int  $status  The refresh response status.
     */
    #[DataProvider('restrictedResponses')]
    public function test_restrictive_refresh_removes_the_old_generation(array $headers, int $status = 200): void
    {
        // Start with an unrestricted response and change its policy during deferred refresh.
        Carbon::setTestNow(self::STARTED_AT);
        Http::fakeSequence()->push('initial')->push('restricted', $status, $headers)->push('new-live-response');
        Http::remember([10, 60])->get(self::API_URL);
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        self::assertSame('initial', Http::remember([10, 60])->get(self::API_URL)->body());

        // Do not continue serving a previous generation after the origin withdraws cache eligibility.
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame('new-live-response', Http::remember([10, 60])->get(self::API_URL)->body());
        Http::assertSentCount(3);
    }

    /**
     * Confirm request freshness controls reuse the representation key and refresh it when required.
     */
    public function test_request_freshness_limits_apply_to_existing_entries(): void
    {
        // Seed an entry without request cache controls and let it age within its fixed lifetime.
        Carbon::setTestNow(self::STARTED_AT);
        Http::fakeSequence()->push('initial')->push('updated')->push('newest');
        Http::remember(60)->get(self::API_URL);
        Carbon::setTestNow(Carbon::now()->addSeconds(10));

        // Reuse the same entry for permissive age limits and fetch a live response for stricter ones.
        self::assertSame('initial', Http::withHeaders(['Cache-Control' => 'max-age=20'])->remember(60)->get(self::API_URL)->body());
        self::assertSame('updated', Http::withHeaders(['Cache-Control' => 'max-age=5'])->remember(60)->get(self::API_URL)->body());
        self::assertSame('updated', Http::remember(60)->get(self::API_URL)->body());

        // Minimum freshness can force a new answer before the configured hard expiry.
        Carbon::setTestNow(Carbon::now()->addSeconds(50));
        self::assertSame('newest', Http::withHeaders(['Cache-Control' => 'min-fresh=20'])->remember(60)->get(self::API_URL)->body());
        Http::assertSentCount(3);
    }

    /**
     * Confirm an only-if-cached request never starts a foreground or deferred transfer.
     */
    public function test_cache_only_requests_return_hits_or_504_without_network_work(): void
    {
        // A missing entry produces an ordinary HTTP failure without reaching the fake transport.
        Carbon::setTestNow(self::STARTED_AT);
        Http::fakeSequence()->push('initial');
        $pending = Http::withHeaders(['Cache-Control' => 'only-if-cached'])->remember([10, 60]);
        self::assertSame(504, $pending->get(self::API_URL)->status());
        Http::assertSentCount(0);

        // Both fresh and permitted stale hits use the same key as the normal population request.
        Http::remember([10, 60])->get(self::API_URL);
        self::assertSame('initial', $pending->get(self::API_URL)->body());
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        self::assertSame('initial', $pending->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));

        // A conflicting freshness requirement still cannot cause a network fallback.
        self::assertSame(504, Http::withHeaders(['Cache-Control' => 'only-if-cached, no-cache'])->remember([10, 60])->get(self::API_URL)->status());
        self::assertSame(504, Http::withHeaders(['Cache-Control' => 'only-if-cached'])->remember()->post(self::API_URL)->status());
        Http::assertSentCount(1);
    }

    /**
     * Confirm cache hits report upstream age plus time spent in the local cache.
     */
    public function test_cache_hits_update_age_without_mutating_live_headers(): void
    {
        // Supply an already aged response whose live headers must remain unchanged.
        Carbon::setTestNow(self::STARTED_AT);
        Http::fakeSequence()->push('initial', 200, ['Age' => '5', 'Cache-Control' => 'max-age=60']);
        $live = Http::remember(60)->get(self::API_URL);
        self::assertSame('5', $live->header('Age'));

        // Age must advance independently of the configured application lifetime.
        Carbon::setTestNow(Carbon::now()->addSeconds(4));
        self::assertSame('9', Http::remember(60)->get(self::API_URL)->header('Age'));
        self::assertSame('5', $live->header('Age'));
        Http::assertSentCount(1);
    }

    /**
     * Confirm quoted extension text cannot create a false directive or hide a real one.
     */
    public function test_quoted_extensions_and_legacy_request_precedence_are_parsed_safely(): void
    {
        // Text inside a quoted extension value is not an independent cache restriction.
        Http::fakeSequence()->push('initial', 200, ['Cache-Control' => 'extension="no-store, private", max-age="60"']);
        $pending = Http::withHeaders(['Cache-Control' => 'max-age=60', 'Pragma' => 'no-cache'])->remember(60);

        // Cache-Control takes precedence over Pragma while ordinary quoted extensions stay cacheable.
        self::assertSame('initial', $pending->get(self::API_URL)->body());
        self::assertSame('initial', $pending->get(self::API_URL)->body());
        Http::assertSentCount(1);
    }

    /**
     * Confirm entries created by the old permissive policy cannot survive the default-policy change.
     */
    public function test_previous_cache_namespace_is_not_reused(): void
    {
        // Move a valid response payload into the previous key namespace to simulate an upgrade.
        Http::fakeSequence()->push('old-policy')->push('new-policy');
        Http::remember(60)->get(self::API_URL);
        $cache = Cache::store();
        $key = array_key_first($cache->getStore()->all());
        $cache->put(str_replace('http-remember:v2:', 'http-remember:', $key), $cache->get($key), 60);
        $cache->forget($key);

        // Populate through the new policy rather than trusting an older eligibility decision.
        self::assertSame('new-policy', Http::remember(60)->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm all safe methods remain cacheable without selecting an invalidation group.
     *
     * @param  string  $method  The method automatically treated as a read.
     */
    #[DataProvider('safeMethods')]
    public function test_safe_methods_are_cacheable_by_default(string $method): void
    {
        // Reuse a successful representation without requiring explicit read intent.
        Http::fakeSequence()->push('initial');
        self::assertSame(200, Http::remember(60)->send($method, self::API_URL)->status());
        self::assertSame(200, Http::remember(60)->send($method, self::API_URL)->status());
        Http::assertSentCount(1);
    }

    /**
     * Provide the non-mutation methods accepted by the default operation policy.
     *
     * @return iterable<string, array{string}> Safe HTTP method names.
     */
    public static function safeMethods(): iterable
    {
        // Keep the public default consistent for both grouped and ungrouped requests.
        foreach (['GET', 'HEAD', 'OPTIONS', 'TRACE'] as $method) {
            yield $method => [$method];
        }
    }

    /**
     * Confirm a cacheable POST query cannot authorize a later unmarked POST mutation.
     */
    public function test_mutations_ignore_existing_stale_entries_and_response_predicates(): void
    {
        // Populate a POST read whose identical request will be stale on its next use.
        Http::fakeSequence()->push('cached-query')->push('first-write')->push('second-write');
        Http::remember([0, 60], operation: 'read')->post(self::API_URL);
        $pending = Http::remember([0, 60], cacheWhen:
            /**
             * Make accidental mutation admission observable before it can skip a real write.
             */
            static function (Response $response): bool {
                // Actual mutation responses must never reach a response admission callback.
                self::fail('The mutation reached the caching predicate.');
            },
        );

        // Both writes must execute independently and neither may schedule a deferred repeat.
        self::assertSame('first-write', $pending->post(self::API_URL)->body());
        self::assertSame('second-write', $pending->post(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(3);
    }

    /**
     * Confirm request max-stale can require a live answer within an otherwise permitted stale period.
     */
    public function test_request_max_stale_narrows_the_configured_window(): void
    {
        // Allow a long application stale period before imposing a shorter request-specific allowance.
        Carbon::setTestNow(self::STARTED_AT);
        Http::fakeSequence()->push('initial')->push('updated');
        Http::remember([10, 60])->get(self::API_URL);
        Carbon::setTestNow(Carbon::now()->addSeconds(15));

        // Five stale seconds exceed the caller's two-second allowance and must fetch live data.
        self::assertSame('updated', Http::withHeaders(['Cache-Control' => 'max-stale=2'])->remember([10, 60])->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);
    }

    /**
     * Confirm async cache-only requests retain the network prohibition during cache failure.
     */
    public function test_cache_only_store_failure_does_not_fall_back_to_the_network(): void
    {
        // Make store resolution fail while the fake transport remains available to detect any fallback.
        Http::fakeSequence()->push('unexpected');
        Cache::shouldReceive('store')->once()->andThrow(new RuntimeException('The cache is unavailable.'));
        $response = Http::async()->withHeaders(['Cache-Control' => 'only-if-cached'])->remember(60)->get(self::API_URL)->wait();

        // Preserve the ordinary HTTP response contract without performing live work.
        self::assertSame(504, $response->status());
        self::assertSame([], $response->handlerStats());
        Http::assertSentCount(0);
    }

    /**
     * Confirm a restrictive response cannot remove a concurrently replaced generation.
     */
    public function test_restrictive_refresh_preserves_a_concurrent_replacement(): void
    {
        // Replace the original response while its deferred network transfer is still in progress.
        Carbon::setTestNow(self::STARTED_AT);
        $calls = 0;
        Http::fake(
            /**
             * Simulate another worker committing before this refresh returns no-store.
             */
            static function () use (&$calls) {
                // The first call populates the ordinary stale-while-revalidate entry.
                if (++$calls === 1) {
                    return Http::response('initial');
                }

                // Keep the replacement's generation distinct from the response being retired.
                $cache = Cache::store();
                $key = array_key_first($cache->getStore()->all());
                $replacement = HttpRememberResponse::capture(new PsrResponse(200, [], 'concurrent-replacement'));
                $cache->put($key, $replacement->toArray(), 60);

                return Http::response('restricted', 200, ['Cache-Control' => 'no-store']);
            },
        );
        Http::remember([10, 60])->get(self::API_URL);
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        Http::remember([10, 60])->get(self::API_URL);

        // Only the originally observed generation can be removed by the restrictive response.
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame('concurrent-replacement', Http::remember([10, 60])->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm minimum freshness accepts the exact requested amount of fresh time.
     */
    public function test_request_minimum_freshness_includes_its_boundary(): void
    {
        // Seed a response whose remaining fresh time will equal the caller's requirement.
        Carbon::setTestNow(self::STARTED_AT);
        Http::fakeSequence()->push('initial')->push('updated');
        Http::remember(60)->get(self::API_URL);
        Carbon::setTestNow(Carbon::now()->addSeconds(50));
        $pending = Http::withHeaders(['Cache-Control' => 'min-fresh=10'])->remember(60);

        // Reuse the entry at the inclusive boundary and fetch live once less freshness remains.
        self::assertSame('initial', $pending->get(self::API_URL)->body());
        Carbon::setTestNow(Carbon::now()->addSecond());
        self::assertSame('updated', $pending->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }
}
