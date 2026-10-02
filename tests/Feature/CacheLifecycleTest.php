<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PhilipAnnis\HttpRemember\HttpRememberResponse;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use RuntimeException;

/**
 * Verify remembered requests through Laravel's public HTTP client API.
 */
final class CacheLifecycleTest extends TestCase
{
    /**
     * The shared fake endpoint used by identical-request tests.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The instant used as the start of time-sensitive cache policies.
     */
    private const STARTED_AT = '2026-01-01 12:00:00';

    /**
     * The first response generation returned by fake APIs.
     */
    private const INITIAL_VERSION = 1;

    /**
     * The next response generation returned by fake APIs.
     */
    private const UPDATED_VERSION = 2;

    /**
     * The age at which a stale-while-revalidate response becomes stale.
     */
    private const FRESH_SECONDS = 30;

    /**
     * The total duration of short test cache policies.
     */
    private const LIFETIME_SECONDS = 60;

    /**
     * The configured limit applied to deferred network operations.
     */
    private const REFRESH_TIMEOUT_SECONDS = 15;

    /**
     * Confirm the default policy stores and restores a successful response.
     */
    public function test_default_policy_remembers_a_successful_response(): void
    {
        // Return metadata that must survive serialization through the cache store.
        Http::fake([
            self::API_URL => Http::response(
                ['version' => self::INITIAL_VERSION],
                HttpStatus::HTTP_CREATED,
                ['X-Response-Version' => (string) self::INITIAL_VERSION],
            ),
        ]);

        // Send the same request twice through independently created builders.
        $first = Http::remember()->get(self::API_URL);
        $second = Http::remember()->get(self::API_URL);

        // Confirm the second response retained the original API representation.
        self::assertSame(HttpStatus::HTTP_CREATED, $second->status());
        self::assertSame((string) self::INITIAL_VERSION, $second->header('X-Response-Version'));
        self::assertSame($first->json(), $second->json());

        // Confirm only the initial request reached the fake API handler.
        Http::assertSentCount(1);
    }

    /**
     * Confirm a fixed policy fetches a new response at hard expiry.
     */
    public function test_fixed_policy_expires_at_its_lifetime(): void
    {
        // Freeze time so the cache boundary can be tested without sleeping.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);

        // Return a distinct response when the fixed cache entry expires.
        $this->fakeVersionSequence();

        // Populate the cache and remain immediately inside its fixed lifetime.
        $initial = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::LIFETIME_SECONDS - 1));
        $remembered = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm the unexpired response avoided a second handler execution.
        self::assertSame(self::INITIAL_VERSION, $initial->json('version'));
        self::assertSame(self::INITIAL_VERSION, $remembered->json('version'));
        Http::assertSentCount(1);

        // Reach the exact expiry boundary and request the resource again.
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::LIFETIME_SECONDS));
        $expired = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm hard expiry synchronously fetched and stored the next generation.
        self::assertSame(self::UPDATED_VERSION, $expired->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm stale calls return immediately and schedule one deferred refresh.
     */
    public function test_stale_response_is_returned_and_refreshed_once(): void
    {
        // Freeze time at the start of the stale-while-revalidate policy.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);

        // Return one generation for population and another for the refresh.
        $this->fakeVersionSequence();

        // Populate the cache while the response is fresh.
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Move to the stale boundary and make duplicate stale requests.
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::FRESH_SECONDS));
        $firstStale = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        $secondStale = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS], store: 'array')->get(self::API_URL);

        // Confirm stale data was served without another immediate API call.
        self::assertSame(self::INITIAL_VERSION, $firstStale->json('version'));
        self::assertSame(self::INITIAL_VERSION, $secondStale->json('version'));
        Http::assertSentCount(1);

        // Confirm implicit and explicit names for one store share a deferred callback.
        $callbacks = app(DeferredCallbackCollection::class);
        self::assertCount(1, $callbacks);

        // Run the deferred lifecycle and read the refreshed response.
        $callbacks->invoke();
        $refreshed = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Confirm exactly one refresh replaced the stale generation.
        self::assertSame(self::UPDATED_VERSION, $refreshed->json('version'));
        Http::assertSentCount(2);
        self::assertCount(0, $callbacks);
    }

    /**
     * Confirm changing the default store does not discard another store's refresh.
     */
    public function test_deferred_refreshes_keep_separate_default_stores(): void
    {
        // Prepare two independent stores that use the same empty key prefix.
        config(['cache.stores.secondary' => ['driver' => 'array']]);
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()
            ->push(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK)
            ->push(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK)
            ->push(['version' => self::UPDATED_VERSION], HttpStatus::HTTP_OK)
            ->push(['version' => self::UPDATED_VERSION], HttpStatus::HTTP_OK);

        // Populate the same request through each application's current default store.
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        Cache::setDefaultDriver('secondary');
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Schedule both refreshes after changing the default store between stale calls.
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::FRESH_SECONDS));
        Cache::setDefaultDriver('array');
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        Cache::setDefaultDriver('secondary');
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        $callbacks = app(DeferredCallbackCollection::class);
        self::assertCount(2, $callbacks);
        $callbacks->invoke();

        // Confirm each deferred callback refreshed its originally selected store.
        $secondary = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        Cache::setDefaultDriver('array');
        $primary = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        self::assertSame(self::UPDATED_VERSION, $secondary->json('version'));
        self::assertSame(self::UPDATED_VERSION, $primary->json('version'));
        Http::assertSentCount(4);
        self::assertCount(0, $callbacks);
    }

    /**
     * Confirm an unsuccessful deferred refresh leaves stale data available.
     */
    public function test_failed_refresh_preserves_the_stale_response(): void
    {
        // Freeze time so the stored response can enter its stale period.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);

        // Populate successfully before returning refresh failures.
        Http::fakeSequence()
            ->push(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK)
            ->push(['error' => 'Unavailable'], HttpStatus::HTTP_SERVICE_UNAVAILABLE)
            ->push(['error' => 'Unavailable'], HttpStatus::HTTP_SERVICE_UNAVAILABLE);

        // Populate the initial response and move it into the stale period.
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::FRESH_SECONDS));

        // Return stale data and execute its unsuccessful deferred refresh.
        $beforeRefresh = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Request the same stale entry after the refresh failure.
        $afterRefresh = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Confirm the failed refresh did not replace or extend the cached response.
        self::assertSame(self::INITIAL_VERSION, $beforeRefresh->json('version'));
        self::assertSame(self::INITIAL_VERSION, $afterRefresh->json('version'));

        // Complete the newly scheduled callback to keep the test lifecycle isolated.
        app(DeferredCallbackCollection::class)->invoke();
        Http::assertSentCount(3);
    }

    /**
     * Confirm unsuccessful foreground responses are never stored.
     */
    public function test_unsuccessful_responses_are_not_remembered(): void
    {
        // Return a failure before allowing the next request to succeed.
        Http::fakeSequence()
            ->push(['error' => 'Unavailable'], HttpStatus::HTTP_SERVICE_UNAVAILABLE)
            ->push(['version' => self::UPDATED_VERSION], HttpStatus::HTTP_OK);

        // Repeat the request after its first response fails.
        $failed = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $successful = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $remembered = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm only the successful response became reusable.
        self::assertSame(HttpStatus::HTTP_SERVICE_UNAVAILABLE, $failed->status());
        self::assertSame(self::UPDATED_VERSION, $successful->json('version'));
        self::assertSame(self::UPDATED_VERSION, $remembered->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm connection failures are never remembered.
     */
    public function test_connection_failures_are_not_remembered(): void
    {
        // Return a transport failure before allowing the next request to succeed.
        Http::fakeSequence()
            ->pushFailedConnection('Test connection failure.')
            ->push(['version' => self::UPDATED_VERSION], HttpStatus::HTTP_OK);

        // Capture the expected foreground connection failure without ending the test.
        try {
            Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

            // Fail explicitly if Laravel did not preserve its connection exception.
            self::fail('The remembered request did not throw the expected connection exception.');
        } catch (ConnectionException $exception) {
            // Confirm the original fake transport failure reached the caller.
            self::assertSame('Test connection failure.', $exception->getMessage());
        }

        // Repeat the request after the rejected promise path completes.
        $successful = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm the failed request did not prevent a later response from being stored.
        self::assertSame(self::UPDATED_VERSION, $successful->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm a refresh rechecks its generation after buffering a slow response body.
     */
    public function test_refresh_does_not_overwrite_a_generation_replaced_during_capture(): void
    {
        // Freeze time and populate the initial cached generation.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        $cache = Cache::store();
        $body = Utils::streamFor(json_encode(['version' => self::INITIAL_VERSION]));

        // Replace the stale entry while the deferred response body is being read.
        $refreshBody = FnStream::decorate($body, [
            'getContents' =>
                /**
                 * Simulate another worker storing a newer response during buffering.
                 *
                 * @return string The older deferred response body.
                 */
                static function () use ($cache, $body): string {
                    // Write a new generation under the original request's cache key.
                    $key = array_key_first($cache->getStore()->all());
                    $replacement = HttpRememberResponse::capture(new Response(
                        HttpStatus::HTTP_OK,
                        [],
                        json_encode(['version' => self::UPDATED_VERSION]),
                    ));
                    $cache->put($key, $replacement->toArray(), self::LIFETIME_SECONDS);

                    // Let capture finish with the older refresh payload.
                    return $body->getContents();
                },
        ]);

        // Populate the cache before returning the response with controlled buffering.
        Http::fakeSequence()
            ->push(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK)
            ->push($refreshBody, HttpStatus::HTTP_OK);
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Schedule and execute the deferred refresh after the response becomes stale.
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::FRESH_SECONDS));
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Confirm buffering did not let the older refresh overwrite the replacement.
        $remembered = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        self::assertSame(self::UPDATED_VERSION, $remembered->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm deferred refreshes cap long and unlimited network timeouts.
     */
    public function test_deferred_refresh_applies_the_configured_timeout_cap(): void
    {
        // Freeze time and collect the transfer options received by the fake API.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        $observedOptions = [];

        // Record both foreground and deferred transfer options.
        Http::fake(
            /**
             * Capture handler options and return a successful fake response.
             *
             * @param  Request  $request  The outgoing fake request.
             * @param  array<string, mixed>  $options  The outgoing transfer options.
             * @return PromiseInterface The successful fake response promise.
             */
            function (Request $request, array $options) use (&$observedOptions): PromiseInterface {
                // Preserve the options for assertions after deferred execution.
                $observedOptions[] = $options;

                // Return a successful generation for every handler execution.
                return Http::response(['version' => count($observedOptions)], HttpStatus::HTTP_OK);
            },
        );

        // Populate a request with longer and unlimited network timeouts.
        Http::withOptions([
            'timeout' => self::LIFETIME_SECONDS,
            'connect_timeout' => self::FRESH_SECONDS,
            'read_timeout' => 0,
        ])->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Enter the stale period and execute the scheduled refresh.
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::FRESH_SECONDS));
        Http::withOptions([
            'timeout' => self::LIFETIME_SECONDS,
            'connect_timeout' => self::FRESH_SECONDS,
            'read_timeout' => 0,
        ])->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Select the options used by the deferred handler execution.
        self::assertCount(2, $observedOptions);
        $refreshOptions = $observedOptions[1];

        // Confirm every deferred network timeout uses the configured upper bound.
        self::assertSame((float) self::REFRESH_TIMEOUT_SECONDS, (float) $refreshOptions['timeout']);
        self::assertSame((float) self::REFRESH_TIMEOUT_SECONDS, (float) $refreshOptions['connect_timeout']);
        self::assertSame((float) self::REFRESH_TIMEOUT_SECONDS, (float) $refreshOptions['read_timeout']);
    }

    /**
     * Confirm a cache read exception falls back to the live HTTP request.
     */
    public function test_cache_read_failure_does_not_break_the_http_request(): void
    {
        // Replace the cache repository lookup with a controlled read failure.
        Cache::shouldReceive('store')
            ->once()
            ->with(null)
            ->andThrow(new RuntimeException('Test cache read failure.'));

        // Provide the live response that should remain available to the caller.
        Http::fake([
            self::API_URL => Http::response(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK),
        ]);

        // Send the remembered request while its cache store is unavailable.
        $response = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm the cache outage did not replace the normal API result.
        self::assertSame(self::INITIAL_VERSION, $response->json('version'));
        Http::assertSentCount(1);
    }

    /**
     * Confirm a cache write exception does not replace a successful response.
     */
    public function test_cache_write_failure_does_not_break_the_http_request(): void
    {
        // Retain the real in-memory lock provider before replacing the cache facade.
        $store = Cache::store()->getStore();

        // Create a repository that permits the read and rejects persistence.
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $cache->shouldReceive('getStore')->once()->andReturn($store);
        $cache->shouldReceive('put')->once()->andThrow(new RuntimeException('Test cache write failure.'));

        // Return the controlled repository for this remembered request.
        Cache::shouldReceive('store')->once()->with(null)->andReturn($cache);

        // Provide the successful live response that must reach the caller.
        Http::fake([
            self::API_URL => Http::response(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK),
        ]);

        // Send the request while cache persistence is unavailable.
        $response = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm the cache outage did not alter the successful response.
        self::assertSame(self::INITIAL_VERSION, $response->json('version'));
        Http::assertSentCount(1);
    }

    /**
     * Configure a fake API with two successful response generations.
     */
    private function fakeVersionSequence(): void
    {
        // Return different bodies so cache hits and live requests are observable.
        Http::fakeSequence()
            ->push(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK)
            ->push(['version' => self::UPDATED_VERSION], HttpStatus::HTTP_OK);
    }
}
