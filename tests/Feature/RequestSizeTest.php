<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;
use PhilipAnnis\HttpRemember\HttpRememberMiddleware;
use PhilipAnnis\HttpRemember\HttpRememberOptions;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Verify request size budgets before cache access and deferred upload copies.
 */
final class RequestSizeTest extends TestCase
{
    /**
     * The shared fake endpoint used by identical-request tests.
     */
    private const API_URL = 'https://api.example.test/search';

    /**
     * The small byte budget used to exercise request size boundaries.
     */
    private const MAX_REQUEST_BYTES = 16;

    /**
     * Confirm the default bypasses large file uploads without reading their contents.
     */
    public function test_default_limit_skips_large_file_uploads_without_reading(): void
    {
        // Use a sparse file so preparing the upload does not allocate its entire body.
        self::assertSame(1_000_000, config('http-remember.max_request_bytes'));
        $file = tmpfile();
        ftruncate($file, 20 * 1024 * 1024);
        $stream = FnStream::decorate(Utils::streamFor($file), [
            'read' =>
                /**
                 * Reject fingerprinting when the advertised size already exceeds the budget.
                 */
                static fn (int $length): string => throw new RuntimeException('An oversized upload was read.'),
            'getContents' =>
                /**
                 * Reject the unbounded copy that previously occurred while scheduling a refresh.
                 */
                static fn (): string => throw new RuntimeException('An unbounded upload copy was attempted.'),
        ]);
        Http::fakeSequence()->push('first-response')->push('second-response');

        // Preserve ordinary live transfers without caching or deferring either response.
        self::assertSame('first-response', Http::withBody($stream)->remember([0, 60])->post(self::API_URL)->body());
        self::assertSame('second-response', Http::withBody($stream)->remember([0, 60])->post(self::API_URL)->body());
        self::assertSame(0, $stream->tell());
        self::assertSame([], Cache::store()->getStore()->all());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);
    }

    /**
     * Confirm the inclusive size limit applies to synchronous and asynchronous requests.
     *
     * @param  int  $bodyBytes  The complete outgoing request body size.
     * @param  bool  $async  Whether requests return promises.
     */
    #[DataProvider('requestSizeModes')]
    public function test_configured_limit_bounds_caching_without_changing_uploads(int $bodyBytes, bool $async): void
    {
        // Keep the response budget larger than the independent upload budget.
        config(['http-remember.max_request_bytes' => self::MAX_REQUEST_BYTES]);
        $contents = str_repeat('x', $bodyBytes);
        Http::fake(
            /**
             * Verify the live transport receives the complete original body.
             */
            static function (HttpRequest $request) use ($contents): PromiseInterface {
                self::assertSame($contents, $request->body());

                return Http::response(str_repeat('r', 32));
            },
        );

        // Reuse only eligible requests while preserving both response contracts.
        for ($call = 0; $call < 2; $call++) {
            $response = Http::async($async)->withBody($contents)->remember(60)->post(self::API_URL);
            if ($async) {
                self::assertInstanceOf(PromiseInterface::class, $response);
                $response = $response->wait();
            }

            self::assertSame(str_repeat('r', 32), $response->body());
        }

        $cacheable = $bodyBytes <= self::MAX_REQUEST_BYTES;
        self::assertCount($cacheable ? 1 : 0, Cache::store()->getStore()->all());
        Http::assertSentCount($cacheable ? 1 : 2);
    }

    /**
     * Provide upload boundaries for both public HTTP execution modes.
     *
     * @return iterable<string, array{int, bool}> Body sizes keyed by boundary and request mode.
     */
    public static function requestSizeModes(): iterable
    {
        // Cover empty requests as well as both sides of the inclusive byte limit.
        foreach ([false, true] as $async) {
            $mode = $async ? 'asynchronous' : 'synchronous';
            yield $mode.' empty body' => [0, $async];
            yield $mode.' below limit' => [self::MAX_REQUEST_BYTES - 1, $async];
            yield $mode.' at limit' => [self::MAX_REQUEST_BYTES, $async];
            yield $mode.' above limit' => [self::MAX_REQUEST_BYTES + 1, $async];
        }
    }

    /**
     * Confirm actual bytes enforce the limit when stream sizes are unknown or inaccurate.
     *
     * @param  int  $bodyBytes  The complete outgoing body size.
     * @param  int|null  $reportedSize  The size advertised by the upload stream.
     */
    #[DataProvider('requestStreamSizes')]
    public function test_unknown_sizes_and_short_reads_preserve_complete_uploads(int $bodyBytes, ?int $reportedSize): void
    {
        // Hide the actual size and return short chunks to exercise bounded fingerprinting.
        $contents = str_repeat('x', $bodyBytes);
        $body = Utils::streamFor($contents);
        $readBytes = 0;
        $stream = FnStream::decorate($body, [
            'getSize' => static fn (): ?int => $reportedSize,
            'read' =>
                /**
                 * Count actual bytes without allowing a full-body allocation.
                 */
                static function (int $length) use ($body, &$readBytes): string {
                    $chunk = $body->read(min(3, $length));
                    $readBytes += strlen($chunk);

                    return $chunk;
                },
            'getContents' => static fn (): string => throw new RuntimeException('An unbounded upload read was attempted.'),
        ]);
        $sent = 0;
        $handler = $this->middleware()(
            /**
             * Observe the restored stream without consuming it in the test transport.
             */
            static function (RequestInterface $request) use (&$sent): PromiseInterface {
                $sent++;
                self::assertSame(0, $request->getBody()->tell());

                return Create::promiseFor(new Response(200, [], 'response-body'));
            },
        );
        $request = new Request('POST', self::API_URL, ['Content-Length' => '1'], $stream);

        // Bound each cache lookup to the budget plus one byte and preserve the upload cursor.
        for ($call = 0; $call < 2; $call++) {
            $readBytes = 0;
            self::assertSame('response-body', (string) $handler($request, [])->wait()->getBody());
            self::assertSame(min($bodyBytes, self::MAX_REQUEST_BYTES + 1), $readBytes);
            self::assertSame(0, $stream->tell());
        }

        self::assertSame($contents, (string) $body);
        self::assertSame($bodyBytes <= self::MAX_REQUEST_BYTES ? 1 : 2, $sent);
    }

    /**
     * Provide complete and oversized uploads with unreliable size metadata.
     *
     * @return iterable<string, array{int, int|null}> Upload sizes keyed by metadata and boundary.
     */
    public static function requestStreamSizes(): iterable
    {
        // Never trust either a missing size or an understated stream size to bound a copy.
        yield 'empty unknown body' => [0, null];
        yield 'small unknown body' => [self::MAX_REQUEST_BYTES - 1, null];
        yield 'exact unknown body' => [self::MAX_REQUEST_BYTES, null];
        yield 'large unknown body' => [4096, null];
        yield 'underreported exact body' => [self::MAX_REQUEST_BYTES, 1];
        yield 'underreported oversized body' => [4096, 1];
    }

    /**
     * Confirm a failed size inspection restores the upload before the live transfer.
     */
    public function test_request_read_failures_preserve_the_live_upload(): void
    {
        // Fail after consuming a prefix so restoration is required to preserve the request.
        $body = Utils::streamFor('query');
        $stream = FnStream::decorate($body, [
            'read' =>
                /**
                 * Simulate a stream failure partway through fingerprinting.
                 */
                static function (int $length) use ($body): string {
                    $body->read(2);

                    throw new RuntimeException('The upload could not be inspected.');
                },
        ]);
        $handler = $this->middleware()(
            /**
             * Preserve the live handler's result after the package abandons caching.
             */
            static function (RequestInterface $request): PromiseInterface {
                self::assertSame(0, $request->getBody()->tell());

                return Create::promiseFor(new Response(200, [], 'live-body'));
            },
        );

        // Return the normal response without leaving the upload consumed or storing a result.
        $response = $handler(new Request('POST', self::API_URL, [], $stream), [])->wait();
        self::assertSame('live-body', (string) $response->getBody());
        self::assertSame('query', (string) $body);
        self::assertSame([], Cache::store()->getStore()->all());
    }

    /**
     * Confirm reducing the budget bypasses an existing stale entry without scheduling a refresh.
     */
    public function test_lowering_the_limit_bypasses_existing_entries(): void
    {
        // Store a response under a larger upload budget before changing configuration.
        $start = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($start);
        $contents = str_repeat('x', self::MAX_REQUEST_BYTES + 1);
        Http::fakeSequence()->push('initial-body')->push('live-body');
        Http::withBody($contents)->remember([10, 60])->post(self::API_URL);
        $cached = Cache::store()->getStore()->all();
        config(['http-remember.max_request_bytes' => self::MAX_REQUEST_BYTES]);
        Carbon::setTestNow($start->copy()->addSeconds(10));
        $events = [];
        app('events')->listen(HttpRememberEventLog::class, static function (HttpRememberEventLog $event) use (&$events): void {
            $events[] = $event;
        });

        // Apply the new request budget before cache access while leaving old entries to expire.
        self::assertSame('live-body', Http::withBody($contents)->remember([10, 60])->post(self::API_URL)->body());
        self::assertSame($cached, Cache::store()->getStore()->all());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        self::assertCount(1, $events);
        self::assertSame('bypass', $events[0]->event);
        self::assertSame('request_size', $events[0]->reason);
        self::assertNull($events[0]->cacheKey);
        Http::assertSentCount(2);
    }

    /**
     * Confirm the captured budget does not become part of an eligible request's cache key.
     */
    public function test_request_budgets_are_captured_without_changing_cache_identity(): void
    {
        // Capture the original request budget before changing the configured default.
        config(['http-remember.max_request_bytes' => self::MAX_REQUEST_BYTES]);
        Http::fakeSequence()->push('cached-body')->push('live-body');
        $contents = str_repeat('x', self::MAX_REQUEST_BYTES);
        $pending = Http::withBody($contents)->remember(60);
        config(['http-remember.max_request_bytes' => self::MAX_REQUEST_BYTES - 1]);

        // Keep the existing builder's policy while new builders enforce the smaller limit.
        self::assertSame('cached-body', $pending->post(self::API_URL)->body());
        self::assertSame('live-body', Http::withBody($contents)->remember(60)->post(self::API_URL)->body());

        // Reuse the original entry after increasing the budget without changing its identity.
        config(['http-remember.max_request_bytes' => self::MAX_REQUEST_BYTES + 1]);
        self::assertSame('cached-body', Http::withBody($contents)->remember(60)->post(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm raised limits retain bounded memory and an independent deferred upload.
     */
    public function test_large_eligible_refreshes_copy_uploads_in_bounded_chunks(): void
    {
        // Permit a file-backed body much larger than the temporary stream's memory buffer.
        $start = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($start);
        $bytes = 20 * 1024 * 1024;
        $file = tmpfile();
        ftruncate($file, $bytes);
        $body = Utils::streamFor($file);
        $uploads = [];
        $handler = $this->middleware([1, 60], $bytes)(
            /**
             * Retain each transport's stream to verify the deferred request owns its copy.
             */
            static function (RequestInterface $request) use (&$uploads): PromiseInterface {
                $uploads[] = $request->getBody();

                return Create::promiseFor(new Response(200, [], 'response-body'));
            },
        );
        $request = new Request('POST', self::API_URL, [], $body);
        $handler($request, [])->wait();
        Carbon::setTestNow($start->copy()->addSeconds(1));

        // Scheduling a stale refresh must not allocate another complete upload in memory.
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $handler($request, [])->wait();
        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        self::assertSame(0, $body->tell());
        self::assertCount(1, app(DeferredCallbackCollection::class));

        // Change the original upload after scheduling without altering the deferred body.
        $body->write('changed');
        app(DeferredCallbackCollection::class)->invoke();
        self::assertCount(2, $uploads);
        self::assertNotSame($uploads[0], $uploads[1]);
        self::assertSame($bytes, $uploads[1]->getSize());
        self::assertSame(0, $uploads[1]->tell());
        self::assertSame(str_repeat("\0", 7), $uploads[1]->read(7));
    }

    /**
     * Confirm request events cannot enlarge a deferred copy beyond the configured budget.
     */
    public function test_refresh_rechecks_bodies_changed_by_request_events(): void
    {
        // Populate an eligible request before a cache-hit event changes its stream.
        config(['http-remember.max_request_bytes' => self::MAX_REQUEST_BYTES]);
        $start = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($start);
        Http::fakeSequence()->push('initial-body');
        Http::withBody('query')->remember([10, 60])->post(self::API_URL);
        Carbon::setTestNow($start->copy()->addSeconds(10));
        $body = null;
        app('events')->listen(RequestSending::class, static function (RequestSending $event) use (&$body): void {
            $body = $event->request->toPsrRequest()->getBody();
            $body->write(str_repeat('x', self::MAX_REQUEST_BYTES + 1));
        });
        $events = [];
        app('events')->listen(HttpRememberEventLog::class, static function (HttpRememberEventLog $event) use (&$events): void {
            $events[] = $event;
        });

        // Keep the cached response while cancelling the oversized refresh and restoring the cursor.
        self::assertSame('initial-body', Http::withBody('query')->remember([10, 60])->post(self::API_URL)->body());
        self::assertSame(self::MAX_REQUEST_BYTES + 1, $body->tell());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        self::assertSame('refresh_skipped', $events[1]->event);
        self::assertSame('request_size', $events[1]->reason);
        Http::assertSentCount(1);
    }

    /**
     * Confirm oversized grouped mutations still invalidate related cached reads.
     */
    public function test_oversized_grouped_mutations_still_invalidate(): void
    {
        // Exercise the upload bypass without changing the mutation's existing group behavior.
        config(['http-remember.max_request_bytes' => self::MAX_REQUEST_BYTES]);
        Http::fakeSequence()->push('initial-body')->push('mutation-body')->push('updated-body');
        $pending = Http::remember(60, group: ['search']);
        self::assertSame('initial-body', $pending->get(self::API_URL)->body());

        // Send the mutation live and force the following read to fetch its replacement.
        Http::withBody(str_repeat('x', self::MAX_REQUEST_BYTES + 1))
            ->remember(group: ['search'])->post(self::API_URL);
        self::assertSame('updated-body', $pending->get(self::API_URL)->body());
        Http::assertSentCount(3);
    }

    /**
     * Confirm invalid configuration fails before attaching request middleware.
     */
    public function test_non_positive_configuration_is_rejected_immediately(): void
    {
        // Reject an unusable upload budget when resolving the request's cache policy.
        config(['http-remember.max_request_bytes' => 0]);
        $this->expectException(InvalidArgumentException::class);

        Http::remember();
    }

    /**
     * Create a policy for controlled upload streams without a live transport.
     *
     * @param  int|array{int, int}  $ttl  The fixed lifetime or fresh and total lifetime thresholds.
     * @param  int  $maxRequestBytes  The inclusive request body budget in bytes.
     * @return HttpRememberMiddleware The cache layer using the supplied upload budget.
     */
    private function middleware(int|array $ttl = 60, int $maxRequestBytes = self::MAX_REQUEST_BYTES): HttpRememberMiddleware
    {
        // Keep request mutation and Laravel's hit bookkeeping outside the stream-focused tests.
        return new HttpRememberMiddleware(
            new HttpRememberOptions($ttl, null, 15, maxRequestBytes: $maxRequestBytes),
            static fn (): bool => true,
            static function (): void {},
        );
    }
}
