<?php

/**
 * Configure the default policy for explicitly remembered HTTP requests.
 * Choose a fixed lifetime or [fresh, total lifetime] thresholds.
 * All durations are measured in seconds.
 *
 * @return array{ttl: int|array{int, int}, store: string|null, refresh_timeout: int}
 */
return [

    // Use 3600 for fixed expiry or [1800, 3600] for stale-while-revalidate.
    'ttl' => [1800, 3600],

    // Use the application's default cache store unless a name is supplied.
    'store' => null,

    // Limit deferred refreshes to 15 seconds; preserve shorter request timeouts.
    'refresh_timeout' => 15,

];
