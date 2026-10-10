<?php

/**
 * Configure the default policy for explicitly remembered HTTP requests.
 * Choose a fixed lifetime or [fresh, total lifetime] thresholds.
 * Durations are measured in seconds. Sizes are measured in bytes.
 *
 * @return array{ttl: int|array{int, int}, store: string|null, refresh_timeout: int, max_response_bytes: int, ignored_headers: list<string>}
 */
return [

    // Use 3600 for fixed expiry or [1800, 3600] for stale-while-revalidate.
    'ttl' => [1800, 3600],

    // Use the application's default cache store unless a name is supplied.
    'store' => null,

    // Limit deferred refreshes to 15 seconds; preserve shorter request timeouts.
    'refresh_timeout' => 15,

    // Cache response bodies up to 1 MB.
    'max_response_bytes' => 1_000_000,

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
