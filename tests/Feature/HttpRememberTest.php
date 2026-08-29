<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use RuntimeException;

/**
 * Verify remembered requests through Laravel's public HTTP client API.
 */
final class HttpRememberTest extends TestCase
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
        $secondStale = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Confirm stale data was served without another immediate API call.
        self::assertSame(self::INITIAL_VERSION, $firstStale->json('version'));
        self::assertSame(self::INITIAL_VERSION, $secondStale->json('version'));
        Http::assertSentCount(1);

        // Confirm duplicate stale calls share one named deferred callback.
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
     * Confirm authorization headers separate otherwise identical cache entries.
     */
    public function test_bearer_tokens_create_separate_cache_entries(): void
    {
        // Return one response generation for each bearer token.
        $this->fakeVersionSequence();

        // Repeat the resource request with two distinct credentials.
        $firstToken = Http::withToken('first-token')->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $firstTokenAgain = Http::withToken('first-token')->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $secondToken = Http::withToken('second-token')->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $secondTokenAgain = Http::withToken('second-token')->remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm each credential reused only its own cached response.
        self::assertSame(self::INITIAL_VERSION, $firstToken->json('version'));
        self::assertSame(self::INITIAL_VERSION, $firstTokenAgain->json('version'));
        self::assertSame(self::UPDATED_VERSION, $secondToken->json('version'));
        self::assertSame(self::UPDATED_VERSION, $secondTokenAgain->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm methods and request bodies participate in cache identity.
     */
    public function test_methods_and_payloads_create_separate_cache_entries(): void
    {
        // Return a distinct response for each unique request representation.
        Http::fakeSequence()
            ->push(['result' => 'first-post'], HttpStatus::HTTP_OK)
            ->push(['result' => 'second-post'], HttpStatus::HTTP_OK)
            ->push(['result' => 'get'], HttpStatus::HTTP_OK);

        // Send two POST payloads and one GET request to the same URL.
        $firstPost = Http::remember(self::LIFETIME_SECONDS)->post(self::API_URL, ['query' => 'first']);
        $secondPost = Http::remember(self::LIFETIME_SECONDS)->post(self::API_URL, ['query' => 'second']);
        $get = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Repeat every representation to exercise its independent cache entry.
        $firstPostAgain = Http::remember(self::LIFETIME_SECONDS)->post(self::API_URL, ['query' => 'first']);
        $secondPostAgain = Http::remember(self::LIFETIME_SECONDS)->post(self::API_URL, ['query' => 'second']);
        $getAgain = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm the correct response stayed attached to every representation.
        self::assertSame($firstPost->json(), $firstPostAgain->json());
        self::assertSame($secondPost->json(), $secondPostAgain->json());
        self::assertSame($get->json(), $getAgain->json());
        Http::assertSentCount(3);
    }

    /**
     * Confirm cache policies are separated and repeated macro calls are replaced.
     */
    public function test_latest_remember_policy_replaces_an_earlier_policy(): void
    {
        // Return separate generations if multiple cache policies reach the handler.
        $this->fakeVersionSequence();

        // Apply two policies to one builder so only the last policy remains active.
        $first = Http::remember(self::FRESH_SECONDS)
            ->remember(self::LIFETIME_SECONDS)
            ->get(self::API_URL);

        // Request the same resource using the final policy directly.
        $samePolicy = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Use another policy to confirm lifetime settings separate cache entries.
        $differentPolicy = Http::remember(self::FRESH_SECONDS)->get(self::API_URL);

        // Confirm replacement avoided nesting while the distinct policy fetched once.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::INITIAL_VERSION, $samePolicy->json('version'));
        self::assertSame(self::UPDATED_VERSION, $differentPolicy->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm custom cURL options bypass response caching.
     */
    public function test_custom_curl_options_bypass_the_cache(): void
    {
        // Return a different response whenever the handler actually executes.
        $this->fakeVersionSequence();

        // Repeat a request carrying a test-only custom cURL option.
        $first = Http::withOptions(['curl' => ['test-option' => true]])
            ->remember(self::LIFETIME_SECONDS)
            ->get(self::API_URL);
        $second = Http::withOptions(['curl' => ['test-option' => true]])
            ->remember(self::LIFETIME_SECONDS)
            ->get(self::API_URL);

        // Confirm both requests passed through to preserve custom transport behavior.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::UPDATED_VERSION, $second->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm later request mutation callbacks bypass response caching.
     */
    public function test_later_before_sending_callbacks_bypass_the_cache(): void
    {
        // Return a different response whenever the handler actually executes.
        $this->fakeVersionSequence();

        // Add a request mutation hook after remember has calculated its stack position.
        $first = Http::remember(self::LIFETIME_SECONDS)
            ->beforeSending(
                /**
                 * Represent an application callback that could mutate the request.
                 */
                static function (): void {},
            )
            ->get(self::API_URL);
        $second = Http::remember(self::LIFETIME_SECONDS)
            ->beforeSending(
                /**
                 * Represent an application callback that could mutate the request.
                 */
                static function (): void {},
            )
            ->get(self::API_URL);

        // Confirm potentially mutated requests were not cached.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::UPDATED_VERSION, $second->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm asynchronous requests retain their promise contract on cache hits.
     */
    public function test_async_requests_are_remembered(): void
    {
        // Return a second generation if the cached async request reaches the handler.
        $this->fakeVersionSequence();

        // Resolve the initial asynchronous request before making the cached request.
        $firstPromise = Http::async()->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        self::assertInstanceOf(PromiseInterface::class, $firstPromise);
        $first = $firstPromise->wait();

        // Resolve the same request from its remembered response.
        $secondPromise = Http::async()->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        self::assertInstanceOf(PromiseInterface::class, $secondPromise);
        $second = $secondPromise->wait();

        // Confirm both promise paths produced the initial API representation.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::INITIAL_VERSION, $second->json('version'));
        Http::assertSentCount(1);
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
        // Create a repository that permits the read and rejects persistence.
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('get')->once()->andReturnNull();
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
