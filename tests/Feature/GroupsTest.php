<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
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
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Verify grouped reads and live mutations through the public remember macro.
 */
final class GroupsTest extends TestCase
{
    /**
     * The resource used by remembered reads and read-only POST requests.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * A different URL whose mutations affect the same group.
     */
    private const MUTATION_URL = 'https://api.example.test/products/42';

    /**
     * The complete group shared by related requests.
     */
    private const GROUP = ['tenant:42', 'products'];

    /**
     * Confirm requests without remember never access the package's cache store.
     */
    public function test_requests_without_remember_skip_the_package(): void
    {
        // Require ordinary requests to remain outside package processing.
        Cache::shouldReceive('store')->never();
        $this->fakeVersionedApi();

        // Exercise reads and mutations without installing package middleware.
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $index => $method) {
            self::assertSame($index + 1, Http::send($method, self::API_URL)->json('version'));
        }

        // Every ordinary request must reach the live handler.
        Http::assertSentCount(4);
    }

    /**
     * Confirm ordinary unremembered writes leave existing groups untouched.
     */
    public function test_plain_mutations_do_not_invalidate_remembered_reads(): void
    {
        // Populate a group before using the ordinary HTTP client for a mutation.
        $this->fakeVersionedApi();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        Http::put(self::MUTATION_URL);

        // Keep the group unchanged because only remember opts requests in.
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm safe HTTP methods cache responses using an order-independent group.
     *
     * @param  string  $method  The method automatically treated as a read.
     */
    #[DataProvider('readMethods')]
    public function test_grouped_reads_reuse_the_same_response(string $method): void
    {
        // Return a new version only when the upstream handler runs.
        $this->fakeVersionedApi();
        $first = Http::remember(60, group: self::GROUP)->send($method, self::API_URL);
        $second = Http::remember(60, group: array_reverse(self::GROUP), operation: 'read')->send($method, self::API_URL);

        // Explicit read intent and reordered values must retain the same cache identity.
        self::assertSame(1, $first->json('version'));
        self::assertSame($first->json(), $second->json());
        Http::assertSentCount(1);
    }

    /**
     * Provide methods that automatically read within a group.
     *
     * @return iterable<string, array{string}> Read methods keyed by their HTTP names.
     */
    public static function readMethods(): iterable
    {
        // Match the HTTP methods whose usual semantics do not mutate resources.
        foreach (['GET', 'HEAD', 'OPTIONS', 'TRACE'] as $method) {
            yield $method => [$method];
        }
    }

    /**
     * Confirm grouped mutations always run live and invalidate matching cached reads.
     *
     * @param  string  $method  The method automatically treated as a mutation.
     */
    #[DataProvider('mutationMethods')]
    public function test_grouped_mutations_are_never_cached_and_invalidate_reads(string $method): void
    {
        // Populate a read before sending the same mutation twice.
        $this->fakeVersionedApi();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $first = Http::remember(group: array_reverse(self::GROUP))->send($method, self::MUTATION_URL);
        $second = Http::remember(group: self::GROUP)->send($method, self::MUTATION_URL);

        // Require both mutations and the next read to reach the handler.
        self::assertSame(2, $first->json('version'));
        self::assertSame(3, $second->json('version'));
        self::assertSame(4, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(4);
    }

    /**
     * Provide standard mutation methods and an extension method.
     *
     * @return iterable<string, array{string}> Mutation methods keyed by their HTTP names.
     */
    public static function mutationMethods(): iterable
    {
        // Treat methods outside the safe set as mutations when a group is supplied.
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'PURGE'] as $method) {
            yield $method => [$method];
        }
    }

    /**
     * Confirm omitted and empty groups preserve the original caching behavior for every method.
     *
     * @param  string  $method  The HTTP method used without automatic group invalidation.
     */
    #[DataProvider('mutationMethods')]
    public function test_ungrouped_requests_keep_the_original_caching_behavior(string $method): void
    {
        // Populate an explicit group before caching an ungrouped operation.
        $this->fakeVersionedApi();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $first = Http::remember(60)->send($method, self::MUTATION_URL);
        $second = Http::remember(60, group: null, operation: 'read')->send($method, self::MUTATION_URL);
        $empty = Http::remember(60, group: [])->send($method, self::MUTATION_URL);

        // Retain the ordinary cache identity without invalidating grouped reads.
        self::assertSame(2, $first->json('version'));
        self::assertSame($first->json(), $second->json());
        self::assertSame($first->json(), $empty->json());
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm explicit reads cache any method and remain subject to group invalidation.
     *
     * @param  string  $method  The HTTP method used by a read operation.
     */
    #[DataProvider('mutationMethods')]
    public function test_read_override_caches_other_methods_without_invalidating(string $method): void
    {
        // Populate a related GET before an endpoint returns a cacheable read response.
        $this->fakeVersionedApi();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $first = Http::remember(60, group: self::GROUP, operation: 'read')->send($method, self::MUTATION_URL);
        $second = Http::remember(60, group: array_reverse(self::GROUP), operation: 'read')->send($method, self::MUTATION_URL);

        // Neither the repeated read nor the related GET should require another live transfer.
        self::assertSame(2, $first->json('version'));
        self::assertSame($first->json(), $second->json());
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(2);

        // A real mutation must invalidate both the ordinary and explicitly marked reads.
        Http::remember(group: self::GROUP)->put(self::MUTATION_URL);
        self::assertSame(4, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertSame(5, Http::remember(60, group: self::GROUP, operation: 'read')->send($method, self::MUTATION_URL)->json('version'));
        Http::assertSentCount(5);
    }

    /**
     * Confirm one mutation invalidates every payload variant of a cached POST query.
     */
    public function test_post_query_variants_share_group_invalidation(): void
    {
        // Cache distinct read payloads independently at the same endpoint.
        $this->fakeVersionedApi();
        $linen = Http::remember(60, group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'linen']);
        $cotton = Http::remember(60, group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'cotton']);
        self::assertSame(1, $linen->json('version'));
        self::assertSame(2, $cotton->json('version'));
        self::assertSame($linen->json(), Http::remember(60, group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'linen'])->json());

        // A POST mutation can use that same endpoint without inspecting its payload.
        Http::remember(group: self::GROUP)->post(self::API_URL, ['name' => 'Updated product']);
        self::assertSame(4, Http::remember(60, group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'linen'])->json('version'));
        self::assertSame(5, Http::remember(60, group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'cotton'])->json('version'));
        Http::assertSentCount(5);
    }

    /**
     * Confirm omission replaces a read override with automatic method detection.
     */
    public function test_omitting_operation_restores_automatic_method_detection(): void
    {
        // Populate a POST read before replacing the builder's operation policy.
        $this->fakeVersionedApi();
        $pending = Http::remember(60, group: self::GROUP, operation: 'read');
        self::assertSame(1, $pending->post(self::API_URL)->json('version'));

        // Omitting the operation restores method detection and sends each grouped POST live.
        $pending->remember(60, group: self::GROUP);
        self::assertSame(2, $pending->post(self::API_URL)->json('version'));
        self::assertSame(3, $pending->post(self::API_URL)->json('version'));
        self::assertCount(0, app(DeferredCallbackCollection::class));

        // That same operation policy must still allow ordinary GET responses to be cached.
        self::assertSame(4, $pending->get(self::API_URL)->json('version'));
        self::assertSame(4, $pending->get(self::API_URL)->json('version'));
        Http::assertSentCount(4);
    }

    /**
     * Confirm an invalid operation cannot replace an existing request policy.
     *
     * @param  string|null  $operation  An unsupported explicit operation value.
     */
    #[DataProvider('invalidExplicitOperations')]
    public function test_invalid_operation_preserves_the_previous_policy(?string $operation): void
    {
        // Cache a POST read through a builder whose policy will be replaced.
        $this->fakeVersionedApi();
        $pending = Http::remember(60, group: self::GROUP, operation: 'read');
        self::assertSame(1, $pending->post(self::API_URL)->json('version'));

        // Reject unsupported explicit values before modifying the builder's middleware.
        try {
            $pending->remember(60, group: self::GROUP, operation: $operation);
            self::fail('The request accepted an unsupported operation.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The HTTP remember operation must be read when provided.', $exception->getMessage());
        }

        // Preserve the read policy and its existing cache entry after failed validation.
        self::assertSame(1, $pending->post(self::API_URL)->json('version'));
        Http::assertSentCount(1);
    }

    /**
     * Provide values that must be omitted instead of selecting automatic detection explicitly.
     *
     * @return iterable<string, array{string|null}> Invalid explicit values keyed by purpose.
     */
    public static function invalidExplicitOperations(): iterable
    {
        // Keep read as the only accepted value supplied to the public macro.
        yield 'removed write value' => ['write'];
        yield 'automatic name' => ['auto'];
        yield 'explicit null' => [null];
    }

    /**
     * Confirm invalidation requires the same complete group within the selected store.
     */
    public function test_invalidation_preserves_other_groups_and_cache_stores(): void
    {
        // Populate overlapping groups, another store, and the original ungrouped cache path.
        config(['cache.stores.secondary' => ['driver' => 'array']]);
        $this->fakeVersionedApi();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $tenant = Http::remember(60, group: ['tenant:43', 'products'])->get(self::API_URL);
        $subset = Http::remember(60, group: ['products'])->get(self::API_URL);
        $secondary = Http::remember(60, store: 'secondary', group: self::GROUP)->get(self::API_URL);
        $ungrouped = Http::remember(60)->get(self::API_URL);

        // Invalidate exactly one complete group in the default store.
        Http::remember(group: self::GROUP)->delete(self::MUTATION_URL);
        self::assertSame(7, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertSame($tenant->json(), Http::remember(60, group: ['tenant:43', 'products'])->get(self::API_URL)->json());
        self::assertSame($subset->json(), Http::remember(60, group: ['products'])->get(self::API_URL)->json());
        self::assertSame($secondary->json(), Http::remember(60, store: 'secondary', group: self::GROUP)->get(self::API_URL)->json());
        self::assertSame($ungrouped->json(), Http::remember(60)->get(self::API_URL)->json());
        Http::assertSentCount(7);
    }

    /**
     * Confirm one group covers different URLs, credentials, methods, and lifetimes.
     */
    public function test_invalidation_covers_all_response_variants_in_a_group(): void
    {
        // Populate separate representations sharing one invalidation identity.
        $this->fakeVersionedApi();
        Http::withToken('first-token')->remember(60, group: self::GROUP)->get(self::API_URL);
        Http::withToken('second-token')->remember(120, group: self::GROUP)->get(self::MUTATION_URL);
        Http::remember(60, group: self::GROUP)->head(self::API_URL);

        // The writer need not share a read's URL, credentials, method, or lifetime.
        Http::withToken('writer-token')->remember(300, group: self::GROUP)->put(self::MUTATION_URL);
        self::assertSame(5, Http::withToken('first-token')->remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertSame(6, Http::withToken('second-token')->remember(120, group: self::GROUP)->get(self::MUTATION_URL)->json('version'));
        self::assertSame(7, Http::remember(60, group: self::GROUP)->head(self::API_URL)->json('version'));
        Http::assertSentCount(7);
    }

    /**
     * Confirm an explicitly empty group shares the ordinary response cache identity.
     */
    public function test_empty_group_reuses_ungrouped_responses(): void
    {
        // Populate through an empty group before using ordinary remembering.
        $this->fakeVersionedApi();
        $empty = Http::remember(60, group: [])->get(self::API_URL);

        // Empty, omitted, and null groups must all reuse the same cached response.
        self::assertSame(1, $empty->json('version'));
        self::assertSame(1, Http::remember(60)->get(self::API_URL)->json('version'));
        self::assertSame(1, Http::remember(60, group: null)->get(self::API_URL)->json('version'));
        Http::assertSentCount(1);
    }

    /**
     * Confirm failed mutation responses preserve the remembered generation.
     *
     * @param  int  $status  The unsuccessful mutation status.
     */
    #[DataProvider('failedMutationStatuses')]
    public function test_failed_mutations_preserve_cached_reads(int $status): void
    {
        // Populate a read before the API rejects its related mutation.
        Http::fakeSequence()->push(['version' => 1])->push(['error' => true], $status);
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $failed = Http::remember(group: self::GROUP)->put(self::MUTATION_URL);

        // Preserve both the error response and the previously cached read.
        self::assertSame($status, $failed->status());
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Provide client and server failures that must not invalidate a group.
     *
     * @return iterable<string, array{int}> Error statuses keyed by HTTP code.
     */
    public static function failedMutationStatuses(): iterable
    {
        // Keep existing reads available after a rejected mutation.
        foreach ([400, 401, 429, 500] as $status) {
            yield (string) $status => [$status];
        }
    }

    /**
     * Confirm a rejected mutation promise preserves its normal exception and cached reads.
     */
    public function test_connection_failures_preserve_the_group(): void
    {
        // Cache a response before a mutation fails to connect.
        Http::fakeSequence()->push(['version' => 1])->pushFailedConnection();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);

        // Retain Laravel's foreground exception instead of converting it into a response.
        try {
            Http::remember(group: self::GROUP)->put(self::MUTATION_URL);
            self::fail('The mutation did not preserve its connection failure.');
        } catch (ConnectionException) {
            self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        }

        // Only the original read and rejected mutation should reach the handler.
        Http::assertSentCount(2);
    }

    /**
     * Confirm asynchronous reads and mutations preserve their normal promise contract.
     */
    public function test_async_reads_and_mutations_share_group_invalidation(): void
    {
        // Populate a POST read before requesting its asynchronous cache hit.
        $this->fakeVersionedApi();
        $first = Http::async()->remember(60, group: self::GROUP, operation: 'read')->post(self::API_URL);
        self::assertInstanceOf(PromiseInterface::class, $first);
        self::assertSame(1, $first->wait()->json('version'));
        $second = Http::async()->remember(60, group: self::GROUP, operation: 'read')->post(self::API_URL);
        self::assertInstanceOf(PromiseInterface::class, $second);
        self::assertSame(1, $second->wait()->json('version'));

        // Await a live mutation before reading the replacement group generation.
        $mutation = Http::async()->remember(group: self::GROUP)->put(self::MUTATION_URL);
        self::assertInstanceOf(PromiseInterface::class, $mutation);
        self::assertSame(2, $mutation->wait()->json('version'));
        self::assertSame(3, Http::remember(60, group: self::GROUP, operation: 'read')->post(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Confirm a reused builder retains its group while each request's method decides intent.
     */
    public function test_reused_builders_infer_reads_and_mutations_per_request(): void
    {
        // Configure the client once for a sequence of reads and writes.
        $this->fakeVersionedApi();
        $api = Http::remember(60, group: self::GROUP);
        self::assertSame(1, $api->get(self::API_URL)->json('version'));
        self::assertSame(1, $api->get(self::API_URL)->json('version'));

        // Send a mutation through the same builder without another remember call.
        self::assertSame(2, $api->put(self::MUTATION_URL)->json('version'));
        self::assertSame(3, $api->get(self::API_URL)->json('version'));
        self::assertSame(3, $api->get(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Confirm replacing a builder's policy resets its operation without changing a clone.
     */
    public function test_replacing_a_policy_keeps_cloned_read_intent_independent(): void
    {
        // Cache a POST read and retain its original policy on a clone.
        $this->fakeVersionedApi();
        $original = Http::remember(60, group: self::GROUP, operation: 'read');
        $clone = clone $original;
        self::assertSame(1, $clone->post(self::API_URL)->json('version'));

        // Omitting operation on a replacement policy restores automatic mutation detection.
        $original->remember(60, group: self::GROUP);
        self::assertSame(2, $original->post(self::API_URL)->json('version'));
        self::assertSame(3, $clone->post(self::API_URL)->json('version'));
        self::assertSame(3, $clone->post(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Confirm uploads and later request hooks still retain mutation invalidation.
     */
    public function test_mutation_uploads_and_custom_hooks_still_invalidate(): void
    {
        // Populate a read before sending a mutation unsuitable for response caching.
        $this->fakeVersionedApi();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $callbacks = 0;

        // Preserve both the live upload and its caller's preparation callback.
        Http::remember(group: self::GROUP)
            ->withMiddleware(
                /**
                 * Forward through a wrapper that obscures Laravel's preparation handler.
                 */
                static function (callable $handler): callable {
                    return
                        /**
                         * Keep the original upload and transfer options available to the handler.
                         */
                        static fn (RequestInterface $request, array $options): PromiseInterface => $handler($request, $options);
                },
            )
            ->beforeSending(
                /**
                 * Observe live preparation without changing the selected group.
                 */
                static function () use (&$callbacks): void {
                    $callbacks++;
                },
            )
            ->attach('file', 'uploaded-body', 'file.txt')
            ->post(self::MUTATION_URL);

        // The callback runs and the next read observes the invalidated group.
        self::assertSame(1, $callbacks);
        self::assertSame(3, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Confirm read overrides preserve preparation callbacks without invalidating a group.
     */
    public function test_read_override_retains_cache_bypasses(): void
    {
        // Populate a related read before using a custom preparation callback.
        $this->fakeVersionedApi();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $callbacks = 0;
        $pending = Http::remember(60, group: self::GROUP, operation: 'read')->beforeSending(
            /**
             * Require preparation on every live request that bypasses caching.
             */
            static function () use (&$callbacks): void {
                $callbacks++;
            },
        );

        // Keep these reads live because their final identity cannot be guaranteed.
        self::assertSame(2, $pending->post(self::API_URL)->json('version'));
        self::assertSame(3, $pending->post(self::API_URL)->json('version'));
        self::assertSame(2, $callbacks);
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Confirm a mutation redirect invalidates before the redirected read reaches the cache.
     */
    public function test_redirects_invalidate_before_following_the_location(): void
    {
        // Populate a read using the content type retained by a redirected POST.
        Http::fakeSequence()
            ->push(['version' => 1])
            ->push('', 303, ['Location' => self::API_URL])
            ->push(['version' => 2]);
        Http::withHeaders(['Content-Type' => 'application/json'])
            ->remember(60, group: self::GROUP)->get(self::API_URL);

        // The redirected GET must fetch the updated resource before returning to the caller.
        $redirected = Http::remember(60, group: self::GROUP)->post(self::MUTATION_URL);
        self::assertSame(2, $redirected->json('version'));
        self::assertSame(2, Http::withHeaders(['Content-Type' => 'application/json'])
            ->remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Confirm POST reads refresh normally and skip refreshes invalidated before execution.
     */
    public function test_post_read_refreshes_respect_group_invalidation(): void
    {
        // Freeze time before populating a read with a short fresh period.
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        $this->fakeVersionedApi();
        Http::remember([10, 60], group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'linen']);

        // Return stale data and deduplicate deferred refreshes of that same read.
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        self::assertSame(1, Http::remember([10, 60], group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'linen'])->json('version'));
        self::assertSame(1, Http::remember([10, 60], group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'linen'])->json('version'));
        self::assertCount(1, app(DeferredCallbackCollection::class));
        Http::assertSentCount(1);
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame(2, Http::remember([10, 60], group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'linen'])->json('version'));

        // Invalidate a later scheduled refresh before it can repeat the old request.
        Carbon::setTestNow($startedAt->copy()->addSeconds(20));
        Http::remember([10, 60], group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'linen']);
        self::assertCount(1, app(DeferredCallbackCollection::class));
        Http::remember(group: self::GROUP)->put(self::MUTATION_URL);
        app(DeferredCallbackCollection::class)->invoke();
        Http::assertSentCount(3);

        // Fetch the updated query result through the next foreground request.
        self::assertSame(4, Http::remember([10, 60], group: self::GROUP, operation: 'read')->post(self::API_URL, ['query' => 'linen'])->json('version'));
        Http::assertSentCount(4);
    }

    /**
     * Confirm an older foreground response cannot populate a group after invalidation.
     */
    public function test_invalidation_cancels_an_in_flight_cache_write(): void
    {
        // Hold the first read while a mutation invalidates the generation it observed.
        $transfer = new Promise;
        $reads = 0;
        $handler = $this->middleware(60)(
            /**
             * Keep an older read in flight while allowing a mutation to complete.
             */
            static function (RequestInterface $request) use ($transfer, &$reads): PromiseInterface {
                if ($request->getMethod() === 'PUT') {
                    return Create::promiseFor(new PsrResponse(204));
                }

                return ++$reads === 1 ? $transfer : Create::promiseFor(new PsrResponse(200, [], 'fresh-response'));
            },
        );
        $request = new PsrRequest('GET', self::API_URL);
        $older = $handler($request, []);
        $handler(new PsrRequest('PUT', self::MUTATION_URL), [])->wait();

        // Preserve the older caller's response while cancelling its cache persistence.
        $transfer->resolve(new PsrResponse(200, [], 'older-response'));
        self::assertSame('older-response', (string) $older->wait()->getBody());
        self::assertSame('fresh-response', (string) $handler($request, [])->wait()->getBody());
        self::assertSame('fresh-response', (string) $handler($request, [])->wait()->getBody());
        self::assertSame(2, $reads);
        self::assertCount(2, Cache::store()->getStore()->all());
    }

    /**
     * Confirm an in-flight stale refresh cannot repopulate an invalidated group.
     */
    public function test_invalidation_cancels_an_in_flight_stale_refresh(): void
    {
        // Populate a stale generation whose refresh completes only after a mutation.
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        $reads = 0;
        $handler = null;
        $handler = $this->middleware([10, 60])(
            /**
             * Complete a mutation while the deferred refresh waits for its response.
             */
            static function (RequestInterface $request) use (&$reads, &$handler): PromiseInterface {
                if ($request->getMethod() === 'PUT') {
                    return Create::promiseFor(new PsrResponse(204));
                }

                if (++$reads !== 2) {
                    return Create::promiseFor(new PsrResponse(200, [], (string) $reads));
                }

                $transfer = null;
                $transfer = new Promise(
                    /**
                     * Invalidate the refresh's group before fulfilling its old response.
                     */
                    static function () use (&$transfer, &$handler): void {
                        $handler(new PsrRequest('PUT', self::MUTATION_URL), [])->wait();
                        $transfer->resolve(new PsrResponse(200, [], 'older-refreshed-response'));
                    },
                );

                return $transfer;
            },
        );
        $request = new PsrRequest('GET', self::API_URL);
        self::assertSame('1', (string) $handler($request, [])->wait()->getBody());
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        self::assertSame('1', (string) $handler($request, [])->wait()->getBody());

        // Only the next foreground read may populate the replacement group generation.
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame('3', (string) $handler($request, [])->wait()->getBody());
        self::assertSame('3', (string) $handler($request, [])->wait()->getBody());
        self::assertSame(3, $reads);
    }

    /**
     * Confirm metadata eviction cannot make older responses visible again.
     */
    public function test_missing_group_metadata_creates_a_new_generation(): void
    {
        // Populate a response and retain its old cache entry while removing only metadata.
        $this->fakeVersionedApi();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $settings = new HttpRememberOptions(60, null, 15, self::GROUP);
        Cache::store()->forget('http-remember:group:'.$settings->groupHash);

        // The replacement generation must not restore that older response.
        self::assertSame(2, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm invalidation uses the store selected before its transfer starts.
     */
    public function test_invalidation_remains_bound_to_the_original_store(): void
    {
        // Populate the same group through two independent named stores.
        config(['cache.stores.secondary' => ['driver' => 'array']]);
        $this->fakeVersionedApi();
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        Http::remember(60, store: 'secondary', group: self::GROUP)->get(self::API_URL);

        // Change the default while the live mutation is being prepared for transfer.
        Http::remember(group: self::GROUP)->beforeSending(
            /**
             * Simulate an application changing its default store during a transfer.
             */
            static function (): void {
                Cache::setDefaultDriver('secondary');
            },
        )->put(self::MUTATION_URL);

        // Keep the secondary cache and invalidate only the initially selected store.
        self::assertSame(2, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertSame(4, Http::remember(60, store: 'array', group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(4);
    }

    /**
     * Confirm cache failures cannot replace a successful mutation response.
     *
     * @param  bool  $throw  Whether persistence throws instead of returning false.
     */
    #[DataProvider('cacheWriteFailures')]
    public function test_invalidation_failures_preserve_the_live_response(bool $throw): void
    {
        // Resolve the repository normally but reject its group generation write.
        $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $write = $cache->shouldReceive('forever')->once();
        if ($throw) {
            $write->andThrow(new RuntimeException('Test invalidation failure.'));
        } else {
            $write->andReturnFalse();
        }
        Cache::shouldReceive('store')->once()->with(null)->andReturn($cache);
        Log::shouldReceive('warning')->once();
        $this->fakeVersionedApi();

        // Keep the original upstream response when invalidation is unavailable.
        self::assertSame(1, Http::remember(group: self::GROUP)->put(self::MUTATION_URL)->json('version'));
        Http::assertSentCount(1);
    }

    /**
     * Provide thrown and nonthrowing persistence failures.
     *
     * @return iterable<string, array{bool}> Failures keyed by persistence behavior.
     */
    public static function cacheWriteFailures(): iterable
    {
        // Cover stores that reject writes through either supported failure path.
        yield 'returns false' => [false];
        yield 'throws' => [true];
    }

    /**
     * Confirm unavailable group metadata falls back to the original live request.
     */
    public function test_group_initialization_failure_preserves_live_reads(): void
    {
        // Reject creation of a missing group generation before response caching starts.
        $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->once()->andReturnNull();
        $cache->shouldReceive('forever')->once()->andReturnFalse();
        Cache::shouldReceive('store')->once()->with(null)->andReturn($cache);
        Log::shouldReceive('warning')->once();
        $this->fakeVersionedApi();

        // The original read must remain usable without any response cache writes.
        self::assertSame(1, Http::remember(group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(1);
    }

    /**
     * Confirm mutations still reach the API when their selected store cannot be resolved.
     */
    public function test_store_resolution_failure_preserves_live_mutations(): void
    {
        // Fail before invalidation can acquire a cache repository.
        Cache::shouldReceive('store')->once()->with(null)->andThrow(new RuntimeException('Test store failure.'));
        Log::shouldReceive('warning')->once();
        $this->fakeVersionedApi();

        // Keep the upstream operation available despite the cache outage.
        self::assertSame(1, Http::remember(group: self::GROUP)->delete(self::MUTATION_URL)->json('version'));
        Http::assertSentCount(1);
    }

    /**
     * Confirm grouping works on stores without cache tags or atomic locks.
     */
    public function test_group_invalidation_works_without_atomic_locks(): void
    {
        // Adapt the memory store to expose only the basic cache store contract.
        $memory = Cache::store()->getStore();
        $store = Mockery::mock(Store::class);
        $store->shouldReceive('get')->andReturnUsing(
            /**
             * Read metadata and response values through the underlying memory store.
             */
            static fn (string $key): mixed => $memory->get($key),
        );
        $store->shouldReceive('put')->andReturnUsing(
            /**
             * Retain response values for their normal backend lifetimes.
             */
            static fn (string $key, mixed $value, int $seconds): bool => $memory->put($key, $value, $seconds),
        );
        $store->shouldReceive('forever')->andReturnUsing(
            /**
             * Retain group metadata without requiring additional store capabilities.
             */
            static fn (string $key, mixed $value): bool => $memory->forever($key, $value),
        );
        Cache::shouldReceive('store')->with(null)->andReturn(new Repository($store));
        $this->fakeVersionedApi();

        // Cache, invalidate, and repopulate through the basic store interface.
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::remember(group: self::GROUP)->put(self::MUTATION_URL);
        self::assertSame(3, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertSame(3, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Confirm generation metadata works with a real persistent file cache.
     */
    public function test_group_invalidation_works_with_the_file_store(): void
    {
        // Give this test its own temporary directory and named cache store.
        $path = sys_get_temp_dir().'/http-remember-group-'.bin2hex(random_bytes(8));
        config(['cache.stores.group-files' => ['driver' => 'file', 'path' => $path]]);
        $this->fakeVersionedApi();

        // Exercise the complete read, hit, mutation, and replacement lifecycle.
        try {
            self::assertSame(1, Http::remember(60, store: 'group-files', group: self::GROUP)->get(self::API_URL)->json('version'));
            self::assertSame(1, Http::remember(60, store: 'group-files', group: self::GROUP)->get(self::API_URL)->json('version'));
            Http::remember(store: 'group-files', group: self::GROUP)->put(self::MUTATION_URL);
            self::assertSame(3, Http::remember(60, store: 'group-files', group: self::GROUP)->get(self::API_URL)->json('version'));
            self::assertSame(3, Http::remember(60, store: 'group-files', group: self::GROUP)->get(self::API_URL)->json('version'));
            Http::assertSentCount(3);
        } finally {
            // Remove only this test's temporary cache files.
            (new Filesystem)->deleteDirectory($path);
        }
    }

    /**
     * Create grouped middleware for tests that control promise completion directly.
     *
     * @param  int|array{int, int}  $ttl  The lifetime policy exercised by the test.
     * @return HttpRememberMiddleware The policy without Laravel-specific preparation hooks.
     */
    private function middleware(int|array $ttl): HttpRememberMiddleware
    {
        // Let direct handler tests focus on group generations and transfer ordering.
        return new HttpRememberMiddleware(
            new HttpRememberOptions($ttl, null, 15, self::GROUP),
            /**
             * Allow controlled reads to use remembered responses.
             */
            static fn (): bool => true,
            /**
             * Keep builder bookkeeping outside direct middleware tests.
             */
            static function (): void {},
        );
    }

    /**
     * Install a fake API that increments its response version for each live transfer.
     */
    private function fakeVersionedApi(): void
    {
        // Count transport calls independently from the HTTP method chosen by the caller.
        $version = 0;
        Http::fake(
            /**
             * Return a distinct response whenever the live handler runs.
             */
            static function () use (&$version): PromiseInterface {
                return Http::response(['version' => ++$version]);
            },
        );
    }
}
