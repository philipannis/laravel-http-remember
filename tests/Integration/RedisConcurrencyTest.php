<?php

namespace PhilipAnnis\HttpRemember\Tests\Integration;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\Foundation\Application;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;
use PhilipAnnis\HttpRemember\HttpRememberServiceProvider;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Exercise coordination across two PHP processes sharing the CI Redis service.
 */
final class RedisConcurrencyTest extends TestCase
{
    /**
     * The upstream resource requested by both workers.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The fresh threshold and lifetime for the grouped reads.
     */
    private const STALE_POLICY = [10, 60];

    /**
     * The group invalidated during the second scenario.
     */
    private const GROUP = ['products'];

    /**
     * The child signals readiness from inside the fake upstream handler.
     */
    private const FETCH_STARTED = "fetch-started\n";

    /**
     * The parent releases the paused upstream response through standard input.
     */
    private const COMPLETE_FETCH = "complete-fetch\n";

    /**
     * The cache key prefix shared by the two workers in this test.
     */
    private string $prefix;

    /**
     * The worker that may still be paused if an assertion fails.
     */
    private ?Process $worker = null;

    /**
     * Give each test its own namespace on the disposable Redis service.
     */
    protected function setUp(): void
    {
        // Boot the package and configure this test's shared cache.
        parent::setUp();
        $this->prefix = 'http-remember-ci:'.bin2hex(random_bytes(12)).':';
        self::configureWorker($this->prefix);
    }

    /**
     * Release process and timing state after each test.
     */
    protected function tearDown(): void
    {
        // Stop a paused child before restoring the normal test environment.
        $this->worker?->stop(0);
        Sleep::fake(false);
        parent::tearDown();
    }

    /**
     * Confirm overlapping misses share the lock owner's upstream response.
     */
    public function test_overlapping_misses_share_one_upstream_transfer(): void
    {
        // Pause the first fetch while it holds the miss lock.
        $input = new InputStream;
        $worker = $this->startWorker('miss', $input);
        Http::fake([self::API_URL => Http::response('contender-body')]);

        // Let the first worker finish at the contender's first Redis lock retry.
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(
            /**
             * Complete the fetch once, even if the lock is retried again.
             */
            static function () use ($worker, $input): void {
                if (! $input->isClosed()) {
                    self::completeFetch($worker, $input);
                }
            },
        );

        // Require the contender to wait and reuse the response written to Redis.
        self::assertSame('worker-body', Http::remember(60)->get(self::API_URL)->body());
        Sleep::assertSleptTimes(1);
        Http::assertNothingSent();
        $this->assertWorkerResult($worker, 'worker-body');
    }

    /**
     * Confirm invalidation during a stale refresh cancels its pending cache write.
     */
    public function test_invalidation_cancels_another_workers_in_flight_refresh(): void
    {
        // Retain the original cache key and observe contention for the refresh lock.
        $originalKey = null;
        $refreshWasLocked = false;
        Event::listen(HttpRememberEventLog::class,
            /**
             * Capture the cache write and refresh lock outcomes for this worker.
             */
            static function (HttpRememberEventLog $event) use (&$originalKey, &$refreshWasLocked): void {
                if ($event->event === 'stored') {
                    $originalKey ??= $event->cacheKey;
                } elseif ($event->event === 'refresh_skipped' && $event->reason === 'refresh_lock_unavailable') {
                    $refreshWasLocked = true;
                }
            },
        );

        // Seed the original response and retain its payload for the final check.
        Http::fakeSequence(self::API_URL)->push('original')->pushStatus(204)->push('replacement');
        $pending = Http::remember(self::STALE_POLICY, group: self::GROUP);
        self::assertSame('original', $pending->get(self::API_URL)->body());
        self::assertIsString($originalKey);
        $original = Cache::get($originalKey);
        self::assertSame('original', $original['body'] ?? null);

        // Pause the child's refresh and verify the held lock suppresses a duplicate transfer.
        $input = new InputStream;
        $worker = $this->startWorker('refresh', $input);
        Carbon::setTestNow(Carbon::now()->addSeconds(self::STALE_POLICY[0]));
        self::assertSame('original', $pending->get(self::API_URL)->body());
        $callbacks = app(DeferredCallbackCollection::class);
        self::assertCount(1, $callbacks);
        $callbacks->invoke();
        self::assertTrue($refreshWasLocked);
        Http::assertSentCount(1);

        // Populate the replacement generation while the original upstream response is still paused.
        self::assertSame(204, Http::remember(group: self::GROUP)->put(self::API_URL)->status());
        self::assertSame('replacement', $pending->get(self::API_URL)->body());

        // Release the old refresh and verify both the old payload and the active response.
        self::completeFetch($worker, $input);
        $this->assertWorkerResult($worker, 'original');
        self::assertSame($original, Cache::get($originalKey));
        self::assertSame('replacement', $pending->get(self::API_URL)->body());
        Http::assertSentCount(3);
    }

    /**
     * Connect a test application to the disposable Redis service configured by CI.
     *
     * @param  string  $prefix  The test's cache key namespace.
     */
    private static function configureWorker(string $prefix): void
    {
        // Require the mapped CI port before attempting to connect to Redis.
        $port = filter_var(getenv('HTTP_REMEMBER_CI_REDIS_PORT'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if (getenv('GITHUB_ACTIONS') !== 'true' || $port === false) {
            throw new RuntimeException('This test requires the GitHub Actions Redis service.');
        }

        // Use the same Redis connection for responses, group metadata, and atomic locks.
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'ci', 'prefix' => $prefix],
            'database.redis' => [
                'client' => 'phpredis',
                'ci' => ['host' => '127.0.0.1', 'port' => $port, 'database' => 0, 'timeout' => 2, 'read_timeout' => 2],
            ],
            'http-remember.event_logs.enabled' => false,
            'http-remember.event_logs.channel' => 'null',
        ]);

        // Keep response age consistent across worker startup times.
        Carbon::setTestNow('2026-01-01 12:00:00 UTC');
    }

    /**
     * Start a fresh PHP process and wait for its upstream handler to pause.
     *
     * @param  'miss'|'refresh'  $scenario  The request path the worker exercises.
     * @param  InputStream  $input  The writer for the worker's standard input.
     * @return Process The worker paused inside its fake upstream handler.
     */
    private function startWorker(string $scenario, InputStream $input): Process
    {
        // Load the child entry point from this test file using Composer's autoloader.
        $bootstrap = <<<'PHP'
            require 'vendor/autoload.php';
            \PhilipAnnis\HttpRemember\Tests\Integration\RedisConcurrencyTest::runWorker($argv[1], $argv[2]);
            PHP;
        $this->worker = $worker = new Process(
            [PHP_BINARY, '-r', $bootstrap, $scenario, $this->prefix],
            dirname(__DIR__, 2),
            input: $input,
            timeout: 15,
        );
        $worker->start();

        // Wait for the readiness signal with the process timeout as the deadline.
        while ($worker->isRunning() && ! str_contains($worker->getOutput(), self::FETCH_STARTED)) {
            $worker->checkTimeout();
            usleep(1000);
        }
        self::assertStringStartsWith(self::FETCH_STARTED, $worker->getOutput(), $worker->getErrorOutput());

        return $worker;
    }

    /**
     * Release the paused response and let the child finish its cache operations.
     *
     * @param  Process  $worker  The child paused inside its upstream handler.
     * @param  InputStream  $input  The writer for the worker's standard input.
     */
    private static function completeFetch(Process $worker, InputStream $input): void
    {
        // Send the release signal before waiting for the child to exit.
        $input->write(self::COMPLETE_FETCH);
        $input->close();
        $worker->wait();
    }

    /**
     * Confirm the child completed one upstream transfer and returned the expected response.
     *
     * @param  Process  $worker  The completed child process.
     * @param  string  $body  The response body returned to the worker's caller.
     */
    private function assertWorkerResult(Process $worker, string $body): void
    {
        // Read the JSON result that follows the initial readiness signal.
        self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        $result = substr($worker->getOutput(), strlen(self::FETCH_STARTED));
        self::assertSame(['body' => $body, 'requests' => 1], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Run the request scenario in an independent Laravel application.
     *
     * @param  'miss'|'refresh'  $scenario  The request path selected by the parent.
     * @param  string  $prefix  The cache key namespace shared with the parent.
     */
    public static function runWorker(string $scenario, string $prefix): void
    {
        // Boot a separate application and Redis connection inside the child process.
        Application::create(options: ['extra' => ['providers' => [HttpRememberServiceProvider::class]]]);
        self::configureWorker($prefix);

        // Pause the fake upstream response after the child has entered the protected transfer.
        Http::preventStrayRequests();
        Http::fake([
            self::API_URL =>
                /**
                 * Wait for the parent to finish its competing operation.
                 */
                static function (): PromiseInterface {
                    // Publish readiness before reading the parent's release signal.
                    fwrite(STDOUT, self::FETCH_STARTED);
                    fflush(STDOUT);
                    if (fgets(STDIN) !== self::COMPLETE_FETCH) {
                        throw new RuntimeException('The Redis test did not release the upstream transfer.');
                    }

                    return Http::response('worker-body');
                },
        ]);

        // Run the deferred refresh after serving the stale hit, or fetch a missing response.
        if ($scenario === 'refresh') {
            Carbon::setTestNow(Carbon::now()->addSeconds(self::STALE_POLICY[0]));
            $response = Http::remember(self::STALE_POLICY, group: self::GROUP)->get(self::API_URL);
            app(DeferredCallbackCollection::class)->invoke();
        } else {
            $response = Http::remember(60)->get(self::API_URL);
        }

        // Report the caller's response and the number of upstream transfers.
        echo json_encode(['body' => $response->body(), 'requests' => count(Http::recorded())], JSON_THROW_ON_ERROR), "\n";
    }
}
