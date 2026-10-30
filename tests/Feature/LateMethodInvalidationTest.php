<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Verify group invalidation follows method changes made after the cache middleware.
 */
final class LateMethodInvalidationTest extends TestCase
{
    /**
     * The remembered read whose group is shared with the changed request.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The endpoint receiving the request after its method changes.
     */
    private const MUTATION_URL = 'https://api.example.test/products/42';

    /**
     * The invalidation group selected by reads and changed requests.
     */
    private const GROUP = ['tenant:42', 'products'];

    /**
     * Confirm automatic invalidation uses the final method for both response execution modes.
     *
     * @param  string  $hook  The late request hook used to change the method.
     * @param  bool  $async  Whether the caller receives a response promise.
     * @param  string  $originalMethod  The method selected before the hook runs.
     * @param  string  $finalMethod  The method received by the live handler.
     * @param  int  $expectedVersion  The read version expected after the changed request.
     */
    #[DataProvider('changedMethods')]
    public function test_final_methods_determine_group_invalidation(string $hook, bool $async, string $originalMethod, string $finalMethod, int $expectedVersion): void
    {
        // Populate a read before preparing a separate request with a late method change.
        Http::fakeSequence()->push(['version' => 1])->push(['version' => 2])->push(['version' => 3]);
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        $pending = $this->changeMethod(Http::async($async)->remember(group: self::GROUP), $hook, $finalMethod);

        // Preserve the changed request's response and confirm its final method reached the handler.
        $response = $pending->send($originalMethod, self::MUTATION_URL);
        self::assertSame(2, ($async ? $response->wait() : $response)->json('version'));
        Http::assertSent(
            /**
             * Match the transferred method independently of the caller's original method.
             */
            static fn (Request $request): bool => $request->url() === self::MUTATION_URL && $request->method() === $finalMethod,
        );

        // Invalidate only mutations and keep the resulting read reusable without deferred work.
        self::assertSame($expectedVersion, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertSame($expectedVersion, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount($expectedVersion === 3 ? 3 : 2);
    }

    /**
     * Provide read-to-mutation, mutation-to-read, and unchanged operation categories.
     *
     * @return iterable<string, array{string, bool, string, string, int}> Hook, execution mode, methods, and expected read version.
     */
    public static function changedMethods(): iterable
    {
        // Exercise late middleware and Laravel preparation callbacks in both execution modes.
        foreach (['middleware', 'before_sending'] as $hook) {
            foreach ([false, true] as $async) {
                $mode = $hook.' '.($async ? 'asynchronous' : 'synchronous');
                yield 'read becomes mutation '.$mode => [$hook, $async, 'GET', 'DELETE', 3];
                yield 'mutation becomes read '.$mode => [$hook, $async, 'DELETE', 'GET', 1];
                yield 'mutation stays mutation '.$mode => [$hook, $async, 'DELETE', 'PATCH', 3];
                yield 'read stays read '.$mode => [$hook, $async, 'GET', 'HEAD', 1];
            }
        }
    }

    /**
     * Confirm callbacks added between sends determine each transfer's final method.
     */
    public function test_reused_builders_observe_callbacks_added_after_their_first_send(): void
    {
        // Keep a remembered read while sending a hooked read through a reusable builder.
        Http::fakeSequence()->push(['version' => 1])->push(['version' => 2])->push(['version' => 3])->push(['version' => 4]);
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $pending = $this->changeMethod(Http::async()->remember(group: self::GROUP), 'before_sending', 'GET');
        self::assertSame(2, $pending->get(self::MUTATION_URL)->wait()->json('version'));
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));

        // Add a later callback after the async handler stack has already been built.
        $this->changeMethod($pending, 'before_sending', 'DELETE');
        self::assertSame(3, $pending->get(self::MUTATION_URL)->wait()->json('version'));

        // Observe the newly added mutation without replaying preparation callbacks or missing invalidation.
        self::assertSame(4, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(4);
    }

    /**
     * Confirm explicit read intent continues to suppress mutation invalidation after method changes.
     */
    public function test_explicit_read_intent_preserves_the_group_after_late_method_changes(): void
    {
        // Populate a group before a declared read changes its HTTP method during preparation.
        Http::fakeSequence()->push(['version' => 1])->push(['version' => 2]);
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $pending = $this->changeMethod(Http::remember(group: self::GROUP, operation: 'read'), 'before_sending', 'POST');
        self::assertSame(2, $pending->get(self::MUTATION_URL)->json('version'));

        // Keep explicit operation intent while retaining the usual late-hook cache bypass.
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);
    }

    /**
     * Confirm failed changed mutations preserve the previous group generation.
     */
    public function test_failed_changed_mutations_do_not_invalidate_the_group(): void
    {
        // Let a late mutation fail after a successful read has populated the group.
        Http::fakeSequence()->push(['version' => 1])->push('unavailable', 503);
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $pending = $this->changeMethod(Http::remember(group: self::GROUP), 'middleware', 'DELETE');
        self::assertSame(503, $pending->get(self::MUTATION_URL)->status());

        // Keep the cached read usable after the upstream operation fails.
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm final-method observation never repeats application preparation callbacks.
     */
    public function test_method_preparation_callbacks_run_once_per_transfer(): void
    {
        // Retain a cached read before a callback chooses the live request's final method.
        Http::fakeSequence()->push(['version' => 1])->push(['version' => 2])->push(['version' => 3]);
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $calls = 0;
        $pending = Http::remember(group: self::GROUP)->beforeSending(
            /**
             * Make a repeated callback change the method and therefore alter invalidation.
             */
            static function (Request $request) use (&$calls): RequestInterface {
                // Select a mutation on the first invocation and a read on an unintended repeat.
                return $request->toPsrRequest()->withMethod(++$calls === 1 ? 'DELETE' : 'GET');
            },
        );

        // Run preparation once while letting its final mutation invalidate the previous read.
        self::assertSame(2, $pending->get(self::MUTATION_URL)->json('version'));
        self::assertSame(1, $calls);
        self::assertSame(3, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        Http::assertSentCount(3);
    }

    /**
     * Confirm overlapping cloned transfers keep their final methods independent.
     */
    public function test_overlapping_cloned_requests_do_not_share_observed_methods(): void
    {
        // Install the fake before creating builders so their stub callbacks include the delayed transfer.
        $calls = 0;
        $transfer = null;
        Http::fake(
            /**
             * Delay only the mutation so completion order differs from preparation order.
             */
            static function (Request $request) use (&$calls, &$transfer): PromiseInterface {
                // Keep each live request's version observable independently of promise completion.
                $version = ++$calls;

                return $request->url() === self::MUTATION_URL ? $transfer : Http::response(['version' => $version]);
            },
        );

        // Give both builders the same late middleware and preparation callback collection.
        $pending = Http::async()->remember(group: self::GROUP)->withRequestMiddleware(
            /**
             * Change only the mutation endpoint while leaving the nested probe as a read.
             */
            static fn (RequestInterface $request): RequestInterface => $request->withMethod(
                (string) $request->getUri() === self::MUTATION_URL ? 'DELETE' : 'GET',
            ),
        );
        $clone = clone $pending;

        // Complete the delayed transfer only after another request has used the shared callbacks.
        $transfer = new Promise(
            /**
             * Send another request while the original mutation waits for its response.
             */
            static function () use (&$transfer, $clone): void {
                // Observe a read on the clone before the mutation completes and invalidates its group.
                self::assertSame(3, $clone->get('https://api.example.test/probe')->wait()->json('version'));
                self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
                $transfer->resolve(new PsrResponse(200, [], '{"version":2}'));
            },
        );

        // Complete the original mutation after the clone has recorded its own final read method.
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        self::assertSame(2, $pending->get(self::MUTATION_URL)->wait()->json('version'));
        self::assertSame(4, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(4);
    }

    /**
     * Confirm cache-only requests cannot send or invalidate a late mutation.
     */
    public function test_cache_only_requests_never_invalidate_late_mutations(): void
    {
        // Keep an existing grouped read while another request forbids reaching the origin.
        Http::fakeSequence()->push(['version' => 1]);
        Http::remember(60, group: self::GROUP)->get(self::API_URL);
        $pending = $this->changeMethod(
            Http::withHeaders(['Cache-Control' => 'only-if-cached'])->remember(group: self::GROUP),
            'before_sending',
            'DELETE',
        );

        // Preserve the network prohibition and leave the unrelated cached representation reachable.
        self::assertSame(504, $pending->get(self::MUTATION_URL)->status());
        self::assertSame(1, Http::remember(60, group: self::GROUP)->get(self::API_URL)->json('version'));
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(1);
    }

    /**
     * Attach a method change after remember without changing the builder's operation intent.
     *
     * @param  PendingRequest  $pending  The builder already carrying its remember policy.
     * @param  string  $hook  The late hook responsible for preparing the method.
     * @param  string  $method  The method to send to the live handler.
     * @return PendingRequest The same builder with the additional hook.
     */
    private function changeMethod(PendingRequest $pending, string $hook, string $method): PendingRequest
    {
        // Place Guzzle request middleware after the cache layer when requested.
        if ($hook === 'middleware') {
            return $pending->withRequestMiddleware(
                /**
                 * Replace the method after the cache layer has inspected the request.
                 */
                static fn (RequestInterface $request): RequestInterface => $request->withMethod($method),
            );
        }

        // Let Laravel replace the PSR request during its normal preparation callbacks.
        return $pending->beforeSending(
            /**
             * Change only the method while preserving the request's URI, body, and headers.
             */
            static fn (Request $request): RequestInterface => $request->toPsrRequest()->withMethod($method),
        );
    }
}
