<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify deferred redirects preserve transport policies, cache identities, and invalidation.
 */
final class RefreshRedirectTest extends TestCase
{
    /**
     * The source resource whose successful response later becomes a redirect.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The destination reached by same-origin redirects.
     */
    private const DESTINATION_URL = 'https://api.example.test/catalog';

    /**
     * Freeze response ages without consuming the real network timeout budget.
     */
    protected function setUp(): void
    {
        // Boot the isolated application before establishing the original response time.
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    }

    /**
     * Confirm stale callers defer redirects and later reads reuse the destination's own cache entry.
     *
     * @param  bool  $async  Whether the stale caller receives a promise.
     */
    #[DataProvider('requestModes')]
    public function test_refresh_follows_redirects_and_retires_the_old_source(bool $async): void
    {
        // Keep the redirect live while making the destination independently cacheable.
        Http::fake([
            self::API_URL => Http::sequence()->push('initial')->push('', 302, ['Location' => '/catalog'])->push('', 302, ['Location' => '/catalog']),
            self::DESTINATION_URL => Http::response('updated'),
        ]);

        // Populate the source and retain its key before it starts returning redirects.
        Http::remember([10, 60])->get(self::API_URL);
        $key = array_key_first(Cache::store()->getStore()->all());

        // Return stale data before any redirect work starts, in either execution mode.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $stale = Http::async($async)->remember([10, 60])->get(self::API_URL);
        self::assertSame('initial', ($async ? $stale->wait() : $stale)->body());
        Http::assertSentCount(1);

        // Execute the queued redirect chain after the caller has received stale data.
        app(DeferredCallbackCollection::class)->invoke();

        // Remove the obsolete source and warm the destination without scheduling another refresh.
        self::assertNull(Cache::get($key));
        self::assertSame('updated', Http::remember([10, 60])->get(self::DESTINATION_URL)->body());
        Http::assertSentCount(3);
        self::assertCount(0, app(DeferredCallbackCollection::class));

        // Following the source again needs only its live redirect, just like a foreground request.
        self::assertSame('updated', Http::remember([10, 60])->get(self::API_URL)->body());
        Http::assertSentCount(4);
    }

    /**
     * Provide the public execution modes supported by remembered requests.
     *
     * @return iterable<string, array{bool}> Modes keyed by promise behavior.
     */
    public static function requestModes(): iterable
    {
        // Cover immediate responses and fulfilled promises from the same stale entry.
        yield 'synchronous' => [false];
        yield 'asynchronous' => [true];
    }

    /**
     * Confirm redirect restrictions preserve the stale source and start the normal cooldown.
     *
     * @param  bool|array<string, mixed>  $redirects  The caller's redirect policy.
     * @param  string  $location  The first redirect's destination.
     * @param  int  $sent  The number of transfers permitted by this policy.
     */
    #[DataProvider('restrictedRedirects')]
    public function test_redirect_failures_preserve_the_source(bool|array $redirects, string $location, int $sent): void
    {
        // Include another redirect so the maximum-count policy can fail partway through a chain.
        Http::fakeSequence()->push('initial')->push('', 302, ['Location' => $location])->push('', 302, ['Location' => '/final']);

        // Capture the original payload and expiry under the caller's redirect restrictions.
        $pending = Http::withOptions(['allow_redirects' => $redirects])->remember([10, 60]);
        $pending->get(self::API_URL);
        $key = array_key_first(Cache::store()->getStore()->all());
        $original = Cache::get($key);

        // Keep redirect exceptions inside deferred work and retain the original response lifetime.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $pending->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Confirm the failed chain preserved the source and suppressed another immediate refresh.
        self::assertSame($original, Cache::get($key));
        self::assertSame('initial', $pending->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount($sent);
    }

    /**
     * Provide disabled redirects, bounded chains, and prohibited destination protocols.
     *
     * @return iterable<string, array{bool|array<string, mixed>, string, int}> Redirect failure cases.
     */
    public static function restrictedRedirects(): iterable
    {
        // Exercise disabled redirects and the limits enforced by Guzzle during a chain.
        yield 'disabled' => [false, '/catalog', 2];
        yield 'zero redirects' => [['max' => 0], '/catalog', 2];
        yield 'redirect limit' => [['max' => 1], '/catalog', 3];
        yield 'protocol restriction' => [['protocols' => ['https']], 'http://api.example.test/catalog', 2];
    }

    /**
     * Confirm Guzzle applies the configured POST redirect semantics to grouped read operations.
     *
     * @param  int  $status  The upstream redirect status.
     * @param  bool  $strict  Whether redirects retain POST for 301 and 302 responses.
     * @param  string  $method  The expected method at the destination.
     * @param  string  $body  The expected destination upload.
     */
    #[DataProvider('postRedirects')]
    public function test_post_refresh_preserves_redirect_method_and_body_rules(int $status, bool $strict, string $method, string $body): void
    {
        // Record the destination after Guzzle has rewritten or rewound the original upload.
        $destination = null;
        Http::fake([
            self::API_URL => Http::sequence()->push('initial')->push('', $status, ['Location' => '/catalog']),
            self::DESTINATION_URL =>
                /**
                 * Retain the request for assertions without consuming its body before transport.
                 *
                 * @param  Request  $request  The prepared destination request.
                 * @return PromiseInterface The successful destination response.
                 */
                static function (Request $request) use (&$destination): PromiseInterface {
                    // Keep the request after redirect method and body preparation.
                    $destination = $request;

                    // Complete the destination with a response eligible for caching.
                    return Http::response('updated');
                },
        ]);

        // Populate a grouped POST read and retain the generation that must survive its refresh.
        $pending = Http::withBody('query')->withOptions(['allow_redirects' => ['strict' => $strict]])
            ->remember([10, 60], group: ['products'], operation: 'read');
        $pending->post(self::API_URL);
        $groupKey = array_key_first(Cache::store()->getStore()->all());
        $generation = Cache::get($groupKey);

        // Refresh the grouped POST without interpreting the replay as a mutation.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        self::assertSame('initial', $pending->withBody('query')->post(self::API_URL)->body());
        Http::assertSentCount(1);

        // Follow the deferred redirect before inspecting its rewritten method and upload.
        app(DeferredCallbackCollection::class)->invoke();

        // Verify the destination received the expected body without invalidating the read group.
        self::assertNotNull($destination);
        self::assertSame($method, $destination->method());
        self::assertSame($body, $destination->body());
        self::assertSame($body === '' ? [] : ['5'], $destination->header('Content-Length'));
        self::assertSame($generation, Cache::get($groupKey));
        Http::assertSentCount(3);
    }

    /**
     * Provide browser-style and strict redirects for replayable POST reads.
     *
     * @return iterable<string, array{int, bool, string, string}> Method and body expectations.
     */
    public static function postRedirects(): iterable
    {
        // Cover redirects that discard POST bodies and redirects that replay them intact.
        yield 'ordinary 302' => [302, false, 'GET', ''];
        yield 'strict 302' => [302, true, 'POST', 'query'];
        yield 'strict 303' => [303, true, 'GET', ''];
        yield '307' => [307, false, 'POST', 'query'];
        yield '308' => [308, false, 'POST', 'query'];
    }

    /**
     * Confirm destination sessions cannot leak through a source URL shared by different cookie jars.
     */
    public function test_cross_origin_refresh_isolates_credentials_cookies_and_cached_responses(): void
    {
        // Give only the destination a session cookie, leaving source request identities identical.
        $destination = 'https://accounts.example.test/profile';
        $jar = CookieJar::fromArray(['session' => 'alice'], 'accounts.example.test');
        Http::fake([
            self::API_URL => Http::sequence()->push('initial')->push('', 302, ['Location' => $destination])->push('', 302, ['Location' => $destination]),
            $destination =>
                /**
                 * Require cross-origin authorization stripping and return the selected session.
                 *
                 * @param  Request  $request  The destination request with its own session cookie.
                 * @return PromiseInterface The private response and newly issued cookie.
                 */
                static function (Request $request): PromiseInterface {
                    // Reject source credentials and identify the destination's selected session.
                    self::assertSame([], $request->header('Authorization'));
                    $body = str_contains(implode(';', $request->header('Cookie')), 'session=alice') ? 'alice-private' : 'bob-private';

                    // Issue a replacement cookie to verify the deferred jar remains isolated.
                    return Http::response($body, 200, ['Set-Cookie' => 'session=rotated; Path=/']);
                },
        ]);

        // Cache the source using credentials that must not reach the other origin.
        $pending = Http::withToken('source-token')->withOptions(['cookies' => $jar])->remember([10, 60]);
        $pending->get(self::API_URL);

        // Refresh the stale source through a redirect to the session-specific destination.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $pending->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Keep deferred cookie changes out of the caller's jar and fetch session-issuing destinations live.
        self::assertSame('alice', $jar->getCookieByName('session')->getValue());
        self::assertSame('alice-private', Http::withOptions(['cookies' => $jar])->remember([10, 60])->get($destination)->body());
        Http::assertSentCount(4);

        // A caller with the same source identity must follow the redirect using its own session.
        $otherJar = CookieJar::fromArray(['session' => 'bob'], 'accounts.example.test');
        self::assertSame('bob-private', Http::withToken('source-token')->withOptions(['cookies' => $otherJar])
            ->remember([10, 60])->get(self::API_URL)->body());
        Http::assertSentCount(6);
    }

    /**
     * Confirm invalidation during a redirect cancels destination writes and preserves replacements.
     *
     * @param  bool  $grouped  Whether invalidation rotates a group or removes the source directly.
     */
    #[DataProvider('invalidationModes')]
    public function test_in_flight_invalidation_cancels_redirected_storage(bool $grouped): void
    {
        // Invalidate after the source redirect but before its destination can be remembered.
        $key = null;
        $group = $grouped ? ['products'] : null;
        Http::fake([
            self::API_URL => Http::sequence()->push('initial')->push('', 302, ['Location' => '/catalog']),
            self::DESTINATION_URL =>
                /**
                 * Simulate a concurrent invalidation while the destination request is in flight.
                 *
                 * @return PromiseInterface The response that must not populate the invalidated cache.
                 */
                static function () use (&$key, $grouped, $group): PromiseInterface {
                    // Rotate the group generation or remove the exact source entry.
                    if ($grouped) {
                        Http::remember(group: $group, invalidate: true);
                    } else {
                        Cache::forget($key);
                    }

                    // Complete the transfer after its permission to populate the cache was removed.
                    return Http::response('invalidated-body');
                },
        ]);

        // Populate the source using the selected invalidation mode.
        $pending = Http::remember([10, 60], group: $group);
        $pending->get(self::API_URL);

        // Find the response entry without confusing it with scalar group metadata.
        foreach (Cache::store()->getStore()->all() as $candidate => $entry) {
            // Retain only the key whose payload contains a serialized response.
            if (is_array($entry['value'])) {
                $key = $candidate;
            }
        }

        // Finish the chain without making its invalidated result reachable from another URL.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $pending->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Check every retained entry for a response written after invalidation.
        foreach (Cache::store()->getStore()->all() as $entry) {
            self::assertNotSame('invalidated-body', is_array($entry['value']) ? ($entry['value']['body'] ?? null) : null);
        }

        // Confirm cancellation affected persistence without skipping the in-flight transfer.
        Http::assertSentCount(3);
    }

    /**
     * Provide direct and grouped invalidation during a refresh.
     *
     * @return iterable<string, array{bool}> Invalidation modes.
     */
    public static function invalidationModes(): iterable
    {
        // Exercise both source generation removal and shared group generation replacement.
        yield 'source removal' => [false];
        yield 'group invalidation' => [true];
    }

    /**
     * Confirm response eligibility still controls whether a redirect can retire stale data.
     */
    public function test_rejected_destination_preserves_the_source(): void
    {
        // Reject only the destination's application error, even though its status is successful.
        Http::fakeSequence()->push('initial')->push('', 302, ['Location' => '/catalog'])->push('application-error');

        // Store the accepted source and retain its original expiry for comparison.
        $pending = Http::remember([10, 60], cacheWhen: static fn (Response $response): bool => $response->body() !== 'application-error');
        $pending->get(self::API_URL);
        $key = array_key_first(Cache::store()->getStore()->all());
        $original = Cache::get($key);

        // Keep the original payload and expiry instead of deleting it after a rejected redirect.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $pending->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Confirm predicate rejection preserved the source without adding a destination entry.
        self::assertSame($original, Cache::get($key));
        self::assertCount(1, Cache::store()->getStore()->all());
        Http::assertSentCount(3);
    }

    /**
     * Confirm a destination failure retains the source and applies the final response's cooldown.
     */
    public function test_failed_destination_preserves_the_source_and_honors_retry_after(): void
    {
        // Fail only after following the redirect, with a delay longer than the configured minimum.
        Http::fakeSequence()->push('initial')->push('', 302, ['Location' => '/catalog'])->push('unavailable', 503, ['Retry-After' => '30']);

        // Capture the source before the redirect chain encounters the unavailable destination.
        Http::remember([10, 60])->get(self::API_URL);
        $key = array_key_first(Cache::store()->getStore()->all());
        $original = Cache::get($key);

        // Start the destination's requested cooldown from the failed deferred attempt.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        Http::remember([10, 60])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Keep the original expiry and suppress another attempt after the ordinary cooldown elapses.
        Carbon::setTestNow(Carbon::now()->addSeconds(20));
        self::assertSame($original, Cache::get($key));
        self::assertSame('initial', Http::remember([10, 60])->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(3);
    }

    /**
     * Confirm a concurrent destination cache entry is preserved while the obsolete source is removed.
     */
    public function test_existing_destination_is_preserved_in_the_original_store(): void
    {
        // Cache the destination independently before the source begins redirecting there.
        Http::fake([
            self::API_URL => Http::sequence()->push('initial')->push('', 302, ['Location' => '/catalog']),
            self::DESTINATION_URL => Http::sequence()->push('existing-destination')->push('refresh-destination'),
        ]);

        // Retain the source key so its later removal can be checked in the original store.
        Http::remember([10, 60])->get(self::API_URL);
        $sourceKey = array_key_first(Cache::store()->getStore()->all());

        // Give the destination a newer entry that remains fresh when the source becomes stale.
        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        Http::remember([10, 60])->get(self::DESTINATION_URL);

        // Queue the source refresh before changing the application's default store.
        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        Http::remember([10, 60])->get(self::API_URL);

        // Capture deferred diagnostics to verify they retain the store selected by the stale caller.
        $events = [];
        app('events')->listen(HttpRememberEventLog::class,
            /**
             * Retain refresh diagnostics after the application's mutable default changes.
             *
             * @param  HttpRememberEventLog  $event  The published cache or refresh decision.
             */
            static function (HttpRememberEventLog $event) use (&$events): void {
                // Keep every event so destination and source diagnostics are checked together.
                $events[] = $event;
            },
        );

        // Deferred network work, destination storage, and diagnostics must retain the original store.
        config(['cache.stores.other' => ['driver' => 'array'], 'cache.default' => 'other']);
        app(DeferredCallbackCollection::class)->invoke();

        // Confirm source removal and destination storage did not use the replacement default.
        self::assertNull(Cache::store('array')->get($sourceKey));
        self::assertSame([], Cache::store('other')->getStore()->all());

        // Require each emitted diagnostic to identify the store used by the refresh.
        self::assertNotEmpty($events);
        foreach ($events as $event) {
            self::assertSame('array', $event->store);
        }

        // Retiring the source must not overwrite a response another caller already cached.
        self::assertSame('existing-destination', Http::remember([10, 60], store: 'array')->get(self::DESTINATION_URL)->body());
        Http::assertSentCount(4);
    }

    /**
     * Confirm redirect callbacks and transfers share one wall-clock refresh budget.
     */
    public function test_redirect_chain_cannot_restart_the_refresh_timeout(): void
    {
        // Exhaust a short request timeout inside Guzzle's redirect callback.
        Http::fakeSequence()->push('initial')->push('', 302, ['Location' => '/catalog']);

        // Collect deferred failures without allowing exceptions to escape to the stale caller.
        $failures = [];
        app('events')->listen(HttpRememberEventLog::class,
            /**
             * Retain the safe exception class reported by deferred timeout handling.
             *
             * @param  HttpRememberEventLog  $event  The published cache or refresh decision.
             */
            static function (HttpRememberEventLog $event) use (&$failures): void {
                // Ignore ordinary cache decisions while retaining refresh failure diagnostics.
                if ($event->event === 'refresh_failed') {
                    $failures[] = $event->exception;
                }
            },
        );

        // Use a redirect callback that consumes more time than the entire request allows.
        $pending = Http::withOptions(['timeout' => 0.01, 'allow_redirects' => ['on_redirect' =>
            /**
             * Spend more than the entire refresh budget without making a real network request.
             */
            static function (): void {
                // Advance the real timeout clock without changing the frozen response ages.
                usleep(20_000);
            },
        ]])->remember([10, 60]);

        // Cache the initial response before triggering the slow redirect during deferred work.
        $pending->get(self::API_URL);

        // Reach the stale period and execute the refresh with its short timeout budget.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $pending->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Do not start another hop after the deadline, and retain the stale response under cooldown.
        self::assertContains(ConnectException::class, $failures);
        self::assertSame('initial', $pending->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);
    }

    /**
     * Confirm a refresh starts its own redirect count after a foreground redirect reaches stale data.
     */
    public function test_refresh_resets_the_foreground_redirect_count(): void
    {
        // Each foreground request uses the entire one-redirect allowance before reaching the source.
        $entry = 'https://api.example.test/entry';
        Http::fake([
            $entry => Http::response('', 302, ['Location' => self::API_URL]),
            self::API_URL => Http::sequence()->push('initial')->push('', 302, ['Location' => '/catalog']),
            self::DESTINATION_URL => Http::response('updated'),
        ]);

        // Use the full foreground redirect allowance while populating the source response.
        $pending = Http::withOptions(['allow_redirects' => ['max' => 1]])->remember([10, 60]);
        $pending->get($entry);

        // Reach stale data through another foreground redirect before running its deferred refresh.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        self::assertSame('initial', $pending->get($entry)->body());
        app(DeferredCallbackCollection::class)->invoke();

        // The independent deferred chain may still follow its own single redirect and warm the target.
        self::assertSame('updated', Http::remember([10, 60])->get(self::DESTINATION_URL)->body());
        Http::assertSentCount(5);
    }
}
