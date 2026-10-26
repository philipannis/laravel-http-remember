<?php

/**
 * Configure the default policy for explicitly remembered HTTP requests.
 * Set the maximum cache lifetimes. HTTP restrictions can shorten them or prevent caching.
 * Durations are measured in seconds. Sizes are measured in bytes.
 *
 * @return array{ttl_seconds: int|array{int, int}, store: string|null, refresh_timeout_seconds: int, refresh_cooldown_seconds: int, max_request_bytes: int, max_response_bytes: int, ignored_headers: list<string>, event_logs: array{enabled: bool, channel: string|null, redact: (callable(string): string)|null}}
 */
return [

    // Use 60 for fixed expiry or [60, 300] for stale-while-revalidate.
    'ttl_seconds' => [60, 300],

    // Use the application's default cache store unless a name is supplied.
    'store' => null,

    // Limit deferred refreshes to 15 seconds while preserving shorter request timeouts.
    'refresh_timeout_seconds' => 15,

    // Wait at least 15 seconds after failed refreshes, honoring longer retry-after delays.
    'refresh_cooldown_seconds' => 15,

    // Bypass response caching for request bodies larger than 1 MB.
    'max_request_bytes' => 1_000_000,

    // Cache response bodies up to 1 MB.
    'max_response_bytes' => 1_000_000,

    // Enable event logs during development and keep them in the package's dedicated log channel.
    'event_logs' => [
        'enabled' => in_array(env('APP_ENV', 'production'), ['local', 'development', 'dev'], true),
        'channel' => 'http-remember',
        'redact' => null,
    ],

    // Ignore common tracing and correlation headers when generating cache keys.
    'ignored_headers' => [
        'traceparent',
        'tracestate',
        'request-id',
        'x-request-id',
        'x-correlation-id',
        'client-request-id',
        'x-ms-client-request-id',
        'x-cloud-trace-context',
        'x-amzn-trace-id',
    ],

];
