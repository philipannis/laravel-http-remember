<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Verify optional message redaction without changing HTTP or cache behavior.
 */
final class LogRedactionTest extends TestCase
{
    /**
     * Confirm a cacheable static callable masks logs while preserving distinct request identities.
     */
    public function test_redaction_preserves_requests_and_cache_keys(): void
    {
        config([
            'http-remember.event_logs.enabled' => true,
            'http-remember.event_logs.redact' => [self::class, 'redact'],
        ]);
        $messages = [];
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('http-remember')->andReturn($logger);
        $logger->shouldReceive('debug')->times(5)->andReturnUsing(
            static function (string $message, array $context) use (&$messages): void {
                $messages[] = [$message, $context];
            },
        );
        Http::fakeSequence()->push('first-body')->push('second-body');
        $firstUrl = 'https://api.example.test/tokens/private-first?secret=private-query';
        $secondUrl = 'https://api.example.test/tokens/private-second?secret=private-query';

        self::assertSame('first-body', Http::remember(60)->get($firstUrl)->body());
        self::assertSame('second-body', Http::remember(60)->get($secondUrl)->body());
        self::assertSame('first-body', Http::remember(60)->get($firstUrl)->body());

        self::assertSame(array_fill(0, 5, '[GET] api.example.test/tokens/[redacted]'), array_column($messages, 0));
        self::assertStringNotContainsString('private-', json_encode($messages));
        self::assertNotSame($messages[0][1]['cache_key'], $messages[2][1]['cache_key']);
        Http::assertSentCount(2);
        Http::assertSent(static fn ($request): bool => $request->url() === $firstUrl);
        Http::assertSent(static fn ($request): bool => $request->url() === $secondUrl);
    }

    /**
     * Confirm deferred failure warnings remain redacted with routine logging disabled.
     */
    public function test_redaction_runs_only_when_a_record_is_written(): void
    {
        $inputs = [];
        config([
            'http-remember.event_logs.enabled' => false,
            'http-remember.event_logs.redact' => static function (string $message) use (&$inputs): string {
                $inputs[] = $message;

                return self::redact($message);
            },
        ]);
        $messages = [];
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->once()->with('http-remember')->andReturn($logger);
        $logger->shouldReceive('warning')->once()->andReturnUsing(
            static function (string $message, array $context) use (&$messages): void {
                $messages[] = [$message, $context];
            },
        );
        $startedAt = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($startedAt);
        Http::fakeSequence()->push('initial-body')->push('unavailable', 503);
        $url = 'https://private-user:private-password@api.example.test/tokens/private-token?secret=private-query#private-fragment';
        Http::remember([10, 60])->get($url);
        Carbon::setTestNow($startedAt->copy()->addSeconds(10));
        self::assertSame('initial-body', Http::remember([10, 60])->get($url)->body());
        self::assertSame([], $inputs);

        app(DeferredCallbackCollection::class)->invoke();

        self::assertSame(['[GET] api.example.test/tokens/private-token'], $inputs);
        self::assertSame('[GET] api.example.test/tokens/[redacted]', $messages[0][0]);
        self::assertSame('refresh_failed', $messages[0][1]['event']);
        self::assertSame(503, $messages[0][1]['status']);
        self::assertStringNotContainsString('private-', json_encode($messages));
        Http::assertSentCount(2);
    }

    /**
     * Confirm invalid redactors cannot expose raw messages or interfere with event listeners and caching.
     *
     * @param  string  $failure  The invalid configuration or callback result.
     */
    #[DataProvider('redactionFailures')]
    public function test_redaction_failures_skip_records_without_breaking_requests(string $failure): void
    {
        config([
            'http-remember.event_logs.enabled' => true,
            'http-remember.event_logs.redact' => match ($failure) {
                'not_callable' => false,
                'exception' => static fn () => throw new RuntimeException('private-error'),
                'null' => static fn () => null,
                'integer' => static fn () => 123,
            },
        ]);
        Log::shouldReceive('channel')->never();
        $events = [];
        app('events')->listen(HttpRememberEventLog::class,
            static function (HttpRememberEventLog $event) use (&$events): void {
                $events[] = $event->toArray();
            },
        );
        Http::fakeSequence()->push('live-body');
        $url = 'https://api.example.test/tokens/private-token';

        self::assertSame('live-body', Http::remember(60)->get($url)->body());
        self::assertSame('live-body', Http::remember(60)->get($url)->body());
        self::assertSame(['miss', 'stored', 'hit'], array_column($events, 'event'));
        self::assertStringNotContainsString('private-', json_encode($events));
        Http::assertSentCount(1);
    }

    /**
     * Supply redaction failures that must suppress the original log message.
     *
     * @return iterable<string, array{string}> Failures keyed by their cause.
     */
    public static function redactionFailures(): iterable
    {
        yield 'not callable' => ['not_callable'];
        yield 'exception' => ['exception'];
        yield 'null result' => ['null'];
        yield 'integer result' => ['integer'];
    }

    /**
     * Mask token path segments using the same callable form as cached application configuration.
     */
    public static function redact(string $message): string
    {
        return preg_replace('~(/tokens/)[^/\s]+~', '$1[redacted]', $message);
    }
}
