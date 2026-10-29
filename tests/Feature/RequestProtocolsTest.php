<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;
use PhilipAnnis\HttpRemember\HttpRememberMiddleware;
use PhilipAnnis\HttpRemember\HttpRememberOptions;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Verify protocol restrictions before cache reuse, storage, and deferred refreshes.
 */
final class RequestProtocolsTest extends TestCase
{
    /**
     * The plaintext endpoint whose cached response must not satisfy HTTPS-only requests.
     */
    private const HTTP_URL = 'http://api.example.test/products';

    /**
     * Confirm protocol restrictions bypass both fresh and stale entries in either request mode.
     *
     * @param  bool  $async  Whether the restricted request returns a promise.
     * @param  int  $age  The age of the previously cached response in seconds.
     * @param  bool  $onlyIfCached  Whether the request forbids a live fallback.
     */
    #[DataProvider('restrictedRequestModes')]
    public function test_disallowed_protocols_bypass_existing_entries(bool $async, int $age, bool $onlyIfCached): void
    {
        // Populate the normal request identity before adding a stricter protocol policy.
        Carbon::setTestNow('2026-01-01 12:00:00');
        Http::fakeSequence()->push('cached-body')->push('live-body');
        Http::remember([10, 60])->get(self::HTTP_URL);
        $original = Cache::store()->getStore()->all();
        Carbon::setTestNow(Carbon::now()->addSeconds($age));

        // Capture the rejection reason without including the original population events.
        $events = [];
        Event::listen(HttpRememberEventLog::class,
            /**
             * Retain the restricted request's cache decisions for assertions.
             */
            static function (HttpRememberEventLog $event) use (&$events): void {
                // Keep events in their original order without changing request handling.
                $events[] = $event;
            },
        );

        // Keep the fake transport authoritative while preventing reuse of the cached body.
        $pending = Http::async($async)->withOptions(['protocols' => ['https']])->remember([10, 60]);
        if ($onlyIfCached) {
            // Exercise the existing network prohibition on the same cached representation.
            $pending->withHeaders(['Cache-Control' => 'only-if-cached']);
        }
        $response = $pending->get(self::HTTP_URL);
        if ($async) {
            // Preserve Laravel's promise contract even when no transfer is permitted.
            self::assertInstanceOf(PromiseInterface::class, $response);
            $response = $response->wait();
        }

        // Reject the cached representation without replacing it or scheduling a refresh.
        self::assertSame($onlyIfCached ? 504 : 200, $response->status());
        self::assertSame($onlyIfCached ? '' : 'live-body', $response->body());
        self::assertSame('bypass', $events[0]->event);
        self::assertSame('protocols', $events[0]->reason);
        self::assertNull($events[0]->cacheKey);
        self::assertSame($original, Cache::store()->getStore()->all());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount($onlyIfCached ? 1 : 2);
    }

    /**
     * Provide freshness boundaries and network permissions for both execution modes.
     *
     * @return iterable<string, array{bool, int, bool}> Restricted request combinations.
     */
    public static function restrictedRequestModes(): iterable
    {
        // Exercise fresh and stale responses with and without permission to contact the transport.
        foreach ([false, true] as $async) {
            foreach ([0, 10] as $age) {
                foreach ([false, true] as $onlyIfCached) {
                    // Name each combination so failures identify its promise and cache-only behavior.
                    yield ($async ? 'async' : 'sync').' age '.$age.($onlyIfCached ? ' cache only' : ' live allowed') => [$async, $age, $onlyIfCached];
                }
            }
        }
    }

    /**
     * Confirm allowed protocols reuse the normal identity and retain stale refresh behavior.
     *
     * @param  string  $scheme  The endpoint's permitted URI scheme.
     * @param  array<string, mixed>  $options  The equivalent protocol policy supplied by the caller.
     */
    #[DataProvider('allowedProtocols')]
    public function test_allowed_protocols_share_entries_and_refresh_normally(string $scheme, array $options): void
    {
        // Populate the response without an explicit protocol restriction.
        Carbon::setTestNow('2026-01-01 12:00:00');
        $url = $scheme.'://api.example.test/products';
        Http::fakeSequence()->push('initial-body')->push('updated-body');
        Http::remember([10, 60])->get($url);
        $pending = Http::withOptions($options)->remember([10, 60]);

        // A compatible policy must use the same fresh entry without another transfer.
        self::assertSame('initial-body', $pending->get($url)->body());
        Http::assertSentCount(1);

        // Preserve deferred refreshes after the shared response reaches its stale threshold.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        self::assertSame('initial-body', $pending->get($url)->body());
        self::assertCount(1, app(DeferredCallbackCollection::class));
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame('updated-body', $pending->get($url)->body());
        self::assertCount(1, Cache::store()->getStore()->all());
        Http::assertSentCount(2);
    }

    /**
     * Provide compatible policies without imposing stricter array shapes than Guzzle accepts.
     *
     * @return iterable<string, array{string, array<string, mixed>}> Allowed schemes and option shapes.
     */
    public static function allowedProtocols(): iterable
    {
        // Preserve the default behavior and explicitly permitted HTTP and HTTPS representations.
        yield 'default HTTP' => ['http', []];
        yield 'default HTTPS' => ['https', []];
        yield 'null default' => ['https', ['protocols' => null]];
        yield 'HTTP only' => ['http', ['protocols' => ['http']]];
        yield 'HTTPS only' => ['https', ['protocols' => ['https']]];

        // Treat ordering, duplicate values, and array keys as irrelevant to protocol permission.
        yield 'reordered duplicates' => ['https', ['protocols' => ['https', 'http', 'https']]];
        yield 'named array key' => ['https', ['protocols' => ['secure' => 'https']]];
    }

    /**
     * Confirm malformed protocol options never read or populate cache entries.
     *
     * @param  mixed  $protocols  A policy that cannot authorize cache reuse.
     */
    #[DataProvider('invalidProtocols')]
    public function test_invalid_protocols_leave_validation_to_the_transport(mixed $protocols): void
    {
        // Use a controlled handler to avoid version-dependent Guzzle option deprecations.
        $middleware = new HttpRememberMiddleware(
            new HttpRememberOptions(60, null, 15),
            static fn (): bool => true,
            static function (): void {},
        );
        $sent = 0;
        $handler = $middleware(
            /**
             * Return independent live results without interpreting transport-specific options.
             */
            static function () use (&$sent): PromiseInterface {
                // Make accidental caching observable on the next invocation.
                return Create::promiseFor(new Response(200, [], 'live-'.++$sent));
            },
        );
        $request = new Request('GET', 'https://api.example.test/products');

        // Invalid options bypass the repository entirely, even when a transport accepts them.
        Cache::shouldReceive('store')->never();
        self::assertSame('live-1', (string) $handler($request, ['protocols' => $protocols])->wait()->getBody());
        self::assertSame('live-2', (string) $handler($request, ['protocols' => $protocols])->wait()->getBody());
        self::assertCount(0, app(DeferredCallbackCollection::class));
    }

    /**
     * Provide malformed policies, including invalid values alongside an otherwise allowed scheme.
     *
     * @return iterable<string, array{mixed}> Invalid protocol option values.
     */
    public static function invalidProtocols(): iterable
    {
        // Reject empty lists and incorrect types before checking the requested scheme.
        yield 'empty' => [[]];
        yield 'string' => ['https'];
        yield 'boolean' => [true];

        // Keep mixed or unsupported values from bypassing the transport's validation on cache hits.
        yield 'mixed types' => [['https', 1]];
        yield 'uppercase' => [['https', 'HTTPS']];
        yield 'unsupported scheme' => [['https', 'ftp']];
    }

    /**
     * Confirm redirects cannot reuse or store a destination excluded by the request's protocols.
     *
     * @param  bool  $deferred  Whether the redirect occurs during a stale refresh.
     */
    #[DataProvider('redirectModes')]
    public function test_redirects_apply_protocol_restrictions_to_each_destination(bool $deferred): void
    {
        // Seed an HTTP destination that would otherwise be reusable after an HTTPS redirect.
        Carbon::setTestNow('2026-01-01 12:00:00');
        $source = 'https://api.example.test/products';
        $redirects = Http::sequence();
        if ($deferred) {
            // Retain an earlier source representation so its next request can defer the redirect.
            $redirects->push('source-body');
        }
        $redirects->push('', 302, ['Location' => self::HTTP_URL]);
        Http::fake([
            $source => $redirects,
            self::HTTP_URL => Http::sequence()->push('cached-destination')->push('live-destination'),
        ]);
        Http::remember([10, 60])->get(self::HTTP_URL);
        $pending = Http::withOptions(['protocols' => ['https']])->remember([10, 60]);

        // Exercise the same destination guard in foreground and deferred redirect chains.
        if ($deferred) {
            // A stale source must retain its generation when its destination bypasses caching.
            $pending->get($source);
            $original = Cache::store()->getStore()->all();
            Carbon::setTestNow(Carbon::now()->addSeconds(10));
            self::assertSame('source-body', $pending->get($source)->body());
            app(DeferredCallbackCollection::class)->invoke();
        } else {
            // Preserve the fake transport's result without substituting the cached HTTP destination.
            $original = Cache::store()->getStore()->all();
            self::assertSame('live-destination', $pending->get($source)->body());
        }

        // Leave both existing representations unchanged and never queue a destination refresh.
        self::assertSame($original, Cache::store()->getStore()->all());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount($deferred ? 4 : 3);
    }

    /**
     * Provide the two redirect paths that can encounter independently cached destinations.
     *
     * @return iterable<string, array{bool}> Redirect execution modes.
     */
    public static function redirectModes(): iterable
    {
        // Check live redirects and the separate redirect stack used by deferred refreshes.
        yield 'foreground' => [false];
        yield 'deferred' => [true];
    }

    /**
     * Confirm cache bypass preserves Guzzle's own protocol rejection without performing network I/O.
     */
    public function test_disallowed_protocols_preserve_native_transport_rejections(): void
    {
        // Older supported Guzzle versions do not implement the native protocols option.
        if (! method_exists(Utils::class, 'normalizeProtocols')) {
            $this->markTestSkipped('This Guzzle version does not provide native protocol validation.');
        }

        // Populate an ordinary HTTP response before switching to the restricted transport.
        Http::fake([self::HTTP_URL => Http::response('cached-body')]);
        Http::remember(60)->get(self::HTTP_URL);

        // Invoke only cURL configuration validation, never curl_exec or a network handler.
        $handler =
            /**
             * Surface the native scheme error before a cURL transfer could begin.
             */
            static function (RequestInterface $request, array $options): PromiseInterface {
                // Require Guzzle to reject the disallowed scheme during handle preparation.
                (new CurlFactory(0))->create($request, $options);

                // Fail locally if native validation unexpectedly permits the forbidden scheme.
                throw new RuntimeException('The native transport accepted a disallowed protocol.');
            };
        $pending = (new Factory)->setHandler($handler)->withOptions(['protocols' => ['https']])->remember(60);

        // Preserve Laravel's usual exception mapping instead of returning the cached HTTP body.
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('The scheme "http" is not allowed by the protocols request option.');
        $pending->get(self::HTTP_URL);
    }
}
