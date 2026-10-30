<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;
use PhilipAnnis\HttpRemember\HttpRememberResponse;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Verify fixed refresh cooldowns without sleeping or extending cached response lifetimes.
 */
final class RefreshCooldownTest extends TestCase
{
    /**
     * The resource shared by stale requests and deferred refreshes.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The instant from which every response age and cooldown is measured.
     */
    private const STARTED_AT = '2026-01-01 12:00:00';

    /**
     * Keep response expiry separate from the shorter cooldown boundaries.
     */
    protected function setUp(): void
    {
        // Boot the isolated cache and freeze time before storing a response.
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::STARTED_AT));
    }

    /**
     * Confirm repeated failures use the same fixed delay on synchronous and asynchronous hits.
     *
     * @param  bool  $async  Whether stale callers receive promises.
     */
    #[DataProvider('requestModes')]
    public function test_failed_refreshes_apply_a_fixed_cooldown(bool $async): void
    {
        // Fail twice before successfully replacing the original response.
        Http::fakeSequence()->push('initial')->push('unavailable', 503)->push('unavailable', 503)->push('updated');
        Http::remember([10, 180])->get(self::API_URL);
        $key = array_key_first(Cache::store()->getStore()->all());
        $original = Cache::get($key);
        $skipped = [];
        app('events')->listen(HttpRememberEventLog::class,
            /**
             * Retain cooldown decisions without changing the normal HTTP events.
             */
            static function (HttpRememberEventLog $event) use (&$skipped): void {
                if ($event->reason === 'cooldown') {
                    $skipped[] = $event;
                }
            },
        );

        // The first failed attempt starts the default fifteen-second cooldown.
        $this->at(10);
        Http::remember([10, 180])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();
        $this->at(24.999);
        $response = Http::async($async)->remember([10, 180])->get(self::API_URL);
        self::assertSame('initial', ($async ? $response->wait() : $response)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        self::assertSame($original, Cache::get($key));
        self::assertSame('refresh_skipped', $skipped[0]->event);
        self::assertSame((float) Carbon::parse(self::STARTED_AT)->addSeconds(25)->timestamp, $skipped[0]->retryAt);
        self::assertSame($skipped[0]->retryAt, $skipped[0]->toArray()['retry_at']);
        Http::assertSentCount(2);

        // A second failure waits another fifteen seconds rather than increasing the delay.
        $this->at(25);
        Http::remember([10, 180])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();
        $this->at(39.999);
        Http::remember([10, 180])->get(self::API_URL);
        self::assertCount(0, app(DeferredCallbackCollection::class));
        $this->at(40);
        Http::remember([10, 180])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame('updated', Http::remember([10, 180])->get(self::API_URL)->body());
        Http::assertSentCount(4);
    }

    /**
     * Provide both public HTTP execution modes for stale cache hits.
     *
     * @return iterable<string, array{bool}> Modes keyed by promise behavior.
     */
    public static function requestModes(): iterable
    {
        yield 'synchronous' => [false];
        yield 'asynchronous' => [true];
    }

    /**
     * Confirm valid retry-after values can lengthen but never shorten the configured cooldown.
     *
     * @param  string|null  $header  The upstream retry-after value, if present.
     * @param  int  $status  The unsuccessful refresh status.
     * @param  int  $delay  Seconds to wait after receiving that response.
     */
    #[DataProvider('retryAfterValues')]
    public function test_retry_after_controls_the_next_attempt(?string $header, int $status, int $delay): void
    {
        // Give the server's requested delay room to elapse before hard expiry.
        Http::fakeSequence()->push('initial')->push('unavailable', $status, $header === null ? [] : ['Retry-After' => $header])->push('updated');
        Http::remember([10, 300])->get(self::API_URL);
        $this->at(10);
        Http::remember([10, 300])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Hits immediately before the deadline must not schedule or perform another attempt.
        $this->at(10 + $delay - 0.001);
        self::assertSame('initial', Http::remember([10, 300])->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);

        // Reaching the exact deadline permits the next deferred refresh.
        $this->at(10 + $delay);
        Http::remember([10, 300])->get(self::API_URL);
        self::assertCount(1, app(DeferredCallbackCollection::class));
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame('updated', Http::remember([10, 300])->get(self::API_URL)->body());
        Http::assertSentCount(3);
    }

    /**
     * Provide delay seconds, HTTP dates, and values that must fall back to fifteen seconds.
     *
     * @return iterable<string, array{string|null, int, int}> Headers keyed by interpretation.
     */
    public static function retryAfterValues(): iterable
    {
        yield 'seconds on rate limit' => ['120', 429, 120];
        yield 'seconds on service failure' => ['60', 503, 60];
        yield 'HTTP date' => ['Thu, 01 Jan 2026 12:01:30 GMT', 503, 80];
        yield 'obsolete RFC 850 date' => ['Thursday, 01-Jan-26 12:01:30 GMT', 503, 80];
        yield 'obsolete asctime date' => ['Thu Jan  1 12:01:30 2026', 503, 80];
        yield 'shorter delay' => ['5', 429, 15];
        yield 'zero delay' => ['0', 503, 15];
        yield 'past date' => ['Thu, 01 Jan 2026 11:59:00 GMT', 503, 15];
        yield 'missing header' => [null, 500, 15];
        yield 'empty header' => ['', 503, 15];
        yield 'negative seconds' => ['-1', 503, 15];
        yield 'fractional seconds' => ['1.5', 503, 15];
        yield 'relative date' => ['tomorrow', 503, 15];
        yield 'malformed date' => ['Thu, 99 Jan 2026 12:01:30 GMT', 503, 15];
    }

    /**
     * Confirm transport exceptions start cooldowns while stale callers keep their response.
     */
    public function test_connection_failures_start_a_cooldown(): void
    {
        // Reject the refresh promise before a later attempt can succeed.
        Http::fakeSequence()->push('initial')->pushFailedConnection('Test connection failure.')->push('updated');
        Http::remember([10, 180])->get(self::API_URL);
        $this->at(10);
        Http::remember([10, 180])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Preserve stale data without another callback until the failure's cooldown ends.
        self::assertSame('initial', Http::remember([10, 180])->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        $this->at(25);
        Http::remember([10, 180])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame('updated', Http::remember([10, 180])->get(self::API_URL)->body());
        Http::assertSentCount(2);
    }

    /**
     * Confirm cooldown settings are captured with the rest of a builder's policy.
     */
    public function test_custom_cooldowns_are_captured_when_remember_is_called(): void
    {
        // Retain a five-second policy even after the application changes its default.
        config(['http-remember.refresh_cooldown_seconds' => 5]);
        Http::fakeSequence()->push('initial')->push('unavailable', 503)->push('updated');
        $pending = Http::remember([10, 180]);
        config(['http-remember.refresh_cooldown_seconds' => 60]);
        $pending->get(self::API_URL);
        $this->at(10);
        $pending->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // The sending builder retries at its own configured boundary.
        $this->at(15);
        $pending->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame('updated', $pending->get(self::API_URL)->body());
        Http::assertSentCount(3);
    }

    /**
     * Confirm long or overflowing retry-after values never extend expiry or suppress foreground calls.
     */
    public function test_cooldowns_never_extend_the_response_lifetime(): void
    {
        // Retain the original cache entry while a huge server delay is clipped to hard expiry.
        Http::fakeSequence()->push('initial')->push('unavailable', 503, ['Retry-After' => str_repeat('9', 400)])->push('foreground failure', 503)->push('updated');
        Http::remember([10, 60])->get(self::API_URL);
        $key = array_key_first(Cache::store()->getStore()->all());
        $original = Cache::store()->getStore()->all()[$key];
        $this->at(10);
        Http::remember([10, 60])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();
        $this->at(59);
        self::assertSame('initial', Http::remember([10, 60])->get(self::API_URL)->body());
        self::assertSame($original, Cache::store()->getStore()->all()[$key]);
        self::assertCount(0, app(DeferredCallbackCollection::class));

        // Expired data must never hide a foreground error or stop an immediate later foreground attempt.
        $this->at(60);
        self::assertSame(503, Http::remember([10, 60])->get(self::API_URL)->status());
        self::assertSame('updated', Http::remember([10, 60])->get(self::API_URL)->body());
        Http::assertSentCount(4);
    }

    /**
     * Confirm one worker's newly recorded failure cancels another worker's queued refresh.
     */
    public function test_queued_refreshes_recheck_cooldown_under_the_lock(): void
    {
        // Queue two lifecycle callbacks before either worker has attempted its refresh.
        Http::fakeSequence()->push('initial')->push('unavailable', 503);
        Http::remember([10, 180])->get(self::API_URL);
        $this->at(10);
        Http::remember([10, 180])->get(self::API_URL);
        $firstWorker = app(DeferredCallbackCollection::class);
        $secondWorker = new DeferredCallbackCollection;
        app()->instance(DeferredCallbackCollection::class, $secondWorker);

        // Complete one failed attempt before allowing the earlier queued worker to acquire its lock.
        try {
            Http::remember([10, 180])->get(self::API_URL);
            self::assertCount(1, $firstWorker);
            self::assertCount(1, $secondWorker);
            $secondWorker->invoke();
            $firstWorker->invoke();
            self::assertSame([], Cache::store()->getStore()->locks);
            Http::assertSentCount(2);
        } finally {
            app()->instance(DeferredCallbackCollection::class, $firstWorker);
        }
    }

    /**
     * Confirm group invalidation and direct replacement do not inherit an old generation's cooldown.
     *
     * @param  bool  $invalidate  Whether to rotate the group instead of directly replacing its response.
     */
    #[DataProvider('replacementModes')]
    public function test_replacement_generations_start_without_old_cooldowns(bool $invalidate): void
    {
        // Give the original response a cooldown longer than the replacement's fresh period.
        $group = ['products'];
        Http::fakeSequence()->push('initial')->push('unavailable', 503, ['Retry-After' => '120'])->push('replacement')->push('updated');
        Http::remember([10, 180], group: $group)->get(self::API_URL);
        $key = array_key_last(Cache::store()->getStore()->all());
        $this->at(10);
        Http::remember([10, 180], group: $group)->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Replace the entry through either supported invalidation or another successful writer.
        if ($invalidate) {
            Http::remember(group: $group, invalidate: true);
            self::assertSame('replacement', Http::remember([10, 180], group: $group)->get(self::API_URL)->body());
        } else {
            $replacement = HttpRememberResponse::capture(new Response(200, [], 'replacement'));
            Cache::put($key, $replacement->toArray(), 205);
        }

        // The replacement's first stale hit refreshes despite the old generation's longer delay.
        $this->at(20);
        Http::remember([10, 180], group: $group)->get(self::API_URL);
        self::assertCount(1, app(DeferredCallbackCollection::class));
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame($invalidate ? 'updated' : 'replacement', Http::remember([10, 180], group: $group)->get(self::API_URL)->body());
        Http::assertSentCount($invalidate ? 4 : 3);
    }

    /**
     * Provide generation changes independent of cooldown metadata cleanup.
     *
     * @return iterable<string, array{bool}> Replacement modes keyed by source.
     */
    public static function replacementModes(): iterable
    {
        yield 'group invalidation' => [true];
        yield 'response replacement' => [false];
    }

    /**
     * Confirm stores without atomic locks can still retain refresh cooldowns.
     */
    public function test_stores_without_locks_share_cooldowns(): void
    {
        // Delegate persistence to memory while exposing only the ordinary cache store contract.
        $memory = Cache::store()->getStore();
        $store = Mockery::mock(Store::class);
        $store->shouldReceive('get')->andReturnUsing($memory->get(...));
        $store->shouldReceive('put')->andReturnUsing($memory->put(...));
        Cache::shouldReceive('store')->with(null)->andReturn(new Repository($store));
        Http::fakeSequence()->push('initial')->push('unavailable', 503);
        Http::remember([10, 180])->get(self::API_URL);
        $this->at(10);
        Http::remember([10, 180])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // A separate builder observes the shared deadline without requiring a lock provider.
        self::assertSame('initial', Http::remember([10, 180])->get(self::API_URL)->body());
        self::assertCount(0, app(DeferredCallbackCollection::class));
        Http::assertSentCount(2);
    }

    /**
     * Confirm cooldown persistence failures cannot break stale hits or hide the upstream failure.
     */
    public function test_cooldown_write_failures_preserve_stale_responses(): void
    {
        // Reject only cooldown writes while letting ordinary response caching work normally.
        $memory = Cache::store()->getStore();
        $store = Mockery::mock(Store::class);
        $store->shouldReceive('get')->andReturnUsing($memory->get(...));
        $store->shouldReceive('put')->andReturnUsing(
            /**
             * Simulate a cache outage for the companion entry without changing the response payload.
             */
            static function (string $key, mixed $value, int $seconds) use ($memory): bool {
                if (str_contains($key, ':refresh-cooldown:')) {
                    throw new RuntimeException('Test cooldown write failure.');
                }

                return $memory->put($key, $value, $seconds);
            },
        );
        Cache::shouldReceive('store')->with(null)->andReturn(new Repository($store));
        $failures = [];
        app('events')->listen(HttpRememberEventLog::class,
            /**
             * Keep metadata failures distinguishable from the original unsuccessful HTTP response.
             */
            static function (HttpRememberEventLog $event) use (&$failures): void {
                if ($event->event === 'failed' || $event->event === 'refresh_failed') {
                    $failures[] = $event;
                }
            },
        );
        Http::fakeSequence()->push('initial')->push('unavailable', 503);
        Http::remember([10, 180])->get(self::API_URL);
        $this->at(10);
        $stale = Http::remember([10, 180])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Preserve the returned body and report both failures using their own safe metadata.
        self::assertSame('initial', $stale->body());
        self::assertSame('cooldown', $failures[0]->operation);
        self::assertSame(RuntimeException::class, $failures[0]->exception);
        self::assertSame('refresh', $failures[1]->operation);
        self::assertSame(503, $failures[1]->status);
        Http::assertSentCount(2);
    }

    /**
     * Move the frozen clock to an exact age without waiting in real time.
     *
     * @param  int|float  $seconds  Seconds elapsed since the original response was stored.
     */
    private function at(int|float $seconds): void
    {
        Carbon::setTestNow(Carbon::parse(self::STARTED_AT)->addMicroseconds((int) round($seconds * 1000000)));
    }
}
