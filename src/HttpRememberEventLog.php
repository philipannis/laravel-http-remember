<?php

namespace PhilipAnnis\HttpRemember;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publish a safe event log for an HTTP response cache decision or lifecycle outcome.
 */
final readonly class HttpRememberEventLog
{
    /**
     * Retain identifiers and diagnostic values without storing requests or response bodies.
     *
     * @param  string  $event  The cache decision or lifecycle outcome.
     * @param  string|null  $cacheKey  The hashed response key, when available.
     * @param  string|null  $store  The cache store selected before the transfer started.
     * @param  string|null  $groupHash  The hashed invalidation group, when selected.
     * @param  string|null  $reason  The miss, bypass, or skipped-operation reason.
     * @param  float|null  $age  The cached response's age in seconds, when relevant.
     * @param  int|null  $status  The upstream or remembered HTTP status, when available.
     * @param  string|null  $operation  The cache operation associated with a failure.
     * @param  class-string<Throwable>|null  $exception  The exception class, without its message.
     * @param  float|null  $retryAt  The next permitted refresh time as a Unix timestamp, when cooling down.
     */
    public function __construct(
        public string $event,
        public ?string $cacheKey,
        public ?string $store,
        public ?string $groupHash = null,
        public ?string $reason = null,
        public ?float $age = null,
        public ?int $status = null,
        public ?string $operation = null,
        public ?string $exception = null,
        public ?float $retryAt = null,
    ) {}

    /**
     * Return the structured event log context used by Laravel's logger.
     *
     * @return array{event: string, cache_key: string|null, store: string|null, group_hash: string|null, reason: string|null, age: float|null, status: int|null, operation: string|null, exception: string|null, retry_at: float|null}
     */
    public function toArray(): array
    {
        // Keep context names stable for application listeners and log processors.
        return [
            'event' => $this->event,
            'cache_key' => $this->cacheKey,
            'store' => $this->store,
            'group_hash' => $this->groupHash,
            'reason' => $this->reason,
            'age' => $this->age,
            'status' => $this->status,
            'operation' => $this->operation,
            'exception' => $this->exception,
            'retry_at' => $this->retryAt,
        ];
    }

    /**
     * Dispatch the event log and optionally write it to the configured log channel.
     *
     * @param  string  $message  The request description or a message for direct group invalidation.
     * @param  bool  $warning  Whether this failure uses the warning level even when routine logs are disabled.
     *
     * @internal
     */
    public function publish(string $message, bool $warning = false): void
    {
        // Application listeners must not interrupt usable HTTP responses or cache operations.
        try {
            Event::dispatch($this);
        } catch (Throwable) {
            // Continue to the optional log sink even when an event log listener fails.
        }

        // Preserve failure diagnostics while allowing applications to disable routine event log output.
        if (! $warning && ! config('http-remember.event_logs.enabled', false)) {
            return;
        }

        // Keep the skimmable request message and structured context separate for Laravel's normal log format.
        try {
            // Redact only emitted records, including warnings when routine logging is disabled.
            $redact = config('http-remember.event_logs.redact');
            if ($redact !== null) {
                $message = $redact($message);

                // Never fall back to the original message when redaction fails its contract.
                if (! is_string($message)) {
                    return;
                }
            }

            $logger = Log::channel(config('http-remember.event_logs.channel'));
            if ($warning) {
                $logger->warning($message, $this->toArray());
            } else {
                $logger->debug($message, $this->toArray());
            }
        } catch (Throwable) {
            // Redaction or logging failures must not expose the message or alter the response.
        }
    }
}
