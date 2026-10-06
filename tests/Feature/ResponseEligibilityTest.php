<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use PhilipAnnis\HttpRemember\HttpRememberMiddleware;
use PhilipAnnis\HttpRemember\HttpRememberOptions;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TypeError;
use UnexpectedValueException;

/**
 * Verify response admission predicates during live requests and deferred refreshes.
 */
final class ResponseEligibilityTest extends TestCase
{
    /**
     * The endpoint used by both ordinary reads and read-only POST requests.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The instant used to verify stale refreshes and their original expiry.
     */
    private const STARTED_AT = '2026-01-01 12:00:00';

    /**
     * Confirm HTTP 200 error payloads remain live until an eligible response arrives.
     */
    public function test_predicate_rejects_application_errors_without_changing_the_live_response(): void
    {
        // Return an application error before an eligible response at the same HTTP status.
        Http::fakeSequence()
            ->push(['errors' => [['message' => 'Unavailable']]])
            ->push(['version' => 2]);
        $inspected = [];
        $pending = Http::remember(60, cacheWhen:
            /**
             * Accept successful application payloads using Laravel's response methods.
             */
            static function (Response $response) use (&$inspected): bool {
                $inspected[] = $response->json();

                return empty($response->json('errors'));
            },
        );

        // Return the error normally without storing it, then cache the eligible result.
        $rejected = $pending->get(self::API_URL);
        self::assertSame(200, $rejected->status());
        self::assertSame('Unavailable', $rejected->json('errors.0.message'));
        self::assertSame([], Cache::store()->getStore()->all());
        self::assertSame(2, $pending->get(self::API_URL)->json('version'));
        self::assertSame(2, $pending->get(self::API_URL)->json('version'));

        // Inspect only live candidates while reusing the accepted response on the cache hit.
        self::assertSame([
            ['errors' => [['message' => 'Unavailable']]],
            ['version' => 2],
        ], $inspected);
        Http::assertSentCount(2);
    }

    /**
     * Confirm fresh and stale cache hits reuse responses without invoking the predicate.
     *
     * @param  int|array{int, int}  $ttl  The policy shared by unfiltered and filtered requests.
     * @param  int  $age  The cached response's age before the filtered request.
     */
    #[DataProvider('cachedResponseStates')]
    public function test_cache_hits_do_not_run_the_predicate(int|array $ttl, int $age): void
    {
        // Populate an application error using an earlier unfiltered policy.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()
            ->push(['errors' => [['message' => 'Unavailable']]])
            ->push(['version' => 2]);
        $initial = Http::remember($ttl)->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds($age));
        $inspections = 0;

        // Reuse the stored response even when the new predicate would reject a live candidate.
        $pending = Http::remember($ttl, cacheWhen:
            /**
             * Record predicate calls while declining every new cache write.
             */
            static function (Response $response) use (&$inspections): bool {
                $inspections++;

                return false;
            },
        );
        self::assertSame($initial->json(), $pending->get(self::API_URL)->json());
        self::assertSame($initial->json(), $pending->get(self::API_URL)->json());
        self::assertSame(0, $inspections);
        Http::assertSentCount(1);

        // A stale hit still schedules one refresh whose live response goes through the predicate.
        $callbacks = app(DeferredCallbackCollection::class);
        $refreshes = $age === 10 ? 1 : 0;
        self::assertCount($refreshes, $callbacks);
        $callbacks->invoke();
        self::assertSame($refreshes, $inspections);
        Http::assertSentCount(1 + $refreshes);
    }

    /**
     * Provide fixed and stale policies whose retained response is still logically usable.
     *
     * @return iterable<string, array{int|array{int, int}, int}> Policies and cache ages keyed by state.
     */
    public static function cachedResponseStates(): iterable
    {
        // Keep both kinds of cache hit free from repeated response inspection.
        yield 'fixed fresh' => [60, 0];
        yield 'stale policy fresh' => [[10, 60], 0];
        yield 'stale policy stale' => [[10, 60], 10];
    }

    /**
     * Confirm predicate failures skip caching and log only safe exception metadata.
     *
     * @param  string  $failure  The callback failure exercised by this test.
     * @param  class-string<\Throwable>  $exception  The exception class expected in the warning.
     */
    #[DataProvider('predicateFailures')]
    public function test_predicate_failures_preserve_live_responses(string $failure, string $exception): void
    {
        // Require diagnostics to omit response contents and the predicate's exception message.
        Log::shouldReceive('warning')->once()->with(
            'HTTP remember eligibility failed.',
            Mockery::on(
                /**
                 * Check that warnings contain only the hashed key and exception class.
                 */
                static fn (array $context): bool => count($context) === 2
                    && preg_match('/^http-remember:[a-f0-9]{64}$/', $context['cache_key']) === 1
                    && $context['exception'] === $exception,
            ),
        );
        Http::fake([self::API_URL => Http::response(['version' => 1])]);

        // Return a live result even when the predicate throws or fails its boolean contract.
        $response = Http::remember(60, cacheWhen:
            /**
             * Exercise application exceptions and accidental non-boolean return values.
             */
            static fn (Response $response): mixed => match ($failure) {
                'throw' => throw new RuntimeException('Sensitive application details.'),
                'integer' => 1,
                'string' => 'yes',
                'null' => null,
            },
        )->get(self::API_URL);
        self::assertSame(200, $response->status());
        self::assertSame(['version' => 1], $response->json());
        self::assertSame([], Cache::store()->getStore()->all());
        Http::assertSentCount(1);
    }

    /**
     * Provide exceptions and invalid return values that must reject caching.
     *
     * @return iterable<string, array{string, class-string<\Throwable>}> Failures keyed by their callback behavior.
     */
    public static function predicateFailures(): iterable
    {
        // Refuse to cache when the application cannot explicitly approve a response.
        yield 'application exception' => ['throw', RuntimeException::class];
        yield 'truthy integer' => ['integer', UnexpectedValueException::class];
        yield 'truthy string' => ['string', UnexpectedValueException::class];
        yield 'missing boolean' => ['null', UnexpectedValueException::class];
    }

    /**
     * Confirm changing a rule affects new writes after the existing entry is invalidated.
     */
    public function test_changing_a_predicate_requires_invalidation_of_existing_entries(): void
    {
        // Cache an application error before adopting a rule that excludes such responses.
        Http::fakeSequence()->push(['errors' => ['Unavailable']])->push(['version' => 2]);
        $initial = Http::remember(60)->get(self::API_URL);
        $cache = Cache::store();
        $key = array_key_first($cache->getStore()->all());
        $inspections = 0;
        $pending = Http::remember(60, cacheWhen:
            /**
             * Inspect only new live results when deciding whether to store them.
             */
            static function (Response $response) use (&$inspections): bool {
                $inspections++;

                return empty($response->json('errors'));
            },
        );

        // Preserve the existing entry until explicitly invalidating it.
        self::assertSame($initial->json(), $pending->get(self::API_URL)->json());
        self::assertSame(0, $inspections);
        Http::assertSentCount(1);
        $cache->forget($key);

        // Apply the new rule to the next live response and reuse its accepted cache entry.
        self::assertSame(2, $pending->get(self::API_URL)->json('version'));
        self::assertSame(2, $pending->get(self::API_URL)->json('version'));
        self::assertSame(1, $inspections);
        Http::assertSentCount(2);
    }

    /**
     * Confirm rejected refreshes preserve the original generation without extending expiry.
     *
     * @param  bool  $throw  Whether the predicate throws instead of returning false.
     */
    #[DataProvider('refreshRejections')]
    public function test_rejected_refresh_preserves_the_original_expiry(bool $throw): void
    {
        // Populate a successful response before an application error during deferred work.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()->push(['version' => 1])->push(['errors' => ['Unavailable']])->push(['version' => 2]);
        Log::shouldReceive('warning')->times($throw ? 1 : 0);
        $pending = Http::remember([10, 60], cacheWhen:
            /**
             * Keep an application error out of both foreground and deferred cache writes.
             */
            static function (Response $response) use ($throw): bool {
                if ($throw && ! empty($response->json('errors'))) {
                    throw new RuntimeException('Deferred eligibility failure.');
                }

                return empty($response->json('errors'));
            },
        );
        $pending->get(self::API_URL);
        $cache = Cache::store();
        $key = array_key_first($cache->getStore()->all());
        $initial = $cache->get($key);

        // Serve stale data and reject its refresh without replacing the stored generation.
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        self::assertSame(1, $pending->get(self::API_URL)->json('version'));
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame($initial, $cache->get($key));
        Http::assertSentCount(2);

        // Fetch a live response at the original hard expiry instead of extending stale data.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));
        self::assertSame(2, $pending->get(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Provide ordinary rejection and a predicate exception during deferred execution.
     *
     * @return iterable<string, array{bool}> Rejections keyed by their callback behavior.
     */
    public static function refreshRejections(): iterable
    {
        // Preserve stale data whether the application rejects a refresh or cannot inspect it.
        yield 'returns false' => [false];
        yield 'throws exception' => [true];
    }

    /**
     * Confirm an eligible deferred response replaces the stale generation.
     */
    public function test_eligible_refresh_is_remembered(): void
    {
        // Prepare two application-successful generations under a stale policy.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()->push(['version' => 1])->push(['version' => 2]);
        $pending = Http::remember([10, 60], cacheWhen:
            /**
             * Accept only responses that expose the expected application result.
             */
            static fn (Response $response): bool => is_int($response->json('version')),
        );
        $pending->get(self::API_URL);

        // Complete the deferred refresh and reuse the newly accepted generation.
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        self::assertSame(1, $pending->get(self::API_URL)->json('version'));
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame(2, $pending->get(self::API_URL)->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm inspecting and changing a predicate's body copy cannot alter live or cached data.
     */
    public function test_predicate_body_stream_is_independent_of_live_and_cached_responses(): void
    {
        // Preserve original headers and a partially consumed body from the controlled handler.
        $live = new PsrResponse(201, ['Set-Cookie' => 'session=live', 'X-Result' => 'created'], '{"version":1}', '2.0');
        $live->getBody()->seek(4);
        $inspections = 0;
        $handler = $this->middleware(
            /**
             * Inspect metadata before deliberately closing only the predicate's body stream.
             */
            static function (Response $response) use (&$inspections): bool {
                self::assertSame(201, $response->status());
                self::assertSame('created', $response->header('X-Result'));
                self::assertSame('2.0', $response->toPsrResponse()->getProtocolVersion());
                self::assertSame(['version' => 1], $response->json());
                self::assertSame('session=live', $response->header('Set-Cookie'));
                $inspections++;
                $response->toPsrResponse()->getBody()->close();

                return true;
            },
        )(
            /**
             * Return the original live response without Laravel's fake stream preparation.
             */
            static fn (): PromiseInterface => Create::promiseFor($live),
        );
        $request = new Request('GET', self::API_URL);

        // Preserve the live cursor and cookie header after inspecting the independent copy.
        self::assertSame($live, $handler($request, [])->wait());
        self::assertSame(4, $live->getBody()->tell());
        self::assertSame('session=live', $live->getHeaderLine('Set-Cookie'));

        // Reuse the original cached body without inspecting it again.
        $cached = $handler($request, [])->wait();
        self::assertSame('{"version":1}', (string) $cached->getBody());
        self::assertSame('', $cached->getHeaderLine('Set-Cookie'));
        self::assertSame(1, $inspections);
    }

    /**
     * Confirm filtering keeps asynchronous requests on their normal promise path.
     */
    public function test_async_requests_apply_the_predicate(): void
    {
        // Reject one live response before remembering a later successful application result.
        Http::fakeSequence()->push(['errors' => ['Unavailable']])->push(['version' => 2]);
        $pending = Http::async()->remember(60, cacheWhen:
            /**
             * Inspect response payloads only when each asynchronous transfer resolves.
             */
            static fn (Response $response): bool => empty($response->json('errors')),
        );

        // Resolve rejected, accepted, and cached candidates without changing the promise contract.
        $rejected = $pending->get(self::API_URL);
        self::assertInstanceOf(PromiseInterface::class, $rejected);
        self::assertSame(['Unavailable'], $rejected->wait()->json('errors'));
        $accepted = $pending->get(self::API_URL);
        self::assertInstanceOf(PromiseInterface::class, $accepted);
        self::assertSame(2, $accepted->wait()->json('version'));
        $cached = $pending->get(self::API_URL);
        self::assertInstanceOf(PromiseInterface::class, $cached);
        self::assertSame(2, $cached->wait()->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm invokable objects and method callables can share persisted cached responses.
     */
    public function test_callable_objects_work_with_a_persistent_cache_store(): void
    {
        // Keep callback objects outside the scalar payload written to a private file cache.
        $path = sys_get_temp_dir().'/http-remember-eligibility-'.bin2hex(random_bytes(8));
        config(['cache.stores.eligibility-files' => ['driver' => 'file', 'path' => $path]]);
        Http::fakeSequence()->push(['errors' => ['Unavailable']])->push(['version' => 2])->push(['version' => 3]);
        $predicate = new class
        {
            /**
             * Accept an application response that contains no error payload.
             */
            public function __invoke(Response $response): bool
            {
                // Apply the same application rule whenever a live result is considered for storage.
                return empty($response->json('errors'));
            }
        };

        // Filter one live error before persisting and reading the eligible result through another callable.
        try {
            $pending = Http::remember(60, store: 'eligibility-files', cacheWhen: $predicate);
            self::assertSame(['Unavailable'], $pending->get(self::API_URL)->json('errors'));
            self::assertSame(2, $pending->get(self::API_URL)->json('version'));
            self::assertSame(2, Http::remember(60, store: 'eligibility-files', cacheWhen: [$predicate, '__invoke'])->get(self::API_URL)->json('version'));

            // Inspect a new live result through the method callable after clearing the existing entry.
            Cache::store('eligibility-files')->flush();
            $pending = Http::remember(60, store: 'eligibility-files', cacheWhen: [$predicate, '__invoke']);
            self::assertSame(3, $pending->get(self::API_URL)->json('version'));
            self::assertSame(3, $pending->get(self::API_URL)->json('version'));
            Http::assertSentCount(3);
        } finally {
            // Remove only this test's temporary cache files.
            (new Filesystem)->deleteDirectory($path);
        }
    }

    /**
     * Confirm requests that bypass caching never run the response predicate.
     */
    public function test_bypassed_requests_skip_response_inspection(): void
    {
        // Preserve streaming behavior while configuring a predicate that would otherwise reject it.
        Http::fakeSequence()->push(['version' => 1])->push(['version' => 2]);
        $inspections = 0;
        $pending = Http::withOptions(['stream' => true])->remember(60, cacheWhen:
            /**
             * Record accidental filtering of a transfer that requires the live handler.
             */
            static function (Response $response) use (&$inspections): bool {
                $inspections++;

                return false;
            },
        );

        // Require both transfers to stay live without predicate calls or stored responses.
        self::assertSame(1, $pending->get(self::API_URL)->json('version'));
        self::assertSame(2, $pending->get(self::API_URL)->json('version'));
        self::assertSame(0, $inspections);
        self::assertSame([], Cache::store()->getStore()->all());
        Http::assertSentCount(2);
    }

    /**
     * Confirm grouped POST reads use the predicate and retain normal mutation invalidation.
     */
    public function test_grouped_read_predicates_do_not_filter_mutation_invalidation(): void
    {
        // Cache a grouped POST only after its application result becomes eligible.
        Http::fakeSequence()
            ->push(['errors' => ['Unavailable']])
            ->push(['version' => 2])
            ->push(['version' => 3])
            ->push(['version' => 4]);
        $group = ['tenant:42', 'products'];
        $pending = Http::remember(60, group: $group, operation: 'read', cacheWhen:
            /**
             * Apply application eligibility to an explicitly marked POST read.
             */
            static fn (Response $response): bool => empty($response->json('errors')),
        );
        self::assertSame(['Unavailable'], $pending->post(self::API_URL)->json('errors'));
        self::assertSame(2, $pending->post(self::API_URL)->json('version'));
        self::assertSame(2, $pending->post(self::API_URL)->json('version'));
        $mutationsInspected = 0;

        // Send a mutation whose cache predicate must never affect group invalidation.
        Http::remember(group: $group, cacheWhen:
            /**
             * Record any accidental filtering of a response that is never cached.
             */
            static function (Response $response) use (&$mutationsInspected): bool {
                $mutationsInspected++;

                return false;
            },
        )->put(self::API_URL);
        self::assertSame(0, $mutationsInspected);
        self::assertSame(4, $pending->post(self::API_URL)->json('version'));
        Http::assertSentCount(4);
    }

    /**
     * Confirm replacing a policy clears its predicate without changing a cloned builder.
     */
    public function test_replacing_a_policy_resets_its_predicate_independently_of_clones(): void
    {
        // Clone a policy that rejects every response before resetting the original builder.
        Http::fakeSequence()->push(['version' => 1])->push(['version' => 2])->push(['version' => 3]);
        $original = Http::remember(60, cacheWhen:
            /**
             * Reject caching while allowing the caller to receive each live response.
             */
            static fn (Response $response): bool => false,
        );
        $clone = clone $original;
        $original->remember(60);

        // Keep the clone's predicate when the original starts caching again.
        self::assertSame(1, $clone->get(self::API_URL)->json('version'));
        self::assertSame([], Cache::store()->getStore()->all());
        self::assertSame(2, $original->get(self::API_URL)->json('version'));
        self::assertSame(2, $clone->get(self::API_URL)->json('version'));

        // Explicit null clears the clone's predicate before the next live cache candidate.
        $clone->remember(60, cacheWhen: null);
        Cache::flush();
        self::assertSame(3, $clone->get(self::API_URL)->json('version'));
        self::assertSame(3, $clone->get(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Confirm invalid predicate arguments cannot replace a builder's existing policy.
     */
    public function test_non_callable_predicate_preserves_the_previous_policy(): void
    {
        // Populate a builder whose policy must survive invalid callback input.
        Http::fakeSequence()->push(['version' => 1])->push(['version' => 2]);
        $pending = Http::remember(60);
        $pending->get(self::API_URL);

        // Reject the invalid argument before removing the active middleware.
        try {
            $pending->remember(60, cacheWhen: 'missing_response_predicate');
            self::fail('The request accepted a non-callable response predicate.');
        } catch (TypeError) {
            self::assertSame(1, $pending->get(self::API_URL)->json('version'));
        }
        Http::assertSentCount(1);
    }

    /**
     * Confirm supplying a later named callback does not make explicit null operation valid.
     */
    public function test_explicit_null_operation_is_rejected_with_a_predicate(): void
    {
        // Preserve operation validation while allowing the callback to be supplied independently.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The HTTP remember operation must be read when provided.');
        Http::remember(operation: null, cacheWhen:
            /**
             * Supply a valid predicate after the explicitly invalid operation argument.
             */
            static fn (Response $response): bool => true,
        );
    }

    /**
     * Confirm predicates cannot opt unsuccessful responses into caching.
     */
    public function test_predicate_cannot_override_successful_response_requirements(): void
    {
        // Return a failure before an eligible successful response under an accepting predicate.
        Http::fakeSequence()->push(['error' => 'Unavailable'], 503)->push(['version' => 2]);
        $inspections = 0;
        $pending = Http::remember(60, cacheWhen:
            /**
             * Record candidates that pass the built-in response requirements.
             */
            static function (Response $response) use (&$inspections): bool {
                $inspections++;

                return true;
            },
        );

        // Keep the upstream failure live and inspect only the later successful transfer.
        self::assertSame(503, $pending->get(self::API_URL)->status());
        self::assertSame(0, $inspections);
        self::assertSame(2, $pending->get(self::API_URL)->json('version'));
        self::assertSame(2, $pending->get(self::API_URL)->json('version'));
        self::assertSame(1, $inspections);
        Http::assertSentCount(2);
    }

    /**
     * Create middleware for tests that inspect isolated response streams.
     *
     * @param  callable(Response): bool  $cacheWhen  The predicate applied to each live candidate.
     * @return HttpRememberMiddleware The policy without Laravel-specific preparation hooks.
     */
    private function middleware(callable $cacheWhen): HttpRememberMiddleware
    {
        // Keep direct handler tests focused on independent body streams.
        return new HttpRememberMiddleware(
            new HttpRememberOptions(60, null, 15, cacheWhen: $cacheWhen),
            /**
             * Allow the prepared test request to use remembered responses.
             */
            static fn (): bool => true,
            /**
             * Leave Laravel's cache-hit metadata outside controlled handler tests.
             */
            static function (): void {},
        );
    }
}
