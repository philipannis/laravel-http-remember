<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Verify remembered requests through Laravel's public HTTP client API.
 */
final class RequestCompatibilityTest extends TestCase
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
     * Confirm independent callers can reuse a response without sharing newly issued cookies.
     */
    public function test_responses_with_cookies_are_cached_without_populating_another_callers_jar(): void
    {
        // Return a successful body alongside newly issued session and affinity cookies.
        Http::fake([
            self::API_URL => Http::response(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK, [
                'Set-Cookie' => ['session=first-session; Path=/; HttpOnly', 'affinity=first-server; Path=/'],
                'X-Response-Version' => (string) self::INITIAL_VERSION,
            ]),
        ]);

        // Send the same anonymous request with independent cookie jars.
        $firstJar = new CookieJar;
        $secondJar = new CookieJar;
        $first = Http::withOptions(['cookies' => $firstJar])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $second = Http::withOptions(['cookies' => $secondJar])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Deliver newly issued cookies only to the caller that reached the upstream server.
        self::assertNotEmpty($first->header('Set-Cookie'));
        self::assertSame('first-session', $firstJar->getCookieByName('session')?->getValue());
        self::assertSame('first-server', $firstJar->getCookieByName('affinity')?->getValue());
        self::assertSame('', $second->header('Set-Cookie'));
        self::assertNull($secondJar->getCookieByName('session'));
        self::assertNull($secondJar->getCookieByName('affinity'));

        // Reuse the cached body and ordinary headers without another upstream call.
        self::assertSame($first->json(), $second->json());
        self::assertSame((string) self::INITIAL_VERSION, $second->header('X-Response-Version'));
        Http::assertSentCount(1);
    }

    /**
     * Confirm cookies already sent with a request keep existing sessions in separate entries.
     */
    public function test_existing_session_cookies_create_separate_cache_entries(): void
    {
        // Return a distinct successful response for each established upstream session.
        $this->fakeVersionSequence();

        // Repeat otherwise identical requests with two different session cookies.
        $first = Http::withCookies(['session' => 'first-session'], 'api.example.test')->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $firstAgain = Http::withCookies(['session' => 'first-session'], 'api.example.test')->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $second = Http::withCookies(['session' => 'second-session'], 'api.example.test')->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $secondAgain = Http::withCookies(['session' => 'second-session'], 'api.example.test')->remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm each session reuses only its own response and keeps its original cookie.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::INITIAL_VERSION, $firstAgain->json('version'));
        self::assertSame(self::UPDATED_VERSION, $second->json('version'));
        self::assertSame(self::UPDATED_VERSION, $secondAgain->json('version'));
        self::assertSame('first-session', $firstAgain->cookies()->getCookieByName('session')?->getValue());
        self::assertSame('second-session', $secondAgain->cookies()->getCookieByName('session')?->getValue());
        Http::assertSentCount(2);
    }

    /**
     * Confirm deferred refreshes keep caching responses that issue new session cookies.
     */
    public function test_refreshed_responses_do_not_replay_new_session_cookies(): void
    {
        // Freeze time and issue a different cookie with the initial and refreshed responses.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()
            ->push(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK, ['Set-Cookie' => 'session=initial-session; Path=/'])
            ->push(['version' => self::UPDATED_VERSION], HttpStatus::HTTP_OK, ['Set-Cookie' => 'session=refreshed-session; Path=/']);

        // Populate the cache before returning its stale response without issuing cookies.
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::FRESH_SECONDS));
        $stale = Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        self::assertSame('', $stale->header('Set-Cookie'));

        // Refresh the cached body and return it to a caller with a separate cookie jar.
        app(DeferredCallbackCollection::class)->invoke();
        $jar = new CookieJar;
        $refreshed = Http::withOptions(['cookies' => $jar])->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Confirm the refreshed body was cached without adopting the refresh's session.
        self::assertSame(self::UPDATED_VERSION, $refreshed->json('version'));
        self::assertSame('', $refreshed->header('Set-Cookie'));
        self::assertNull($jar->getCookieByName('session'));
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
     * Confirm header callbacks run on every request without remembering their responses.
     */
    public function test_header_callbacks_bypass_the_cache(): void
    {
        // Use Guzzle's mock transport so header callbacks run without reaching the network.
        $handler = new MockHandler([
            new Response(HttpStatus::HTTP_OK, [], json_encode(['version' => self::INITIAL_VERSION])),
            new Response(HttpStatus::HTTP_OK, [], json_encode(['version' => self::UPDATED_VERSION])),
        ]);
        $validated = 0;
        $callback =
            /**
             * Validate each live response before its body is delivered.
             *
             * @param  ResponseInterface  $response  The response received by the transport.
             */
            static function (ResponseInterface $response) use (&$validated): void {
                // Count only responses that reach Guzzle's header validation hook.
                self::assertSame(HttpStatus::HTTP_OK, $response->getStatusCode());
                $validated++;
            };

        // Configure the callback before and after remember to cover both fluent orders.
        $first = Http::setHandler($handler)->preventStrayRequests(false)
            ->withOptions(['on_headers' => $callback])
            ->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $second = Http::setHandler($handler)->preventStrayRequests(false)
            ->remember(self::LIFETIME_SECONDS)
            ->withOptions(['on_headers' => $callback])->get(self::API_URL);

        // Confirm both requests were validated without populating the response cache.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::UPDATED_VERSION, $second->json('version'));
        self::assertSame(2, $validated);
        self::assertCount(0, $handler);
        self::assertSame([], Cache::store()->getStore()->all());
    }

    /**
     * Confirm header callbacks cannot reuse or refresh an existing stale response.
     */
    public function test_header_callbacks_bypass_existing_stale_responses(): void
    {
        // Freeze time and populate an ordinary response using Guzzle's mock transport.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        $handler = new MockHandler([
            new Response(HttpStatus::HTTP_OK, [], json_encode(['version' => self::INITIAL_VERSION])),
            new Response(HttpStatus::HTTP_OK, [], json_encode(['version' => self::UPDATED_VERSION])),
        ]);
        Http::setHandler($handler)->preventStrayRequests(false)
            ->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        $cache = Cache::store()->getStore();
        $cached = $cache->all();
        $validated = 0;

        // Require header validation while the matching remembered response is stale.
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::FRESH_SECONDS));
        $response = Http::setHandler($handler)->preventStrayRequests(false)
            ->withOptions(['on_headers' =>
                /**
                 * Validate the live response instead of accepting remembered headers.
                 *
                 * @param  ResponseInterface  $response  The response received by the transport.
                 */
                static function (ResponseInterface $response) use (&$validated): void {
                    // Confirm Guzzle delivered the successful live response to the callback.
                    self::assertSame(HttpStatus::HTTP_OK, $response->getStatusCode());
                    $validated++;
                },
            ])->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Confirm validation left the existing entry and deferred callbacks unchanged.
        self::assertSame(self::UPDATED_VERSION, $response->json('version'));
        self::assertSame(1, $validated);
        self::assertCount(0, $handler);
        self::assertSame($cached, $cache->all());
        self::assertCount(0, app(DeferredCallbackCollection::class));
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
     * Confirm custom stream context options bypass response caching.
     */
    public function test_custom_stream_context_options_bypass_the_cache(): void
    {
        // Return a different response whenever the handler actually executes.
        $this->fakeVersionSequence();

        // Send distinct credentials through stream contexts without changing PSR-7 headers.
        $first = Http::withOptions([
            'stream_context' => ['http' => ['header' => 'Authorization: Bearer first-token']],
        ])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $second = Http::remember(self::LIFETIME_SECONDS)->withOptions([
            'stream_context' => ['http' => ['header' => 'Authorization: Bearer second-token']],
        ])->get(self::API_URL);

        // Confirm both requests reached the handler without storing their responses.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::UPDATED_VERSION, $second->json('version'));
        Http::assertSentCount(2);
        self::assertSame([], Cache::store()->getStore()->all());
    }

    /**
     * Confirm custom stream contexts cannot reuse or refresh an existing stale response.
     */
    public function test_custom_stream_context_options_bypass_existing_stale_responses(): void
    {
        // Freeze time and populate a response using the ordinary transport options.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        $this->fakeVersionSequence();
        Http::remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        $cache = Cache::store()->getStore();
        $cached = $cache->all();

        // Request the stale resource with credentials supplied outside its prepared headers.
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::FRESH_SECONDS));
        $response = Http::withOptions([
            'stream_context' => ['http' => ['header' => 'Authorization: Bearer second-token']],
        ])->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Confirm the live response left the existing entry and deferred callbacks unchanged.
        self::assertSame(self::UPDATED_VERSION, $response->json('version'));
        Http::assertSentCount(2);
        self::assertSame($cached, $cache->all());
        self::assertCount(0, app(DeferredCallbackCollection::class));
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
     * Confirm cloned cache hits retain their own cookies, attributes, and HTTP events.
     *
     * @param  bool  $async  Whether the cloned request returns a promise.
     */
    #[DataProvider('clonedRequestModes')]
    public function test_cloned_cache_hits_populate_the_sending_builders_metadata(bool $async): void
    {
        // Populate the cache independently of the builder that will be cloned.
        Http::fake([self::API_URL => Http::response('remembered-body', HttpStatus::HTTP_OK)]);
        Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Give the cloned request its own cookie jar and caller attributes.
        $originalJar = new CookieJar;
        $jar = new CookieJar;
        $original = Http::withOptions(['cookies' => $originalJar])
            ->remember(self::LIFETIME_SECONDS)
            ->withAttributes(['original' => true]);
        $clone = clone $original;
        $clone->async($async)->withOptions(['cookies' => $jar])->withAttributes(['cloned' => true]);

        // Observe the public request events after the initial cache population.
        $sending = [];
        $received = [];
        app('events')->listen(RequestSending::class,
            /**
             * Record the request that Laravel prepares for the cache hit.
             */
            static function (RequestSending $event) use (&$sending): void {
                $sending[] = $event;
            },
        );
        app('events')->listen(ResponseReceived::class,
            /**
             * Record the response event emitted by the cloned builder.
             */
            static function (ResponseReceived $event) use (&$received): void {
                $received[] = $event;
            },
        );

        // Resolve both synchronous and asynchronous cache-hit responses.
        $response = $clone->get(self::API_URL);
        if ($async) {
            self::assertInstanceOf(PromiseInterface::class, $response);
            $response = $response->wait();
        }

        // Confirm bookkeeping belongs to the clone without another handler execution.
        self::assertSame('remembered-body', $response->body());
        self::assertSame($jar, $response->cookies());
        self::assertCount(1, $sending);
        self::assertCount(1, $received);
        self::assertSame($sending[0]->request, $received[0]->request);
        self::assertSame($response, $received[0]->response);
        self::assertSame(['original' => true, 'cloned' => true], $sending[0]->request->attributes());
        Http::assertSentCount(1);

        // Reuse the original to confirm the clone did not replace its callback bindings.
        $originalResponse = $original->get(self::API_URL);
        self::assertSame($originalJar, $originalResponse->cookies());
        self::assertCount(2, $sending);
        self::assertCount(2, $received);
        self::assertSame(['original' => true], $sending[1]->request->attributes());
        self::assertSame($sending[1]->request, $received[1]->request);
        Http::assertSentCount(1);
    }

    /**
     * Provide both public HTTP request execution modes for cloned builders.
     *
     * @return iterable<string, array{bool}> Request modes keyed by their promise behavior.
     */
    public static function clonedRequestModes(): iterable
    {
        // Exercise the immediate response and fulfilled promise paths.
        yield 'synchronous' => [false];
        yield 'asynchronous' => [true];
    }

    /**
     * Confirm a cloned cache hit clears statistics inherited from a live request.
     */
    public function test_cloned_cache_hits_clear_inherited_transfer_statistics(): void
    {
        // Use an in-memory Guzzle handler that produces normal transfer statistics.
        $handler = new MockHandler([new Response(HttpStatus::HTTP_OK, [], 'remembered-body')]);
        $original = Http::setHandler($handler)->preventStrayRequests(false)->remember(self::LIFETIME_SECONDS);
        $live = $original->get(self::API_URL);
        self::assertSame(self::API_URL, (string) $live->effectiveUri());

        // Clone the populated builder so its previous transfer statistics are inherited.
        $clone = clone $original;
        $remembered = $clone->get(self::API_URL);

        // Confirm the cached response does not claim an earlier network transfer.
        self::assertSame('remembered-body', $remembered->body());
        self::assertNull($remembered->effectiveUri());
        self::assertSame([], $remembered->handlerStats());
        self::assertCount(0, $handler);
    }

    /**
     * Confirm later middleware on a clone still prevents response caching.
     */
    public function test_later_middleware_on_a_clone_bypasses_the_cache(): void
    {
        // Return distinct bodies whenever a cloned request reaches the fake handler.
        Http::fakeSequence()->push('first-body')->push('second-body');
        $original = Http::remember(self::LIFETIME_SECONDS);
        $clone = clone $original;

        // Separate the middleware collections before changing only the clone's stack.
        $original->remember(self::LIFETIME_SECONDS);
        $clone->withRequestMiddleware(
            /**
             * Represent a later middleware that changes the cloned request's identity.
             *
             * @return RequestInterface The request carrying the clone's credentials.
             */
            static fn (RequestInterface $request): RequestInterface => $request->withHeader('Authorization', 'Bearer clone-token'),
        );

        // Confirm both requests preserve the later middleware's normal execution.
        self::assertSame('first-body', $clone->get(self::API_URL)->body());
        self::assertSame('second-body', $clone->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm changes to the original builder do not disable caching on its clone.
     */
    public function test_later_middleware_on_the_original_does_not_bypass_the_clones_cache(): void
    {
        // Return a second body if the clone incorrectly inherits the original's guard.
        Http::fakeSequence()->push('first-body')->push('second-body');
        $original = Http::remember(self::LIFETIME_SECONDS);
        $clone = clone $original;

        // Replace the original's policy before adding middleware to its independent stack.
        $original->remember(self::LIFETIME_SECONDS)->withRequestMiddleware(
            /**
             * Represent a later request mutation confined to the original builder.
             *
             * @return RequestInterface The request carrying the original's credentials.
             */
            static fn (RequestInterface $request): RequestInterface => $request->withHeader('Authorization', 'Bearer original-token'),
        );

        // Confirm the unmodified clone can still remember its own response.
        self::assertSame('first-body', $clone->get(self::API_URL)->body());
        self::assertSame('first-body', $clone->get(self::API_URL)->body());
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
