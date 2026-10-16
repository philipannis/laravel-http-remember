# Laravel HTTP Remember

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square)
![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat-square)
[![License](https://img.shields.io/badge/license-MIT-334155?style=flat-square)](LICENSE)

Add `remember()` to Laravel's HTTP client to cache successful API responses. It handles cache keys, response storage, and refreshes using your application's cache store. Use `group` to invalidate related cached reads after a live mutation or with `invalidate: true`. Requests without `remember()` skip the package entirely.

## Contents

- [Installation](#installation)
- [Cache modes](#cache-modes)
- [Per-request options](#per-request-options)
- [Group related requests](#group-related-requests)
- [Configuration](#configuration)
- [Cache keys and responses](#cache-keys-and-responses)
- [Event logs](#event-logs)
- [Refreshes and failures](#refreshes-and-failures)
- [Request options and limits](#request-options-and-limits)
- [Testing](#testing)
- [License](#license)

## Installation

Requires PHP 8.2+ with Laravel 12, or PHP 8.3+ with Laravel 13.

```bash
composer require "philipannis/laravel-http-remember:^1.0"
```

Then add `remember()` before sending a request:

```php
use Illuminate\Support\Facades\Http;

// Cache successful responses while keeping the usual authentication and response methods.
$products = Http::withToken(config('services.catalog.token'))
    ->acceptJson()
    ->remember()
    ->get('https://api.example.com/products')
    ->throw()
    ->json();
```

The default keeps responses **fresh for 30 minutes** and **expires them after 60 minutes**. In between, callers get the cached response while a refresh runs after the current request ends. The examples below use the same `Http` import.

Laravel discovers the service provider automatically. There are no package migrations, routes, commands, or scheduled jobs to set up. You don't need a queue worker or a published config file.

Your application's cache store must be configured and available. With the database cache driver, its cache-table migration must have run.

If you've disabled package discovery, add `PhilipAnnis\HttpRemember\HttpRememberServiceProvider::class` to the existing array in `bootstrap/providers.php`.

## Cache modes

All durations are in **seconds**. Both modes cache only successful responses with status codes **200 through 299**.

### Fixed lifetime

Pass a number of seconds to work much like `Cache::remember()`:

```php
// Cache the first successful response for one hour.
$response = Http::remember(3600)
    ->get('https://api.example.com/products');
```

The first request waits for the API. If it succeeds, matching requests reuse the response for one hour. After expiry, the next request waits for the API again and starts a new lifetime if it succeeds.

Reading from the cache doesn't extend its lifetime. There are no deferred refreshes in this mode.

### Stale-while-revalidate (default)

Use this when a quick response matters more than having the very latest data. Pass `[fresh, lifetime]` to serve a cached response while a refresh is deferred:

```php
// Use the default: fresh for 30 minutes, cached for 60 minutes in total.
$response = Http::remember([1800, 3600])
    ->get('https://api.example.com/products');
```

**The second value is the total lifetime, including the fresh period.** The defaults give you 30 fresh minutes followed by up to 30 stale minutes, measured from the successful response.

| Cache age | What happens |
| --- | --- |
| No entry | Wait for the API and cache the response immediately if successful. |
| Under 30 minutes | Return the cached response without refreshing. |
| 30 to under 60 minutes | Return the cached response and refresh after the current request, command, or job ends. |
| 60 minutes or older | Wait for the API and replace the entry if successful. |

## Per-request options

```php
// Cache for ten minutes without deferred refreshes.
$categories = Http::remember(600)
    ->get('https://api.example.com/categories');

// Keep availability fresh for two minutes and cached for ten minutes in total.
$availability = Http::remember([120, 600])
    ->get('https://api.example.com/availability');

// Use Redis with the configured default lifetime.
$response = Http::remember(store: 'redis')
    ->get('https://api.example.com/products');

// Use Redis with five fresh minutes and a fifteen-minute total lifetime.
$response = Http::remember([300, 900], store: 'redis')
    ->get('https://api.example.com/products');
```

A fixed lifetime must be a positive integer. A fresh/stale pair must contain exactly two integers with `0 <= fresh < lifetime`. For example, `[0, 300]` makes every cache hit stale and expires it after five minutes. Invalid values throw `InvalidArgumentException` immediately.

The store name comes from your application's `config/cache.php`. Omitting a lifetime or store, or passing `null`, uses the package configuration. Blank store names are rejected. Passing `store: null` uses the configured package store, which may itself select the application's default.

Policy settings are captured when you call `remember()`. Calling it again before sending replaces the previous settings. Invalid settings are rejected before the previous valid policy is replaced. A reused request builder keeps its options, so start a new `Http` chain for unrelated requests.

### Select responses to cache

Use `cacheWhen` when an API returns HTTP 200 for application errors, such as GraphQL errors, or when other response details determine whether it should be cached. The callback receives an `Illuminate\Http\Client\Response` and must return a boolean. Use its usual `json()`, `body()`, `header()`, and `status()` methods to inspect the API's result:

```php
use Illuminate\Http\Client\Response;

// Exclude GraphQL errors even when the API returns HTTP 200.
$products = Http::remember(600, cacheWhen:
    fn (Response $response): bool => empty($response->json('errors')),
)->post('https://api.example.com/graphql', [
    'query' => 'query { products { id name } }',
])->json();
```

The predicate runs before storing eligible live responses and deferred refreshes. Cache hits reuse the stored response without invoking the callback. Returning `false` skips storing the live response while preserving its normal result for the caller. A rejected deferred refresh keeps the previous entry until its original expiry.

Predicates receive the live response's original headers and an independent body stream. Reading, closing, or changing that copy does not alter the caller's response or the body stored in the cache. Transfer statistics and cookie jars are not populated on the inspection copy.

Use a predicate that can safely run during live requests and deferred refreshes. Callback exceptions or non-boolean return values reject caching and log the hashed key and exception class. Live responses still reach their caller. The callback can only narrow the built-in eligibility rules: non-2xx responses and requests that bypass caching remain outside filtering. Grouped mutations keep their normal invalidation behavior without invoking the predicate.

`cacheWhen` accepts any PHP callable, including closures and invokable objects. Omitting it or passing `null` uses the usual successful-response policy. Calling `remember()` again replaces the predicate along with the other settings. Predicates stay on the request builder and are never serialized into the cache or included in its key. Existing entries keep their original lifetime, so invalidate the relevant group or remove the cached entries when changing a rule that should also apply to previously stored responses.

### Other HTTP methods

The request body is part of the cache key, so you can also cache a POST used to read data, such as a search:

```php
// Cache each search separately based on its payload.
$results = Http::withToken(config('services.catalog.token'))
    ->remember([300, 900])
    ->post('https://api.example.com/search', [
        'query' => 'linen',
    ])
    ->json();
```

Changing the payload creates a separate entry. Without a group, any HTTP method uses the existing caching behavior. The operation must be safe to skip on a cache hit and repeat during a refresh. **Don't cache payments, order creation, or other operations with side effects.** Use a group for live mutations that should invalidate related reads.

## Group related requests

Give related requests the same `group`. Reads are cached. Mutation methods run live and invalidate the group's cached reads after a non-error HTTP response:

```php
$group = ['tenant:42', 'products'];

// Cache the product list and an individual product in the same group.
$products = Http::remember(600, group: $group)
    ->get('https://api.example.com/products');

$product = Http::remember(300, group: $group)
    ->get('https://api.example.com/products/42');

// Send the update live and invalidate both cached reads after success.
$updated = Http::remember(group: $group)
    ->put('https://api.example.com/products/42', ['name' => 'Updated product']);

// Fetch and cache an updated list on the next read.
$products = Http::remember(600, group: $group)
    ->get('https://api.example.com/products');
```

Order does not matter: `['tenant:42', 'products']` and `['products', 'tenant:42']` identify the same group. Matching uses the **complete array**, so `['products']` is a different group. Include a tenant or other scope when changes should affect only that scope.

Group state lives in the selected cache store. Use the same store for related reads and mutations. The same group in a separate store has its own generation. Other groups and ungrouped responses keep their existing lifetimes.

GET, HEAD, OPTIONS, and TRACE are treated as reads. Other methods, including POST, PUT, PATCH, and DELETE, are treated as mutations when a group is supplied. Mutations are never served from the cache, stored as cached responses, or repeated during deferred refreshes. A 2xx or 3xx response invalidates the group. A 4xx, 5xx, or connection failure preserves it. Redirects invalidate before their follow-up requests run.

### Invalidate a group directly

Pass `invalidate: true` to `remember()` when a webhook, local update, or other application action should invalidate related reads:

```php
// Invalidate the exact group immediately without sending an HTTP request.
Http::remember(group: ['tenant:42', 'products'], invalidate: true);

// Invalidate the same group in an explicitly selected cache store.
Http::remember(store: 'redis', group: ['tenant:42', 'products'], invalidate: true);
```

Invalidation runs once when `remember()` is called. It affects every cached URL, payload, credential, and lifetime variant in that exact group and selected store. Other groups and ungrouped responses keep their existing lifetimes. Pending refreshes are cancelled, and older in-flight reads or refreshes cannot populate the replacement group generation.

The call still returns a normal configured request builder, so you can invalidate and fetch an updated response in one chain:

```php
// Invalidate once, then fetch and cache an updated list for ten minutes.
$products = Http::remember(600, group: ['tenant:42', 'products'], invalidate: true)
    ->get('https://api.example.com/products');
```

Reusing that builder keeps ordinary caching behavior. It does not invalidate again on each request. Call `remember(..., invalidate: true)` again when the group changes.

`invalidate` defaults to `false`. Direct invalidation requires a non-empty `group`; omitting it, passing `null`, or passing an empty list throws `InvalidArgumentException`. The same group validation, exact matching, and store defaults apply. Cache failures are logged through the existing `failed` event with `operation: 'invalidate'` and preserve the usual builder return value.

### Cache POST searches and other reads

Use `operation: 'read'` when a method normally associated with writes performs a read. This works for POST searches, reports, RPC calls, GraphQL queries, and other APIs:

```php
$group = ['tenant:42', 'products'];

// Cache each search payload separately and invalidate it when the group changes.
$results = Http::remember(600, group: $group, operation: 'read')
    ->post('https://api.example.com/products/search', ['query' => 'linen'])
    ->json();

// A GraphQL query can also be a cacheable POST in the same group.
$products = Http::remember(600, group: $group, operation: 'read')
    ->post('https://api.example.com/graphql', [
        'query' => 'query { products { id name } }',
    ])
    ->json();
```

Repeated identical reads use their own cached response. A successful grouped mutation invalidates all of them, even when their URLs, payloads, credentials, or lifetimes differ.

You do not need `operation: 'read'` for ordinary GET requests or for POST reads without a group. Use it only for operations that are safe to skip on a cache hit and repeat during a refresh. For actual writes, including GraphQL mutations, leave it out so grouped mutation methods run live and invalidate the group. The package does not inspect payloads to determine the operation's intent.

### Reuse a configured client

Call `remember()` once on a shared request builder to avoid repeating the group:

```php
$api = Http::baseUrl('https://api.example.com')
    ->remember(600, group: ['tenant:42', 'products']);

$api->get('/products'); // Cache the read.
$api->put('/products/42', ['name' => 'Updated product']); // Invalidate the group.
$api->get('/products'); // Fetch and cache the updated list.
```

The selected `operation` stays on that builder too. Use `operation: 'read'` on builders dedicated to read operations. Leave it unset on builders that mix ordinary reads and writes. Calling `remember()` again replaces the entire policy, including `group`, `operation`, and `cacheWhen`.

Cloning a builder preserves its current policy. Calling `remember()` on the clone replaces only the clone's policy. Cache hit bookkeeping uses the builder that sends the request, including its attributes and selected cookie jar.

### Request behavior

| Usage | Behavior |
| --- | --- |
| No `remember()` | Ordinary live request with no package caching or invalidation. |
| `remember()` with no group | Cache eligible responses for any method. |
| `remember(group: [...])` | Cache reads and invalidate the group after live mutations. |
| `remember(group: [...], operation: 'read')` | Cache an eligible read using any HTTP method. |
| `remember(group: [...], invalidate: true)` | Invalidate the group immediately, then configure ordinary remembered requests. |

Omit `operation` to use automatic method detection for grouped requests: a GET remains a cacheable read, while POST, PUT, PATCH, and DELETE run live and invalidate the group. When supplied, `operation` accepts only the exact string `'read'`, which enables caching for any eligible method. Other strings and explicit `null` throw `InvalidArgumentException`. Unsupported argument types are also rejected. Operation settings retain the response eligibility and bypass rules below.

To skip the package, simply omit `remember()`. A plain `Http::put(...)` does not invalidate remembered reads, even at the same URL. Opt related writes in with `remember(group: ...)` or use a builder that already carries that policy.

Groups accept a positional array of non-empty strings. Values remain exact, including case, surrounding whitespace, and duplicate counts. Associative arrays, non-string values, and blank or whitespace-only strings throw `InvalidArgumentException`. Omitting `group`, passing `null`, or passing an empty array disables grouping and uses the same ordinary caching behavior.

## Configuration

You only need to publish the config if you want to change the defaults for your application.

### 1. Publish the configuration

```bash
php artisan vendor:publish --tag=http-remember-config
```

### 2. Edit `config/http-remember.php`

```php
// Set defaults for remembered requests. All durations are in seconds.
return [
    // Keep responses fresh for 30 minutes and cached for 60 minutes in total.
    'ttl' => [1800, 3600],

    // Use the application's default cache store.
    'store' => null,

    // Limit network timeouts during deferred refreshes to 15 seconds.
    'refresh_timeout' => 15,

    // Cache response bodies up to 1 MB.
    'max_response_bytes' => 1_000_000,

    // Enable event logs during development and use the dedicated package log.
    'event_logs' => [
        'enabled' => in_array(env('APP_ENV', 'production'), ['local', 'development', 'dev'], true),
        'channel' => 'http-remember',
    ],

    // Exclude common tracing and correlation headers from cache keys.
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
```

- `ttl` accepts the same values as `remember()`: use `3600` for a fixed hour, or `[1800, 3600]` for the default fresh/stale behavior.
- `store` is a configured cache store name. Leave it `null` to use your application's default, or set it to `'redis'` to use Redis.
- `refresh_timeout` must be a positive integer. It caps Guzzle's `timeout`, `connect_timeout`, and `read_timeout` options for deferred refreshes only. Shorter timeouts stay unchanged. Unlimited or longer timeouts use this cap. It doesn't change timeouts for requests that callers wait for.
- `max_response_bytes` must be a positive integer and defaults to **1 MB (1,000,000 bytes)**. It limits the response body copied into the cache, including during deferred refreshes. Bodies exactly at the limit are eligible. Larger bodies pass through normally without being cached. Existing entries keep their original expiry when the limit changes. The limit applies to the actual body stream, after decompression when enabled, and excludes headers and cache serialization overhead.
- `event_logs.enabled` defaults to `true` when `APP_ENV` is `local`, `development`, or `dev`, and `false` otherwise. Set it explicitly to enable or disable event log output in any environment. Laravel listeners still receive event logs when file output is disabled.
- `event_logs.channel` defaults to `'http-remember'`. The package supplies a daily log at `storage/logs/http-remember-YYYY-MM-DD.log`, with a `debug` level and 7 days of retention. Define `logging.channels.http-remember` to customize that channel, choose another configured channel, or use `null` for the application's default. Existing cache failure warnings use the selected channel even when routine event log output is disabled.
- `ignored_headers` is a positional list of valid HTTP header names excluded from cache keys. The defaults cover common tracing and correlation headers, so changing a request ID does not create another cache entry. Names match case-insensitively and duplicates are removed. Blank names, names containing whitespace, associative arrays, and non-string values are rejected immediately. Replace the list to customize it, or set it to `[]` to include all request headers. Headers are still sent upstream normally.

Calling `remember()` without arguments uses these defaults. Passing a lifetime or store overrides that setting for one request.

### 3. Rebuild cached configuration when needed

If your deployment uses cached configuration, rebuild it after editing:

```bash
php artisan config:cache
```

Skip this step if you don't cache configuration.

## Cache keys and responses

### Request identity

Keys use the `http-remember:` prefix followed by a SHA-256 hash of the method, URL (including its query string but excluding its fragment), headers other than the configured `ignored_headers`, body, protocol, relevant authentication and transport options, and cache lifetime settings.

The transport options included in the key are `auth`, `cert`, `ssl_key`, `verify`, `decode_content`, and `proxy`. Network timeouts and delays don't create separate cache entries.

Header names and their ordering are normalized. Included header values, body bytes, and query ordering stay as sent. Equivalent query strings or JSON bodies with different ordering can therefore create distinct entries. The default exclusions preserve separate entries for different bearer tokens, basic credentials, cookie headers, bodies, or lifetimes. URLs and credentials aren't written into keys as plaintext.

Only exclude headers that do not affect the response data. Authentication, tenant, language, API-version, and idempotency headers remain in the key by default. Responses with `Vary: *` or a `Vary` field naming an ignored header are returned live without being cached or reused, because their variants cannot be distinguished by the filtered key. Other `Vary` headers remain cacheable because their request headers are still included.

### Group generations and invalidation

Grouped keys also include the SHA-256 hash of the sorted group array and its current generation. Group values are not written into keys as plaintext. Requests without a group use the same filtered request fingerprint without group fields. `operation` does not change the identity of a cacheable response.

Invalidation replaces one shared generation record instead of scanning response keys, so groups work without cache tags or atomic locks. Generation records use the `http-remember:group:` prefix followed by the group hash. Old responses become unreachable and expire at their existing backend lifetimes. The store retains one small generation record per group until it is replaced or removed. Missing metadata creates a fresh generation and never restores older cached responses.

To remove one cached response manually, take the full `cache_key` from a `hit` or `stored` event log and call `Cache::store($store)->forget($key)` with that key and store. The package uses Laravel's cache API for manual removal.

### Response handling

The cache stores arrays and scalars for the response's status, headers, body, protocol, reason phrase, and creation metadata. Each cache hit creates a normal `Illuminate\Http\Client\Response` with its own body stream. You can keep using `json()`, `body()`, `header()`, `successful()`, and `throw()`.

Malformed cache payloads are treated as misses. Response headers and bodies are stored as ordinary cache data, even though the keys are hashed.

Lifetimes come from the `remember()` policy. Upstream `Cache-Control`, `Expires`, and `Age` headers do not shorten or disable caching. `ETag` and `Last-Modified` are retained as response headers, but the package does not build conditional requests from them. Use `cacheWhen` if response headers should affect eligibility.

Responses containing `Set-Cookie` are still cached. Live responses deliver those cookies to their caller, while cached copies retain the body and other headers and omit `Set-Cookie`. Cookies already sent with a request remain part of its cache key, so different established sessions use separate entries. Use ordinary live requests for session creation or authentication flows that need fresh cookies or issue session credentials in the response body.

Cache hits still emit Laravel's `RequestSending` and `ResponseReceived` events and retain the sending builder's request attributes and cookie jar. They have no network transfer statistics: `handlerStats()` returns `[]` and `effectiveUri()` returns `null`. Guzzle's `on_stats` callback runs only for live transfers.

### Async requests and pools

Async requests keep their usual promise behavior, including on cache hits:

```php
$promise = Http::async()->remember(600)
    ->get('https://api.example.com/products');

$products = $promise->wait()->json();
```

Add `remember()` and any authentication or other options to each request inside a pool:

```php
use Illuminate\Http\Client\Pool;

$responses = Http::pool(fn (Pool $pool) => [
    $pool->as('products')->remember(600)
        ->get('https://api.example.com/products'),
    $pool->as('categories')->remember(600)
        ->get('https://api.example.com/categories'),
]);

$products = $responses['products']->json();
```

## Event logs

Event logs describe cache decisions and refresh outcomes. They are written to the dedicated package log by default in development environments. Other environments keep event log output disabled until you enable it in `config/http-remember.php`.

Event logs use Laravel's standard log format. The message shows `[METHOD] hostname/path`, with the scheme, leading `www.`, port, credentials, query parameters, and fragment omitted for quick skimming. The request path keeps its original case and encoding. A request without a path shows `/`. The event type and all other fields are passed as a separate context array. The dedicated channel uses Laravel's normal formatter:

```text
[2026-01-01 12:00:10] local.DEBUG: [GET] api.example.com/products {"event":"hit","cache_key":"http-remember:...","store":"redis","group_hash":null,"reason":"stale","age":10.0,"status":200,"operation":null,"exception":null}
```

Failure warnings use the same request message and structured context at the `warning` level. They remain enabled when routine event log output is disabled, and a failed operation produces one warning rather than duplicate debug and warning records. Application channel overrides retain their own formatting.

Direct group invalidation uses the message `HTTP remember group invalidation.` or `HTTP remember group invalidation failed.` with the selected store, hashed group, and `operation: 'invalidate'`. Its request key and HTTP status are `null` because it sends no HTTP request.

### Listen for event logs

The package dispatches an immutable `HttpRememberEventLog` through Laravel for each decision, independently of log-channel output:

```php
use Illuminate\Support\Facades\Event;
use PhilipAnnis\HttpRemember\HttpRememberEventLog;

Event::listen(HttpRememberEventLog::class, function (HttpRememberEventLog $eventLog): void {
    if ($eventLog->event === 'refresh_failed') {
        // Record an application metric using the safe event log context.
        $context = $eventLog->toArray();
    }
});
```

| Event log | Meaning |
| --- | --- |
| `hit` | A fresh or stale response was served. `reason` gives its freshness and `age` gives its age in seconds. |
| `miss` | A live fetch is needed. `reason` identifies a missing, expired, invalid, or unusable entry. |
| `bypass` | A request runs live because of its transport options, hooks, a cache read failure, or grouped mutation intent. |
| `stored` | The cache accepted a live response or refreshed replacement. |
| `not_stored` | A response was not persisted. `reason` identifies the eligibility, generation, lock, or write outcome. |
| `refresh_scheduled` | A stale hit requested deferred work. |
| `refresh_started` | Deferred work passed its generation checks and is about to contact the API. |
| `refresh_succeeded` | A deferred refresh stored its replacement response. |
| `refresh_skipped` | A refresh was cancelled, its lock was unavailable, or its response could not be stored. |
| `refresh_failed` | A refresh failed during scheduling, transport, upstream handling, or cache persistence. |
| `invalidated` | Direct invalidation or a successful grouped mutation replaced the group's generation. |
| `failed` | A foreground request or cache operation failed. `operation` identifies the failed operation. |

Each event log exposes `event`, `cacheKey`, `store`, `groupHash`, `reason`, `age`, `status`, `operation`, and `exception`. Unavailable fields are `null`. `toArray()` uses `cache_key` and `group_hash` for log context. Keys and groups are hashed. Records omit query strings, fragments, credentials, headers, bodies, and exception messages. The method, hostname, and path appear only in the log message. Enabled event logs use the `debug` log level.

Request bypass reasons include `request_hooks`, `on_headers`, `on_trailers`, `stream`, `sink`, `curl`, `stream_context`, `multipart`, `request_body`, `mutation`, and `cache_read_failed`. Miss reasons include `missing`, `invalid_entry`, `expired`, `group_invalidated`, and `vary`. Storage reasons include `response_status`, `response_stream`, `response_size`, `response_predicate`, `vary`, `group_invalidated`, `generation_changed`, `write_lock_unavailable`, and `cache_write_failed`. Refresh cancellation can also report `expired`, `request_hooks`, or `refresh_lock_unavailable`.

Every stale hit emits `refresh_scheduled`, while Laravel deduplicates actual execution by callback name. Count `refresh_started` and the final refresh outcome to measure refresh work. Listener and log-channel exceptions are contained so they do not replace HTTP responses, change cache lifetimes, or interfere with invalidation.

## Refreshes and failures

### When refreshes run

Refreshes use Laravel's deferred functions. They run after the HTTP response is sent, or when the current Artisan command or queue job finishes. They also run after failures if Laravel reaches its normal deferred callback handling.

Keep `InvokeDeferredCallbacks` and the normal termination hooks enabled. Long-running commands wait until the command ends to refresh. Deferred work runs in the current PHP worker and depends on that worker reaching termination. It has no durable job storage or queue retry policy.

Refreshes replay the prepared request from the stale hit with an independent copy of its complete body. They reuse its URL, headers, credentials, and selected cache store. Credentials are not fetched again from application configuration. Foreground `delay` options are removed from the refresh.

### Failure behavior

| What happens | Result |
| --- | --- |
| The API returns a non-2xx response | Return it as usual without caching it. |
| The response predicate rejects a live response | Return it as usual without caching it. |
| The response predicate throws or returns a non-boolean value | Skip caching and log the predicate failure. |
| A live request throws or times out | Keep the usual exception or rejected promise. |
| A deferred refresh fails | Keep the old entry until its original expiry and log the failure. |
| A cache read or write fails | Let the live request proceed and log the cache failure. |

Refreshes make one attempt through the remaining Guzzle handler stack. `retry()` still applies to foreground requests. Deferred refreshes don't repeat that outer retry loop. Failed refreshes never extend the stale period.

### Expiry and concurrent requests

For stale-while-revalidate policies, the cache store retains each response for `refresh_timeout` plus 10 seconds beyond its logical lifetime. This gives a refresh that starts before expiry time to finish while its original generation can still be checked. Requests always enforce the configured lifetime, so retained expired responses are never served. Fixed-lifetime policies expire in the store at their configured lifetime.

A refresh replaces only the generation it started with. Removing that cache entry or flushing the store before the refresh's final generation check cancels its write, even when the original lifetime has elapsed. A missing generation is never treated as proof of natural expiry. Deferred callbacks also skip generations that expire before the refresh starts.

Group invalidation cancels pending refreshes and prevents older in-flight reads or refreshes from populating the replacement group generation. An in-flight request still returns its live result to its own caller. Cache failures during invalidation are logged without replacing the mutation's original response.

When several callers request stale data, the package deduplicates callbacks within the current request, command, or job. Use a shared cache store with atomic locks to coordinate refreshes across servers. Stores without locks still cache responses, but separate workers may refresh independently.

Synchronous cache misses use best-effort deduplication on stores with atomic locks. A competing caller waits up to one second, then rechecks the cache. If no usable response is available, it sends its own request, so slow calls can still overlap. Use a shared cache store to coordinate across processes or servers. Async requests, pools, and stores without atomic locks skip miss deduplication.

A short, nonblocking lock separately protects cache writes on stores that support locks. A response that completes later keeps its caller's result without replacing a response another caller has already cached.

Warnings include the hashed cache key when available, plus an HTTP status or exception class where relevant. Their log message includes the method, hostname, and path described in [Event logs](#event-logs). Query strings, credentials, response bodies, and exception messages are omitted.

## Request options and limits

### Client setup and middleware order

Use `withToken()`, `withBasicAuth()`, or `withHeaders()` for authentication. Ordinary options such as `acceptJson()` and `timeout()` can go before or after `remember()`.

Add custom Guzzle middleware **before** `remember()` so the cache key includes its changes. This applies to `withMiddleware()`, `withRequestMiddleware()`, and `withResponseMiddleware()`. Middleware placed before the cache layer still runs on cache hits.

Use Laravel's normal client stack. A client passed to `setClient()` controls its own middleware, so `remember()` cannot install itself into that client's handler stack.

### Requests that bypass caching

These requests skip caching even when a matching cached response already exists:

| Request feature | Reason |
| --- | --- |
| Custom `beforeSending()` callbacks | They can change the request after its key is calculated. |
| Guzzle `on_headers` callbacks | They must run on the live transport and can reject a response before its body is downloaded. |
| Guzzle `on_trailers` callbacks | They must run on the live transport and can reject a completed response after inspecting its trailers. |
| Middleware added after `remember()` | It can change credentials, the URL, or the body after the key is calculated. |
| Streaming or `sink()` downloads | They need to keep their streaming or file-writing behavior. |
| Multipart uploads | Upload streams and generated boundaries aren't suitable for reuse. |
| Unreadable, non-seekable, or already-consumed request bodies | The body can't be hashed and restored safely. |
| Custom cURL options, including digest and NTLM authentication | They can change behavior outside the request used for the cache key. |
| Custom `stream_context` options | They can override headers, bodies, or transport behavior outside the request used for the cache key. |

Grouped mutations retain invalidation even when uploads, custom callbacks, or these other options bypass response caching.

Unreadable or non-seekable response bodies are also returned without being cached.

### Response size limits

Response capture reads at most `max_response_bytes` plus one byte and restores the live stream's original position. Known oversized streams are skipped without reading them, and missing or inaccurate `Content-Length` headers cannot bypass the limit. An oversized deferred refresh keeps the previous generation until its original expiry.

The default 1 MB limit keeps cache buffering small and predictable across web requests, commands, and queue workers. Set `max_response_bytes` for your expected API payloads and cache backend's item limits. The serialized cache entry is larger than its body. The limit bounds the package's cache copy. Guzzle still downloads the full response, and application code can still read it normally. Use streaming or `sink()` for large transfers.

## Testing

### Test remembered requests in your application

`Http::fake()` works with remembered requests. A cache hit does not reach the fake transport, so `Http::assertSentCount()` counts live fetches, including deferred refreshes that you execute:

```php
Http::fake([
    'https://api.example.com/products' => Http::response(['products' => []]),
]);

$first = Http::remember(60)->get('https://api.example.com/products');
$second = Http::remember(60)->get('https://api.example.com/products');

Http::assertSentCount(1);
```

Use an isolated cache store for each test or clear the test cache during setup so a previous test's entries don't affect the result. Replacing an HTTP fake does not invalidate remembered responses.

To test a stale refresh, advance Laravel's test clock into the stale period and make a cached request. Invoke `app(Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke()` before asserting the refreshed result. Advance to the original lifetime when checking that a failed refresh preserves the expiry.

## License

Released under the [MIT license](LICENSE).
