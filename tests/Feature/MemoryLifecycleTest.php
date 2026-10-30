<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\HttpRememberRequestObserver;
use PhilipAnnis\HttpRemember\HttpRememberServiceProvider;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use WeakReference;

/**
 * Verify completed HTTP lifecycles release package-owned requests, observers, and callbacks.
 */
final class MemoryLifecycleTest extends TestCase
{
    /**
     * The endpoint used by repeated transfers and deferred refreshes.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * Confirm reused builders do not accumulate completed transfers or observer payloads.
     *
     * @param  bool  $async  Whether the builder uses asynchronous response promises.
     */
    #[DataProvider('requestModes')]
    public function test_reused_builders_release_completed_grouped_transfers(bool $async): void
    {
        // Use a local transport without HTTP fake recording that would intentionally retain requests.
        $references = [];
        $transport =
            /**
             * Observe object lifetimes without taking ownership of their request or response data.
             */
            static function (RequestInterface $request, array $options) use (&$references): PromiseInterface {
                // Keep only weak references while returning a response large enough to expose retention.
                $response = new PsrResponse(200, [], str_repeat('x', 50_000));
                $references[] = [
                    'request' => WeakReference::create($request),
                    'request_body' => WeakReference::create($request->getBody()),
                    'observer' => WeakReference::create($options[HttpRememberRequestObserver::OPTION]),
                    'response' => WeakReference::create($response),
                    'response_body' => WeakReference::create($response->getBody()),
                ];

                return Create::promiseFor($response);
            };

        // Keep one builder alive while sending many independent grouped mutations.
        $pending = Http::async($async)->setHandler($transport)->preventStrayRequests(false)->remember(group: ['memory-lifecycle']);
        $builder = WeakReference::create($pending);
        for ($iteration = 0; $iteration < 40; $iteration++) {
            // Release the caller's result after every completed transfer.
            $result = $pending->post(self::API_URL, ['iteration' => $iteration]);
            $response = $async ? $result->wait() : $result;
            self::assertSame(200, $response->status());
            unset($result, $response);
        }

        // Repeated transfers must keep one stateless observer callback rather than accumulating hooks.
        $callbacks = (new ReflectionProperty(PendingRequest::class, 'beforeSendingCallbacks'))->getValue($pending);
        self::assertCount(HttpRememberServiceProvider::INTERNAL_BEFORE_SENDING_CALLBACK_COUNT + 1, $callbacks);
        unset($callbacks);

        // Laravel may retain its latest request, but earlier completed transfers must be collectible.
        gc_collect_cycles();
        foreach (array_slice($references, 0, -1) as $transfer) {
            foreach ($transfer as $name => $reference) {
                self::assertNull($reference->get(), 'A completed '.$name.' remained reachable on the reused builder.');
            }
        }

        // Discarding the builder must release its remaining request and response objects too.
        unset($pending);
        gc_collect_cycles();
        self::assertNull($builder->get());
        foreach ($references as $transfer) {
            foreach ($transfer as $name => $reference) {
                self::assertNull($reference->get(), 'A discarded builder retained its '.$name.'.');
            }
        }
    }

    /**
     * Provide both public request execution modes.
     *
     * @return iterable<string, array{bool}> Request modes keyed by promise behavior.
     */
    public static function requestModes(): iterable
    {
        // Cover ordinary builders and builders that retain their latest asynchronous promise.
        yield 'synchronous' => [false];
        yield 'asynchronous' => [true];
    }

    /**
     * Confirm deferred success, failure, and cancellation release the builder and policy predicate.
     *
     * @param  int  $status  The refresh response status, or zero for a transport exception.
     * @param  bool  $expire  Whether hard expiry cancels the refresh before transport execution.
     */
    #[DataProvider('refreshOutcomes')]
    public function test_deferred_completion_releases_builders_and_policy_callbacks(int $status, bool $expire): void
    {
        // Freeze cache ages and discard warning records from the intentionally failed refresh.
        Carbon::setTestNow('2026-01-01 12:00:00');
        config(['http-remember.event_logs.channel' => 'null']);
        $transfers = 0;
        $transport =
            /**
             * Complete remembered reads locally without retaining recorded request-response pairs.
             */
            static function (RequestInterface $request) use (&$transfers, $status): PromiseInterface {
                // Include transport failures whose exception temporarily retains the prepared request.
                if (++$transfers > 1 && $status === 0) {
                    throw new ConnectException('Test refresh connection failure.', $request);
                }

                // Populate successfully before applying the selected deferred refresh outcome.
                return Create::promiseFor(new PsrResponse($transfers === 1 ? 200 : $status, [], 'response-body'));
            };

        // Give the response predicate an object whose lifetime is controlled only by the policy.
        $marker = new \stdClass;
        $markerReference = WeakReference::create($marker);
        $predicate =
            /**
             * Keep application callback state alive only while its request policy is needed.
             */
            static fn (Response $response): bool => $marker instanceof \stdClass && $response->successful();
        $predicateReference = WeakReference::create($predicate);
        $pending = Http::setHandler($transport)->preventStrayRequests(false)->remember([1, 60], cacheWhen: $predicate);
        $builder = WeakReference::create($pending);

        // Schedule deferred work and release every explicit reference held by the caller.
        $pending->get(self::API_URL);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $pending->get(self::API_URL);
        $callbacks = app(DeferredCallbackCollection::class);
        $callback = WeakReference::create($callbacks->first());
        unset($pending, $predicate, $marker);
        gc_collect_cycles();
        self::assertNotNull($builder->get());
        self::assertCount(1, $callbacks);

        // Make the pending generation expire when this case exercises cancellation.
        if ($expire) {
            Carbon::setTestNow(Carbon::now()->addSeconds(60));
        }

        // Completing the lifecycle must leave no deferred closure or policy object reachable.
        $callbacks->invoke();
        gc_collect_cycles();
        self::assertCount(0, $callbacks);
        self::assertNull($callback->get());
        self::assertNull($builder->get());
        self::assertNull($predicateReference->get());
        self::assertNull($markerReference->get());
        self::assertSame($expire ? 1 : 2, $transfers);
    }

    /**
     * Provide the lifecycle outcomes that must discard captured application state.
     *
     * @return iterable<string, array{int, bool}> Refresh status and cancellation flag.
     */
    public static function refreshOutcomes(): iterable
    {
        // Successful, unsuccessful, and skipped work must all release their deferred captures.
        yield 'successful refresh' => [200, false];
        yield 'failed refresh' => [503, false];
        yield 'transport exception' => [0, false];
        yield 'expired generation' => [200, true];
    }
}
