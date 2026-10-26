<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use ArrayObject;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;
use PhilipAnnis\HttpRemember\HttpRememberServiceProvider;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Verify safe event logs across cache decisions and deferred work.
 */
final class EventLogsTest extends TestCase
{
    /**
     * The shared fake endpoint used by identical-request tests.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * Confirm cache hit event logs report freshness and fractional ages in both request modes.
     *
     * @param  bool  $async  Whether the request returns a promise.
     */
    #[DataProvider('requestModes')]
    public function test_event_logs_identify_fresh_and_stale_cache_hits(bool $async): void
    {
        // Freeze the clock and preserve upstream headers while moving between cache states.
        $eventLogs = $this->eventLogs();
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()->push('initial-body', 200, ['X-Upstream' => 'original'])->push('refreshed-body');
        $pending = Http::async($async)->remember([10, 60]);
        $live = $pending->get(self::API_URL);
        if ($async) {
            self::assertInstanceOf(PromiseInterface::class, $live);
            $live = $live->wait();
        }

        // Record the live miss and storage outcome without changing the normal response.
        self::assertSame(['miss', 'stored'], $this->eventNames($eventLogs));
        self::assertSame('missing', $eventLogs[0]->reason);
        self::assertSame('array', $eventLogs[0]->store);
        self::assertMatchesRegularExpression('/^http-remember:v2:[a-f0-9]{64}$/', $eventLogs[0]->cacheKey);
        self::assertSame('initial-body', $live->body());

        // Include fractional seconds in the fresh hit's event log.
        Carbon::setTestNow($startedAt->copy()->addSeconds(1)->addMicroseconds(250000));
        $fresh = $pending->get(self::API_URL);
        $fresh = $async ? $fresh->wait() : $fresh;
        self::assertSame('hit', $eventLogs[2]->event);
        self::assertSame('fresh', $eventLogs[2]->reason);
        self::assertSame(1.25, $eventLogs[2]->age);
        self::assertSame($eventLogs[0]->cacheKey, $eventLogs[2]->cacheKey);
        self::assertSame(['X-Upstream' => ['original'], 'Age' => ['1']], $fresh->headers());
        self::assertSame('initial-body', $fresh->body());

        // Record the stale hit before deferred execution replaces its cache entry.
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        $stale = $pending->get(self::API_URL);
        $stale = $async ? $stale->wait() : $stale;
        self::assertSame('stale', $eventLogs[3]->reason);
        self::assertSame(10.0, $eventLogs[3]->age);
        Http::assertSentCount(1);
        app(DeferredCallbackCollection::class)->invoke();
        self::assertSame(['miss', 'stored', 'hit', 'hit', 'refresh_scheduled', 'refresh_started', 'stored', 'refresh_succeeded'], $this->eventNames($eventLogs));
        self::assertSame('initial-body', $stale->body());
        Http::assertSentCount(2);
    }

    /**
     * Provide both public HTTP request execution modes.
     *
     * @return iterable<string, array{bool}> Request modes keyed by promise behavior.
     */
    public static function requestModes(): iterable
    {
        // Exercise immediate responses and the normal asynchronous promise path.
        yield 'synchronous' => [false];
        yield 'asynchronous' => [true];
    }

    /**
     * Confirm event logs distinguish cache decisions from actual deferred refresh work.
     */
    public function test_event_logs_report_cache_and_refresh_outcomes_in_order(): void
    {
        // Collect event logs while two stale hits request the same deferred refresh.
        $eventLogs = $this->eventLogs();
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()->push('initial-body')->push('refreshed-body');
        Http::remember([10, 60])->get(self::API_URL);
        Http::remember([10, 60])->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        Http::remember([10, 60])->get(self::API_URL);
        Http::remember([10, 60])->get(self::API_URL);

        // Scheduling requests remain observable while execution is deduplicated by Laravel.
        self::assertSame(['miss', 'stored', 'hit', 'hit', 'refresh_scheduled', 'hit', 'refresh_scheduled'], $this->eventNames($eventLogs));
        self::assertSame('fresh', $eventLogs[2]->reason);
        self::assertSame('stale', $eventLogs[3]->reason);
        self::assertSame(10.0, $eventLogs[3]->age);
        app(DeferredCallbackCollection::class)->invoke();

        // Publish success only after the replacement response has actually been stored.
        self::assertSame(['refresh_started', 'stored', 'refresh_succeeded'], array_slice($this->eventNames($eventLogs), -3));
        self::assertSame(200, $eventLogs[9]->status);
        self::assertNull($eventLogs[9]->reason);
        self::assertSame($eventLogs[0]->cacheKey, $eventLogs[9]->cacheKey);
        Http::assertSentCount(2);
    }

    /**
     * Confirm fixed expiry is reported as a new live cache miss.
     */
    public function test_expired_entries_report_a_cache_miss_reason(): void
    {
        // Reach the exact lifetime boundary after the first fixed-expiry response.
        $eventLogs = $this->eventLogs();
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()->push('initial-body')->push('updated-body');
        Http::remember([10, 60])->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));
        $expired = Http::remember([10, 60])->get(self::API_URL);

        // Identify logical expiry even while bounded backend retention keeps the old generation.
        self::assertSame('miss', $eventLogs[2]->event);
        self::assertSame('expired', $eventLogs[2]->reason);
        self::assertSame('updated-body', $expired->body());
    }

    /**
     * Confirm request bypasses retain their reasons on event logs.
     *
     * @param  array<string, mixed>  $options  The transfer options that bypass caching.
     * @param  string  $reason  The expected safe bypass reason.
     */
    #[DataProvider('bypassOptions')]
    public function test_event_logs_identify_request_bypass_reasons(array $options, string $reason): void
    {
        // Send an explicitly remembered request that requires ordinary live transport behavior.
        $eventLogs = $this->eventLogs();
        Http::fake([self::API_URL => Http::response('live-body')]);
        $response = Http::withOptions($options)->remember(60)->get(self::API_URL);

        // Avoid generating a cache fingerprint or storing the bypassed response.
        self::assertSame(['bypass'], $this->eventNames($eventLogs));
        self::assertSame($reason, $eventLogs[0]->reason);
        self::assertNull($eventLogs[0]->cacheKey);
        self::assertSame('live-body', $response->body());
        self::assertSame([], Cache::store()->getStore()->all());
    }

    /**
     * Provide transport options with distinct bypass reasons.
     *
     * @return iterable<string, array{array<string, mixed>, string}> Bypasses keyed by transfer feature.
     */
    public static function bypassOptions(): iterable
    {
        // Cover side effects and callbacks without making actual network transfers.
        yield 'stream' => [['stream' => true], 'stream'];
        yield 'sink' => [['sink' => '/unused-download-path'], 'sink'];
        yield 'curl' => [['curl' => [CURLOPT_FOLLOWLOCATION => false]], 'curl'];
        yield 'stream context' => [['stream_context' => ['http' => ['method' => 'POST']]], 'stream_context'];
        yield 'header callback' => [['on_headers' => static function (): void {}], 'on_headers'];
        yield 'trailer callback' => [['on_trailers' => static function (): void {}], 'on_trailers'];
    }

    /**
     * Confirm response eligibility outcomes never appear as successful cache writes.
     *
     * @param  int  $status  The live upstream response status.
     * @param  array<string, string>  $headers  The live upstream response headers.
     * @param  bool  $eligible  Whether the application predicate accepts the response.
     * @param  string  $reason  The expected reason persistence was skipped.
     */
    #[DataProvider('responseRejections')]
    public function test_event_logs_report_responses_that_were_not_stored(int $status, array $headers, bool $eligible, string $reason): void
    {
        // Narrow eligibility or the body budget while preserving every live response.
        $eventLogs = $this->eventLogs();
        config(['http-remember.max_response_bytes' => $reason === 'response_size' ? 1 : 100]);
        Http::fake([self::API_URL => Http::response('live-body', $status, $headers)]);
        $response = Http::remember(60, cacheWhen:
            /**
             * Accept or reject the independent cache candidate for this scenario.
             */
            static fn (Response $response): bool => $eligible,
        )->get(self::API_URL);

        // Preserve the live response while making the storage rejection explicit in event logs.
        self::assertSame(['miss', 'not_stored'], $this->eventNames($eventLogs));
        self::assertSame($reason, $eventLogs[1]->reason);
        self::assertSame($status, $eventLogs[1]->status);
        self::assertSame('live-body', $response->body());
        self::assertSame([], Cache::store()->getStore()->all());
    }

    /**
     * Provide independent built-in and application response rejections.
     *
     * @return iterable<string, array{int, array<string, string>, bool, string}> Rejections keyed by rule.
     */
    public static function responseRejections(): iterable
    {
        // Distinguish upstream failures, size budgets, predicates, and unsupported variants.
        yield 'status' => [503, [], true, 'response_status'];
        yield 'size' => [200, [], true, 'response_size'];
        yield 'predicate' => [200, [], false, 'response_predicate'];
        yield 'vary' => [200, ['Vary' => 'X-Request-ID'], true, 'vary'];
    }

    /**
     * Confirm a refresh failure remains observable without changing stale data or expiry.
     */
    public function test_failed_refreshes_preserve_the_stale_response(): void
    {
        // Store a valid response before the deferred API call fails.
        $eventLogs = $this->eventLogs();
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()->push('initial-body')->push('unavailable', 503);
        Http::remember([10, 60])->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        $stale = Http::remember([10, 60])->get(self::API_URL);
        app(DeferredCallbackCollection::class)->invoke();

        // Report the failure once and keep the caller's stale response unchanged.
        self::assertSame(['refresh_started', 'not_stored', 'refresh_failed'], array_slice($this->eventNames($eventLogs), -3));
        self::assertSame(503, $eventLogs[6]->status);
        self::assertSame('refresh', $eventLogs[6]->operation);
        self::assertSame('initial-body', $stale->body());
        self::assertSame('stale', $eventLogs[2]->reason);
        self::assertSame(10.0, $eventLogs[2]->age);
    }

    /**
     * Confirm cancelled refreshes report why no upstream request was made.
     *
     * @param  bool  $invalidate  Whether a group mutation cancels the refresh instead of expiry.
     */
    #[DataProvider('refreshCancellations')]
    public function test_event_logs_report_refresh_cancellation(bool $invalidate): void
    {
        // Schedule a grouped refresh before changing its generation or reaching hard expiry.
        $eventLogs = $this->eventLogs();
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fake([self::API_URL => Http::response('initial-body')]);
        Http::remember([10, 60], group: ['products'])->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        Http::remember([10, 60], group: ['products'])->get(self::API_URL);
        if ($invalidate) {
            Http::remember(group: ['products'])->put(self::API_URL);
            self::assertSame('mutation', $eventLogs[4]->reason);
            self::assertSame('invalidated', $eventLogs[5]->event);
        } else {
            Carbon::setTestNow($startedAt->copy()->addSeconds(60));
        }
        app(DeferredCallbackCollection::class)->invoke();

        // A cancelled callback does not claim to have started or completed a refresh.
        $last = $eventLogs[count($eventLogs) - 1];
        self::assertSame('refresh_skipped', $last->event);
        self::assertSame($invalidate ? 'group_invalidated' : 'expired', $last->reason);
        self::assertNotContains('refresh_started', $this->eventNames($eventLogs));
        Http::assertSentCount($invalidate ? 2 : 1);
    }

    /**
     * Provide cancellation paths independent of upstream response failures.
     *
     * @return iterable<string, array{bool}> Cancellation modes keyed by cause.
     */
    public static function refreshCancellations(): iterable
    {
        // Cover generation rotation and the exact logical lifetime boundary.
        yield 'invalidation' => [true];
        yield 'expiry' => [false];
    }

    /**
     * Confirm a held refresh lock produces a skipped event log rather than a success.
     */
    public function test_event_logs_report_refresh_lock_contention(): void
    {
        // Hold the refresh lock after a stale hit schedules deferred work.
        $eventLogs = $this->eventLogs();
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fake([self::API_URL => Http::response('initial-body')]);
        Http::remember([10, 60])->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        Http::remember([10, 60])->get(self::API_URL);
        $lock = Cache::store()->getStore()->lock($eventLogs[0]->cacheKey.':refresh', 25);
        self::assertTrue($lock->get());

        // Preserve stale data without a second network transfer while another worker owns the lock.
        try {
            app(DeferredCallbackCollection::class)->invoke();
            self::assertSame('refresh_skipped', $eventLogs[4]->event);
            self::assertSame('refresh_lock_unavailable', $eventLogs[4]->reason);
            Http::assertSentCount(1);
        } finally {
            $lock->release();
        }
    }

    /**
     * Confirm cache read failures retain safe event logs and usable live responses.
     */
    public function test_event_logs_report_cache_failures_without_exception_messages(): void
    {
        // Fail cache lookup with sensitive-looking details that must stay out of event logs.
        $eventLogs = $this->eventLogs();
        $warnings = [];
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->once()->with('http-remember')->andReturn($logger);
        $logger->shouldReceive('warning')->once()->withArgs(
            /**
             * Retain the foreground failure's request message and complete event context.
             */
            static function (string $message, array $context) use (&$warnings): bool {
                $warnings[] = [$message, $context];

                return true;
            },
        );
        Cache::shouldReceive('store')->once()->with(null)->andThrow(new RuntimeException('secret-token sensitive-body'));
        Http::fake([self::API_URL => Http::response('live-body')]);
        $response = Http::remember(60)->get(self::API_URL);

        // Keep structured failures separate from the live fallback decision.
        self::assertSame(['failed', 'bypass'], $this->eventNames($eventLogs));
        self::assertSame('read', $eventLogs[0]->operation);
        self::assertSame(RuntimeException::class, $eventLogs[0]->exception);
        self::assertStringNotContainsString('secret-token', json_encode($eventLogs[0]->toArray()));
        self::assertSame('cache_read_failed', $eventLogs[1]->reason);
        self::assertSame('live-body', $response->body());

        // Keep cache failure warnings in the same request format as routine event logs.
        self::assertCount(1, $warnings);
        self::assertSame('[GET] api.example.test/products', $warnings[0][0]);
        self::assertSame($eventLogs[0]->toArray(), $warnings[0][1]);
    }

    /**
     * Confirm transport failures preserve their normal synchronous or asynchronous exception.
     *
     * @param  bool  $async  Whether the rejected request returns a promise.
     */
    #[DataProvider('requestModes')]
    public function test_event_logs_preserve_transport_failures(bool $async): void
    {
        // Fail the fake connection after the request has been identified as a cache miss.
        $eventLogs = $this->eventLogs();
        Http::fakeSequence()->pushFailedConnection('private-connection-details');
        $failure = null;
        try {
            $response = Http::async($async)->remember(60)->get(self::API_URL);
            if ($async) {
                $failure = $response->wait();
            }
        } catch (ConnectionException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(ConnectionException::class, $failure);
        self::assertSame('private-connection-details', $failure->getMessage());

        // Record the safe failure class without persisting a response or exposing exception text.
        self::assertSame(['miss', 'failed'], $this->eventNames($eventLogs));
        self::assertSame('request', $eventLogs[1]->operation);
        self::assertNotNull($eventLogs[1]->exception);
        self::assertStringNotContainsString('private-connection-details', json_encode($eventLogs[1]->toArray()));
        self::assertSame([], Cache::store()->getStore()->all());
    }

    /**
     * Confirm deferred event logs retain the store selected by their original request.
     */
    public function test_event_logs_keep_the_selected_store_when_defaults_change(): void
    {
        // Schedule a refresh against the original default before selecting a second cache store.
        $eventLogs = $this->eventLogs();
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        config(['cache.stores.secondary' => ['driver' => 'array']]);
        Http::fakeSequence()->push('initial-body')->push('refreshed-body');
        Http::remember([10, 60])->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        Http::remember([10, 60])->get(self::API_URL);
        Cache::setDefaultDriver('secondary');
        app(DeferredCallbackCollection::class)->invoke();

        // A mutable application default cannot relabel the store used by deferred work.
        foreach ($eventLogs as $eventLog) {
            self::assertSame('array', $eventLog->store);
        }
        self::assertSame([], Cache::store()->getStore()->all());
    }

    /**
     * Confirm event logs expose hashes rather than credentials, request details, or group values.
     */
    public function test_event_logs_keep_request_and_group_details_private(): void
    {
        // Include sensitive-looking values in every request component that may affect identity.
        $eventLogs = $this->eventLogs();
        $url = self::API_URL.'?token=private-query';
        Http::fake([$url => Http::response('private-response', 200, ['X-Private' => 'private-header'])]);
        Http::withToken('private-token')->remember(60, group: ['private-tenant'], operation: 'read')->post($url, ['value' => 'private-payload']);

        // Allow useful correlation without logging plaintext request or response contents.
        foreach ($eventLogs as $eventLog) {
            self::assertMatchesRegularExpression('/^http-remember:v2:[a-f0-9]{64}$/', $eventLog->cacheKey);
            self::assertSame(hash('sha256', serialize(['private-tenant'])), $eventLog->groupHash);
            self::assertStringNotContainsString('private-', json_encode($eventLog->toArray()));
            self::assertStringNotContainsString(self::API_URL, json_encode($eventLog->toArray()));
        }
    }

    /**
     * Confirm structured event logs can be dispatched without writing to log files.
     */
    public function test_event_logs_can_disable_log_output_without_disabling_listeners(): void
    {
        // Keep listeners available while rejecting any routine log-channel lookup.
        $eventLogs = $this->eventLogs();
        config(['http-remember.event_logs.enabled' => false]);
        Log::shouldReceive('channel')->never();
        Http::fake([self::API_URL => Http::response('live-body')]);
        Http::remember(60)->get(self::API_URL);

        self::assertSame(['miss', 'stored'], $this->eventNames($eventLogs));
    }

    /**
     * Confirm package defaults select a dedicated rotating log file.
     */
    public function test_event_logs_default_to_the_dedicated_package_channel(): void
    {
        // Inspect production defaults independently of the test environment's explicit opt-out.
        $defaults = require __DIR__.'/../../config/http-remember.php';
        self::assertFalse($defaults['event_logs']['enabled']);
        self::assertSame('http-remember', $defaults['event_logs']['channel']);
        self::assertNull($defaults['event_logs']['redact']);
        self::assertSame('daily', config('logging.channels.http-remember.driver'));
        self::assertSame(storage_path('logs/http-remember.log'), config('logging.channels.http-remember.path'));
        self::assertSame(7, config('logging.channels.http-remember.days'));

        // Explicitly enabling event logs writes routine decisions to the package channel.
        $defaults['event_logs']['enabled'] = true;
        config(['http-remember.event_logs' => $defaults['event_logs']]);
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('debug')->twice();
        Log::shouldReceive('channel')->twice()->with('http-remember')->andReturn($logger);
        Http::fake([self::API_URL => Http::response('live-body')]);
        self::assertSame('live-body', Http::remember(60)->get(self::API_URL)->body());
    }

    /**
     * Confirm development environments enable event logs while other environments opt in.
     *
     * @param  string  $environment  The configured application environment.
     * @param  bool  $enabled  Whether event logs should be enabled by default.
     */
    #[DataProvider('eventLogEnvironments')]
    public function test_event_log_defaults_follow_the_application_environment(string $environment, bool $enabled): void
    {
        // Retain the test runner's environment while reading the package's cacheable defaults.
        $originalEnv = $_ENV['APP_ENV'] ?? null;
        $originalServer = $_SERVER['APP_ENV'] ?? null;
        try {
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $environment;
            $defaults = require __DIR__.'/../../config/http-remember.php';
            self::assertSame($enabled, $defaults['event_logs']['enabled']);
            self::assertSame('http-remember', $defaults['event_logs']['channel']);
        } finally {
            // Restore both environment adapters before another test boots its application.
            if ($originalEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $originalEnv;
            }
            if ($originalServer === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $originalServer;
            }
        }
    }

    /**
     * Provide development and deployment environment defaults.
     *
     * @return iterable<string, array{string, bool}> Defaults keyed by application environment.
     */
    public static function eventLogEnvironments(): iterable
    {
        // Recognize Laravel's usual local environment and common development aliases.
        yield 'local' => ['local', true];
        yield 'development' => ['development', true];
        yield 'dev' => ['dev', true];
        yield 'production' => ['production', false];
        yield 'staging' => ['staging', false];
        yield 'testing' => ['testing', false];
    }

    /**
     * Confirm applications can supply their own package log channel definition.
     */
    public function test_package_channel_registration_preserves_application_configuration(): void
    {
        // Register the provider again after the application supplies a custom channel definition.
        $channel = ['driver' => 'single', 'path' => '/custom/http-remember.log', 'level' => 'info'];
        config(['logging.channels.http-remember' => $channel]);
        (new HttpRememberServiceProvider($this->app))->register();

        self::assertSame($channel, config('logging.channels.http-remember'));
    }

    /**
     * Confirm the dedicated channel writes event logs through Laravel's actual daily logger.
     */
    public function test_package_channel_writes_event_logs_to_its_own_log_file(): void
    {
        // Keep the real logging integration isolated from application and package log files.
        $directory = sys_get_temp_dir().'/http-remember-event-logs-'.bin2hex(random_bytes(8));
        mkdir($directory);
        config([
            'http-remember.event_logs.enabled' => true,
            'logging.channels.http-remember.path' => $directory.'/http-remember.log',
        ]);
        Http::fake([self::API_URL => Http::response('private-response-body')]);
        $eventLogs = $this->eventLogs();

        // Exercise the registered channel instead of a mocked logger or dispatch listener.
        try {
            Http::remember(60)->get(self::API_URL);
            Http::remember(60)->get(self::API_URL);
            $files = glob($directory.'/http-remember-*.log');
            self::assertCount(1, $files);
            $contents = file_get_contents($files[0]);
            self::assertStringNotContainsString('HTTP remember event log.', $contents);

            // Use Laravel's standard request message and separate structured event context.
            $lines = file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertCount(3, $lines);
            foreach ($lines as $index => $line) {
                self::assertSame(1, preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] testing\.DEBUG: (\[[A-Z]+\] \S+) (\{.*\})\s*$/', $line, $matches));
                self::assertSame('[GET] api.example.test/products', $matches[1]);
                self::assertSame($eventLogs[$index]->toArray(), json_decode($matches[2], true, flags: JSON_THROW_ON_ERROR));
            }

            // Keep private upstream data out of both the message and structured context.
            self::assertStringNotContainsString('private-response-body', $contents);
            self::assertStringNotContainsString(self::API_URL, $contents);
        } finally {
            // Close the temporary channel and remove only files created by this test.
            Log::channel('http-remember')->getLogger()->close();
            Log::forgetChannel('http-remember');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /**
     * Confirm enabled event logs use the configured channel and structured context.
     */
    public function test_event_logs_can_be_written_to_a_selected_log_channel(): void
    {
        // Enable event logs without changing cache identity or request options.
        config(['http-remember.event_logs' => ['enabled' => true, 'channel' => 'event-logs']]);
        $eventLogs = $this->eventLogs();
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->twice()->with('event-logs')->andReturn($logger);
        $contexts = [];
        $logger->shouldReceive('debug')->twice()->withArgs(
            /**
             * Retain the emitted context without requiring a real log file.
             */
            static function (string $message, array $context) use (&$contexts): bool {
                $contexts[] = $context;

                return $message === '[GET] api.example.test/products';
            },
        );
        Http::fake([self::API_URL => Http::response('live-body')]);
        Http::remember(60)->get(self::API_URL);

        // Keep listener and log-channel payloads identical for the same observed decisions.
        self::assertSame($eventLogs[0]->toArray(), $contexts[0]);
        self::assertSame($eventLogs[1]->toArray(), $contexts[1]);
    }

    /**
     * Confirm log messages identify request methods and paths without exposing queries or credentials.
     *
     * @param  string  $method  The outgoing HTTP request method.
     * @param  string  $url  The request URL containing a path and optional private details.
     * @param  string  $expected  The request message expected in the event log.
     */
    #[DataProvider('eventLogRequests')]
    public function test_event_log_messages_show_the_request_method_host_and_path(string $method, string $url, string $expected): void
    {
        // Inspect the message separately from Laravel's structured context array.
        config(['http-remember.event_logs.enabled' => true]);
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->twice()->with('http-remember')->andReturn($logger);
        $logger->shouldReceive('debug')->twice()->withArgs(
            /**
             * Keep the event type in context while showing the method, shortened host, and path.
             */
            static function (string $message, array $context) use ($expected): bool {
                self::assertSame($expected, $message);
                self::assertStringNotContainsString('private-', $message);
                self::assertStringNotContainsString('private-', json_encode($context));

                return in_array($context['event'], ['miss', 'stored'], true);
            },
        );
        Http::fakeSequence()->push('private-response');

        self::assertSame('private-response', Http::remember(60, operation: 'read')->send($method, $url)->body());
    }

    /**
     * Provide methods, hosts, and paths that exercise concise request messages.
     *
     * @return iterable<string, array{string, string, string}> Requests keyed by their message rule.
     */
    public static function eventLogRequests(): iterable
    {
        // Strip schemes, credentials, queries, fragments, ports, and the leading www prefix.
        yield 'https' => ['GET', 'https://www.example.com/products?token=private-query#private-fragment', '[GET] example.com/products'];
        yield 'http with port' => ['GET', 'http://www.example.com:8080/products', '[GET] example.com/products'];
        yield 'mixed case' => ['GET', 'https://WWW.Example.COM/Products', '[GET] example.com/Products'];
        yield 'credentials' => ['GET', 'https://private-user:private-password@www.example.com/products', '[GET] example.com/products'];
        yield 'api subdomain' => ['GET', 'https://api.example.test/products?token=private-query', '[GET] api.example.test/products'];
        yield 'ip address' => ['GET', 'http://127.0.0.1:8080/products', '[GET] 127.0.0.1/products'];
        yield 'root' => ['GET', 'https://api.example.test?token=private-query#private-fragment', '[GET] api.example.test/'];
        yield 'encoded path' => ['GET', 'https://api.example.test/products/a%3Fb%23c/?token=private-query#private-fragment', '[GET] api.example.test/products/a%3Fb%23c/'];

        // Retain standard and extension methods even when the same endpoint is used.
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'PROPFIND'] as $method) {
            yield $method => [$method, 'https://api.example.test/products?token=private-query#private-fragment', '['.$method.'] api.example.test/products'];
        }
    }

    /**
     * Confirm reused builders and deferred refreshes retain each transfer's own method, host, and path.
     */
    public function test_event_log_messages_keep_the_original_host_during_deferred_refreshes(): void
    {
        // Collect log messages while one request builder sends different methods to two hosts.
        config(['http-remember.event_logs.enabled' => true]);
        $messages = [];
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('http-remember')->andReturn($logger);
        $logger->shouldReceive('debug')->withArgs(
            /**
             * Preserve the message's association with its cache key through deferred execution.
             */
            static function (string $message, array $context) use (&$messages): bool {
                $messages[] = [$message, $context];

                return true;
            },
        );
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()->push('first')->push('second')->push('refreshed-first')->push('refreshed-second');
        $pending = Http::remember([10, 60], operation: 'read');
        $firstUrl = 'https://www.first.example.test/products';
        $secondUrl = 'https://www.second.example.test/categories?token=private-query#private-fragment';
        $pending->post($firstUrl);
        $firstKey = $messages[0][1]['cache_key'];
        $pending->get($secondUrl);
        $secondKey = $messages[2][1]['cache_key'];

        // Run both refreshes after the builder has moved between the two hosts.
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        $pending->post($firstUrl);
        $pending->get($secondUrl);
        app(DeferredCallbackCollection::class)->invoke();

        self::assertCount(14, $messages);
        foreach ($messages as [$message, $context]) {
            self::assertSame($context['cache_key'] === $firstKey ? '[POST] first.example.test/products' : '[GET] second.example.test/categories', $message);
        }
        self::assertNotSame($firstKey, $secondKey);
        Http::assertSentCount(4);
    }

    /**
     * Confirm failure warnings use the request message and remain visible without routine event logs.
     *
     * @param  bool  $enabled  Whether routine event log output is enabled.
     */
    #[DataProvider('warningLogModes')]
    public function test_failure_warnings_keep_the_request_message_and_complete_event_context(bool $enabled): void
    {
        // Inspect failure diagnostics separately from the optional routine event logs.
        config(['http-remember.event_logs.enabled' => $enabled]);
        $eventLogs = $this->eventLogs();
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('http-remember')->andReturn($logger);
        $logger->shouldReceive('debug')->times($enabled ? 6 : 0);
        $warnings = [];
        $logger->shouldReceive('warning')->once()->withArgs(
            /**
             * Retain the warning message and structured fields for comparison with the dispatched event.
             */
            static function (string $message, array $context) use (&$warnings): bool {
                $warnings[] = [$message, $context];

                return true;
            },
        );
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()->push('initial-body')->push('private-error-body', 503);
        $url = 'https://www.example.test/products?token=private-query#private-fragment';
        Http::remember([10, 60])->get($url);
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        $stale = Http::remember([10, 60])->get($url);
        app(DeferredCallbackCollection::class)->invoke();

        // Write one warning for the failed refresh with the same safe fields seen by listeners.
        self::assertCount(1, $warnings);
        self::assertSame('[GET] example.test/products', $warnings[0][0]);
        self::assertSame('refresh_failed', $warnings[0][1]['event']);
        self::assertSame('refresh', $warnings[0][1]['operation']);
        self::assertSame(503, $warnings[0][1]['status']);
        self::assertSame($eventLogs[count($eventLogs) - 1]->toArray(), $warnings[0][1]);
        self::assertStringNotContainsString('private-', json_encode($warnings));
        self::assertSame('initial-body', $stale->body());
        Http::assertSentCount(2);
    }

    /**
     * Provide routine event log settings that must both preserve failure diagnostics.
     *
     * @return iterable<string, array{bool}> Settings keyed by routine log output.
     */
    public static function warningLogModes(): iterable
    {
        // Keep warning records independent of the development-only routine event log default.
        yield 'enabled' => [true];
        yield 'disabled' => [false];
    }

    /**
     * Confirm event log listener and logger failures cannot disable caching or replace responses.
     *
     * @param  bool  $listenerFails  Whether the application listener throws.
     * @param  bool  $loggerFails  Whether the selected log channel throws.
     */
    #[DataProvider('eventLogFailures')]
    public function test_event_log_failures_preserve_http_and_cache_behavior(bool $listenerFails, bool $loggerFails): void
    {
        // Configure independent failing observation sinks around a normal cache lifecycle.
        config(['http-remember.event_logs' => ['enabled' => true, 'channel' => null]]);
        app('events')->listen(HttpRememberEventLog::class,
            /**
             * Fail the application listener only when requested by this scenario.
             */
            static function (HttpRememberEventLog $eventLog) use ($listenerFails): void {
                if ($listenerFails) {
                    throw new RuntimeException('Test event log listener failure.');
                }
            },
        );
        $channel = Log::shouldReceive('channel')->times(3)->with(null);
        if ($loggerFails) {
            $channel->andThrow(new RuntimeException('Test event log channel failure.'));
        } else {
            $logger = Mockery::mock(LoggerInterface::class);
            $logger->shouldReceive('debug')->times(3);
            $channel->andReturn($logger);
        }
        Http::fake([self::API_URL => Http::response('live-body')]);

        // Store and reuse the response even when either observation sink is unavailable.
        $live = Http::remember(60)->get(self::API_URL);
        $cached = Http::remember(60)->get(self::API_URL);
        self::assertSame('live-body', $live->body());
        self::assertSame('live-body', $cached->body());
        Http::assertSentCount(1);
    }

    /**
     * Provide independent and combined event log sink failures.
     *
     * @return iterable<string, array{bool, bool}> Failures keyed by unavailable sink.
     */
    public static function eventLogFailures(): iterable
    {
        // A listener failure must still allow log output, and either sink may fail independently.
        yield 'listener' => [true, false];
        yield 'logger' => [false, true];
        yield 'both' => [true, true];
    }

    /**
     * Collect structured event logs without faking Laravel's normal HTTP or cache events.
     *
     * @return ArrayObject<int, HttpRememberEventLog> The event logs collected during this test.
     */
    private function eventLogs(): ArrayObject
    {
        // Keep collection state local to this isolated application and its deferred callbacks.
        $eventLogs = new ArrayObject;
        app('events')->listen(HttpRememberEventLog::class,
            /**
             * Retain each immutable event log in dispatch order.
             */
            static function (HttpRememberEventLog $eventLog) use ($eventLogs): void {
                $eventLogs[] = $eventLog;
            },
        );

        return $eventLogs;
    }

    /**
     * Retrieve observed event names for assertions about lifecycle ordering.
     *
     * @param  ArrayObject<int, HttpRememberEventLog>  $eventLogs  The collected event logs.
     * @return list<string> The event names in dispatch order.
     */
    private function eventNames(ArrayObject $eventLogs): array
    {
        // Compare ordering without repeating the structured payload in each assertion.
        return array_column($eventLogs->getArrayCopy(), 'event');
    }
}
