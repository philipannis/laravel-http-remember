<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WeakReference;

use function Illuminate\Support\defer;

/**
 * Verify refresh scheduling releases duplicate uploads without discarding pending work.
 */
final class RefreshSchedulingTest extends TestCase
{
    /**
     * The shared fake endpoint used by identical-request tests.
     */
    private const API_URL = 'https://api.example.test/search';

    /**
     * Freeze response ages before testing pending refresh replacements.
     */
    protected function setUp(): void
    {
        // Boot an isolated application before starting the cache lifetime.
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    }

    /**
     * Confirm repeated stale uploads release older callbacks before deferred execution.
     *
     * @param  bool  $async  Whether stale requests return promises.
     */
    #[DataProvider('requestModes')]
    public function test_duplicate_refreshes_release_previous_uploads_immediately(bool $async): void
    {
        // Keep each eligible upload large enough to expose retention of duplicate body copies.
        $contents = str_repeat('x', 900_000);
        Http::fake(
            /**
             * Return the complete upload's fingerprint and the request selected for refreshing.
             */
            static fn (Request $request): PromiseInterface => Http::response([
                'body_hash' => hash('sha256', $request->body()),
                'request_id' => $request->header('X-Request-Id')[0],
            ]),
        );

        // Populate one representation whose tracing header is excluded from the cache key.
        Http::withHeaders(['X-Request-Id' => 'initial'])->withBody($contents)
            ->remember([1, 60], operation: 'read')->post(self::API_URL);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $callbacks = app(DeferredCallbackCollection::class);
        $previous = null;

        // Inspect liveness without counting the collection, which itself removes duplicates.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            // Alternate store aliases while retaining the latest eligible request's metadata.
            $response = Http::async($async)->withHeaders(['X-Request-Id' => (string) $attempt])
                ->withBody($contents)->remember([1, 60], store: $attempt % 2 === 0 ? null : 'array', operation: 'read')
                ->post(self::API_URL);
            $response = $async ? $response->wait() : $response;

            // A stale hit must release the superseded closure and its independently copied body.
            self::assertSame('initial', $response->json('request_id'));
            self::assertNull($previous?->get());
            $previous = WeakReference::create($callbacks->first());
        }

        // Execute only the latest refresh after all callers have received their stale responses.
        Http::assertSentCount(1);
        $callbacks->invoke();
        $refreshed = Http::withHeaders(['X-Request-Id' => 'reader'])->withBody($contents)
            ->remember([1, 60], operation: 'read')->post(self::API_URL);

        // Preserve the latest request and complete upload while releasing the completed callback.
        self::assertSame('19', $refreshed->json('request_id'));
        self::assertSame(hash('sha256', $contents), $refreshed->json('body_hash'));
        self::assertNull($previous->get());
        self::assertCount(0, $callbacks);
        Http::assertSentCount(2);
    }

    /**
     * Provide the supported foreground response contracts.
     *
     * @return iterable<string, array{bool}> Modes keyed by promise behavior.
     */
    public static function requestModes(): iterable
    {
        // Exercise both completed responses and asynchronously resolved cache hits.
        yield 'synchronous' => [false];
        yield 'asynchronous' => [true];
    }

    /**
     * Confirm an ineligible replacement does not cancel an earlier valid refresh.
     */
    public function test_rejected_upload_copy_preserves_the_pending_refresh(): void
    {
        // Retain a valid refresh before attempting another copy under the same response key.
        config(['http-remember.max_request_bytes' => 16]);
        Http::fakeSequence()->push('initial')->push('refreshed');
        Http::withBody('query')->remember([1, 60], operation: 'read')->post(self::API_URL);
        Carbon::setTestNow(Carbon::now()->addSecond());
        Http::withBody('query')->remember([1, 60], operation: 'read')->post(self::API_URL);
        $callbacks = app(DeferredCallbackCollection::class);
        $scheduled = $callbacks->first();

        // Enlarge only the replacement request after fingerprinting and before its deferred copy.
        app('events')->listen(RequestSending::class,
            /**
             * Make the later upload ineligible without changing the previously prepared refresh.
             */
            static function (RequestSending $event): void {
                // Leave the valid refresh's independent request body intact during execution.
                if ($event->request->hasHeader('X-Request-Id', 'oversized')) {
                    $event->request->toPsrRequest()->getBody()->write(str_repeat('x', 17));
                }
            },
        );

        // Preserve both the stale response and the earlier callback when preparation is rejected.
        $stale = Http::withHeaders(['X-Request-Id' => 'oversized'])->withBody('query')
            ->remember([1, 60], operation: 'read')->post(self::API_URL);
        self::assertSame('initial', $stale->body());
        self::assertSame($scheduled, $callbacks->first());
        Http::assertSentCount(1);

        // Complete the retained refresh and reuse its replacement without another transfer.
        $callbacks->invoke();
        self::assertSame('refreshed', Http::withBody('query')
            ->remember([1, 60], operation: 'read')->post(self::API_URL)->body());
        Http::assertSentCount(2);
        self::assertCount(0, $callbacks);
    }

    /**
     * Confirm scheduling inside deferred work neither repeats nor removes other callbacks.
     */
    public function test_refreshes_scheduled_during_deferred_execution_preserve_unrelated_work(): void
    {
        // Populate two independent representations and give each exactly one refreshed response.
        $urls = [self::API_URL.'/first', self::API_URL.'/second'];
        Http::fake([
            $urls[0] => Http::sequence()->push('initial')->push('first-refreshed'),
            $urls[1] => Http::sequence()->push('initial')->push('second-refreshed'),
        ]);
        foreach ($urls as $url) {
            // Retain both keys before either application callback requests stale data.
            Http::remember([1, 60])->get($url);
        }
        Carbon::setTestNow(Carbon::now()->addSecond());
        $executed = [];

        // Queue ordinary application work that schedules duplicate refreshes during invocation.
        foreach ($urls as $url) {
            defer(
                /**
                 * Schedule one resource without reindexing callbacks already being invoked.
                 */
                static function () use ($url, &$executed): void {
                    // Record every invocation so accidentally repeated application work is visible.
                    $executed[] = $url;
                    for ($attempt = 0; $attempt < 3; $attempt++) {
                        // Return cached data while replacing only this resource's pending refresh.
                        self::assertSame('initial', Http::remember([1, 60])->get($url)->body());
                    }
                },
            );
        }

        // Invoke application work without assuming newly queued callbacks run in the same pass.
        $callbacks = app(DeferredCallbackCollection::class);
        $callbacks->invoke();
        self::assertSame($urls, $executed);

        // Laravel 12 leaves nested work queued; Laravel 13 already drains it during the first pass.
        $callbacks->invoke();
        self::assertSame($urls, $executed);

        // Require both resources to refresh once while preserving unrelated work and cache keys.
        self::assertSame('first-refreshed', Http::remember([1, 60])->get($urls[0])->body());
        self::assertSame('second-refreshed', Http::remember([1, 60])->get($urls[1])->body());
        Http::assertSentCount(4);
        self::assertCount(0, $callbacks);
    }
}
