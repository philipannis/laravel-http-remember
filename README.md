# Laravel HTTP Remember

![PHP](https://img.shields.io/badge/PHP-8.2%E2%80%938.5-777BB4?style=flat-square)
![Laravel](https://img.shields.io/badge/Laravel-12%E2%80%9313-FF2D20?style=flat-square)
[![License](https://img.shields.io/badge/license-MIT-334155?style=flat-square)](LICENSE)

Add `remember()` to Laravel's HTTP client to cache API reads using your application's cache store. By default, mutation methods always run live. Mark a POST search or GraphQL query with `operation: 'read'` when it is safe to cache. The package respects HTTP cache restrictions and keeps different credentials in separate entries.

Use `group` to invalidate related cached reads after a live mutation or with `invalidate: true`. Requests without `remember()` skip the package entirely.

## Contents

- [Installation](#installation)
- [Cache modes](#cache-modes)
- [HTTP cache rules](#http-cache-rules)
- [Per-request options](#per-request-options)
- [Group related requests](#group-related-requests)
- [Configuration](#configuration)
- [Cache keys and responses](#cache-keys-and-responses)
- [Event logs](#event-logs)
- [Refreshes and failures](#refreshes-and-failures)
- [Request options and limits](#request-options-and-limits)
- [Testing](#testing)
- [Migrating from V1](#migrating-from-v1)
- [License](#license)

## Installation

Requires PHP 8.2+ with Laravel 12, or PHP 8.3+ with Laravel 13.

```bash
composer require philipannis/laravel-http-remember
```

Then add `remember()` before sending a request.

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

The default allows **up to one fresh minute** and **up to five minutes of total use**. The API's headers can shorten those periods or prevent caching altogether. When stale use is allowed, callers receive the cached response while a refresh runs after the current request ends. The examples below use the same `Http` import.

Laravel discovers the service provider automatically. There are no package migrations, routes, commands, or scheduled jobs to set up. You don't need a queue worker or a published config file.

Your application's cache store must be configured and available. With the database cache driver, its cache-table migration must have run.

If you've disabled package discovery, add `PhilipAnnis\HttpRemember\HttpRememberServiceProvider::class` to the existing array in `bootstrap/providers.php`.

## Cache modes

All durations are in **seconds** and act as upper limits. Both modes require a successful 2xx response that passes the HTTP cache rules below. Partial responses (`206` or `Content-Range`) are excluded.

### Fixed lifetime

Pass a number of seconds to work much like `Cache::remember()`.

```php
// Cache an eligible response for up to one hour.
$response = Http::remember(3600)
    ->get('https://api.example.com/products');
```

The first request waits for the API. If the response is eligible, matching requests can reuse it for up to one hour. An earlier expiry supplied by the API takes precedence. After expiry, the next request waits for the API again and starts a new lifetime if its response is eligible.

Reading from the cache doesn't extend its lifetime. There are no deferred refreshes in this mode.

### Stale-while-revalidate (default)

Use this when a quick response matters more than having the very latest data. Pass `[fresh, lifetime]` to serve a cached response while a refresh is deferred.

```php
// Allow up to one fresh minute and five minutes of total use.
$response = Http::remember([60, 300])
    ->get('https://api.example.com/products');
```

**The second value is the total lifetime, including the fresh period.** When the API supplies no shorter limit, the defaults give you one fresh minute followed by up to four stale minutes. The table below describes that case. HTTP restrictions can shorten or remove the stale period.

| Cache age | What happens |
| --- | --- |
| No entry | Wait for the API and cache the response immediately if successful. |
| Under one minute | Return the cached response without refreshing. |
| One to under five minutes | Return the cached response and refresh after the current request, command, or job ends. |
| Five minutes or older | Wait for the API and replace the entry if successful. |

## HTTP cache rules

HTTP cache rules apply automatically to both cache modes. You do not need to publish configuration or add a `cacheWhen` callback to enable them. The configured `ttl_seconds` or per-request lifetime is an upper limit, and a response cannot override it with a longer lifetime.

| API response | What happens |
| --- | --- |
| `Cache-Control: no-store`, `no-cache`, or `private` | Return the response live and do not store it. Qualified forms such as `private="X-Secret"` are treated the same way. |
| `Set-Cookie` or `Set-Cookie2` | Return the response live and do not store its headers or body. |
| `max-age=N` | Limit use to the remaining origin freshness, after accounting for `Date` and `Age`. |
| `s-maxage=N` | Use this limit ahead of `max-age` or `Expires`, and require a live response when stale. |
| `Expires` | Use its deadline when neither `s-maxage` nor `max-age` is present. |
| `stale-while-revalidate=N` | Allow at most this many stale seconds, within the configured total lifetime. |
| `must-revalidate` or `proxy-revalidate` | Require a live response once the effective fresh period ends. Stale data is never served. |
| `Vary: *`, or `Vary` on an omitted request header | Do not store a response whose variants cannot be distinguished. |
| Malformed or duplicate lifetime directives, invalid dates or ages, or `must-understand` | Skip storage rather than guess how the response may be reused. |

The package handles `no-cache` conservatively. It makes a normal live request each time instead of storing the response and conditionally validating it.

When an API supplies no freshness headers, `remember()` provides the application's caching policy. When it does supply a freshness limit, stale use beyond that limit requires an explicit `stale-while-revalidate` allowance from the API. Revalidation requirements take precedence over that allowance. Neither the API nor a request's `max-stale` directive can extend your configured total lifetime.

The following examples use `remember([300, 900])`.

| Response headers | Fresh for at most | Usable for at most |
| --- | --- | --- |
| No cache headers | 300 seconds | 900 seconds |
| `max-age=120` | 120 seconds | 120 seconds |
| `max-age=120, stale-while-revalidate=60` | 120 seconds | 180 seconds |
| `max-age=600, must-revalidate` | 300 seconds | 300 seconds |

Those figures assume a newly generated response. Upstream `Age`, an older `Date`, and time spent receiving the response reduce the remaining origin lifetime. Cache hits expose the current age in their `Age` header.

Authenticated requests remain cacheable under a credential-specific key unless the response forbids caching. Bearer tokens, basic credentials, and existing session cookies keep their separate entries. A `public` directive never causes different credentials to share an entry. Cached response bodies are ordinary data in your application's cache store, so give that store the same access protection as the API data itself.

### Request cache controls

Request `no-store`, `no-cache`, and `max-age=0` bypass cache reads, writes, and deferred refreshes. `Pragma: no-cache` does the same when the request has no `Cache-Control` field. These requests leave previously stored entries alone.

Positive `max-age` and `min-fresh` requirements are checked against existing entries and fetch a live response when necessary. `max-stale` can narrow the permitted stale period. These controls share the normal representation's cache key, so adding them does not create a separate copy of the same response.

`only-if-cached` returns an eligible cached response or HTTP 504. It never starts a foreground request or a deferred refresh, including when the cache is unavailable or another option would normally bypass caching.

Conditional request headers (`If-Match`, `If-None-Match`, `If-Modified-Since`, `If-Unmodified-Since`, or `If-Range`) and `Range` bypass caching. The normal HTTP client and origin handle their semantics.

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

A fixed lifetime must be a positive integer. A fresh/stale pair must contain exactly two integers with `0 <= fresh < lifetime`. For example, `[0, 300]` makes every eligible cache hit stale and permits up to five minutes of total use, subject to the API's restrictions. Invalid values throw `InvalidArgumentException` immediately.

The store name comes from your application's `config/cache.php`. Omitting a lifetime or store, or passing `null`, uses the package configuration. Blank store names are rejected. Passing `store: null` uses the configured package store, which may itself select the application's default.

Policy settings are captured when you call `remember()`. Calling it again before sending replaces the previous settings. Invalid settings are rejected before the previous valid policy is replaced. A reused request builder keeps its options, so start a new `Http` chain for unrelated requests.

### Select responses to cache

Use `cacheWhen` when an API returns HTTP 200 for application errors, such as GraphQL errors, or when other response details determine whether it should be cached. The callback receives an `Illuminate\Http\Client\Response` and must return a boolean. Use its usual `json()`, `body()`, `header()`, and `status()` methods to inspect the API's result.

```php
use Illuminate\Http\Client\Response;

// Exclude GraphQL errors even when the API returns HTTP 200.
$products = Http::remember(600, operation: 'read', cacheWhen:
    fn (Response $response): bool => empty($response->json('errors')),
)->post('https://api.example.com/graphql', [
    'query' => 'query { products { id name } }',
])->json();
```

The predicate runs before storing eligible live responses and deferred refreshes. Cache hits reuse the stored response without invoking the callback. Returning `false` skips storing the live response while preserving its normal result for the caller. A predicate-rejected deferred refresh keeps the previous entry until its original expiry. A new HTTP restriction, such as `no-store` or `Set-Cookie`, removes the old entry instead.

Predicates receive the live response's original headers and an independent body stream. Reading, closing, or changing that copy does not alter the caller's response or the body stored in the cache. Transfer statistics and cookie jars are not populated on the inspection copy.

Use a predicate that can safely run during live requests and deferred refreshes. Callback exceptions or non-boolean return values reject caching and log the hashed key and exception class. Live responses still reach their caller. The callback can only narrow the built-in eligibility rules. HTTP cache restrictions, session-issuing responses, partial responses, and requests that bypass caching are checked before the predicate. Grouped mutations keep their normal invalidation behavior without invoking the predicate.

`cacheWhen` accepts any PHP callable, including closures and invokable objects. Omitting it or passing `null` uses the usual successful-response policy. Calling `remember()` again replaces the predicate along with the other settings. Predicates stay on the request builder and are never serialized into the cache or included in its key. Existing entries keep their original lifetime, so invalidate the relevant group or remove the cached entries when changing a rule that should also apply to previously stored responses.

### Other HTTP methods

GET, HEAD, OPTIONS, and TRACE are treated as reads by default. POST, PUT, PATCH, DELETE, and unfamiliar methods run live and are never cached or deferred unless you explicitly pass `operation: 'read'`. This applies whether or not you select a group.

For a POST that only reads data, such as a search, declare that intent. The request body is part of its cache key.

```php
// Cache each search separately based on its payload.
$results = Http::withToken(config('services.catalog.token'))
    ->remember([300, 900], operation: 'read')
    ->post('https://api.example.com/search', [
        'query' => 'linen',
    ])
    ->json();
```

Changing the payload creates a separate entry. `operation: 'read'` means the operation is safe to skip on a cache hit and repeat during a refresh. **Never use it for payments, order creation, GraphQL mutations, or other operations with side effects.** The package does not inspect a GraphQL document or request payload to decide whether it is a query. Use a group when a live mutation should invalidate related reads.

## Group related requests

Give related requests the same `group`. Reads are cached. Mutation methods run live and invalidate the group's cached reads after a non-error HTTP response.

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

Order does not matter. `['tenant:42', 'products']` and `['products', 'tenant:42']` identify the same group. Matching uses the **complete array**, so `['products']` is a different group. Include a tenant or other scope when changes should affect only that scope.

Group state lives in the selected cache store. Use the same store for related reads and mutations. The same group in a separate store has its own generation. Other groups and ungrouped responses keep their existing lifetimes.

GET, HEAD, OPTIONS, and TRACE are treated as reads. Other methods, including POST, PUT, PATCH, and DELETE, are treated as mutations unless explicitly marked `operation: 'read'`. Mutations are never served from the cache, stored as cached responses, or repeated during deferred refreshes. A 2xx or 3xx response invalidates the group. A 4xx, 5xx, or connection failure preserves it. Redirects invalidate before their follow-up requests run.

### Invalidate a group directly

Pass `invalidate: true` to `remember()` when a webhook, local update, or other application action should invalidate related reads.

```php
// Invalidate the exact group immediately without sending an HTTP request.
Http::remember(group: ['tenant:42', 'products'], invalidate: true);

// Invalidate the same group in an explicitly selected cache store.
Http::remember(store: 'redis', group: ['tenant:42', 'products'], invalidate: true);
```

Invalidation runs once when `remember()` is called. It affects every cached URL, payload, credential, and lifetime variant in that exact group and selected store. Other groups and ungrouped responses keep their existing lifetimes. Pending refreshes are cancelled, and older in-flight reads or refreshes cannot populate the replacement group generation.

The call still returns a normal configured request builder, so you can invalidate and fetch an updated response in one chain.

```php
// Invalidate once, then fetch and cache an updated list for ten minutes.
$products = Http::remember(600, group: ['tenant:42', 'products'], invalidate: true)
    ->get('https://api.example.com/products');
```

Reusing that builder keeps ordinary caching behavior. It does not invalidate again on each request. Call `remember(..., invalidate: true)` again when the group changes.

`invalidate` defaults to `false`. Direct invalidation requires a non-empty `group`. Omitting it, passing `null`, or passing an empty list throws `InvalidArgumentException`. The same group validation, exact matching, and store defaults apply. Cache failures are logged through the existing `failed` event with `operation: 'invalidate'` and preserve the usual builder return value.

### Cache POST searches and other reads

Use `operation: 'read'` when a method normally associated with writes performs a read. This works for POST searches, reports, RPC calls, GraphQL queries, and other APIs.

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

You do not need `operation: 'read'` for ordinary GET requests. You do need it for every cacheable POST read, including requests without a group. Use it only for operations that are safe to skip on a cache hit and repeat during a refresh. For actual writes, including GraphQL mutations, leave it out so grouped mutation methods run live and invalidate the group. The package does not inspect payloads to determine the operation's intent.

### Reuse a configured client

Call `remember()` once on a shared request builder to avoid repeating the group.

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
| `remember()` with no group | Cache eligible reads and send mutation methods live. |
| `remember(group: [...])` | Cache reads and invalidate the group after live mutations. |
| `remember(operation: 'read')`, with or without a group | Allow an eligible read using any HTTP method. |
| `remember(group: [...], invalidate: true)` | Invalidate the group immediately, then configure ordinary remembered requests. |

Omit `operation` to use automatic method detection for grouped requests. A GET remains a cacheable read, while POST, PUT, PATCH, and DELETE run live and invalidate the group. When supplied, `operation` accepts only the exact string `'read'`, which enables caching for any eligible method. Other strings and explicit `null` throw `InvalidArgumentException`. Unsupported argument types are also rejected. Operation settings retain the response eligibility and bypass rules below.

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
return [

    // Use 3600 for fixed expiry or [60, 300] for stale-while-revalidate.
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
```

- `ttl_seconds` accepts the same values as `remember()`. Use `3600` for a fixed hour, or `[60, 300]` for the default fresh/stale behavior. Both values in a pair are measured in seconds.
- `store` is a configured cache store name. Leave it `null` to use your application's default, or set it to `'redis'` to use Redis.
- `refresh_timeout_seconds` must be a positive integer measured in seconds. It caps Guzzle's `timeout`, `connect_timeout`, and `read_timeout` options for deferred refreshes only. Shorter timeouts stay unchanged. Unlimited or longer timeouts use this cap. It doesn't change timeouts for requests that callers wait for.
- `refresh_cooldown_seconds` must be a positive integer and defaults to **15 seconds**. Failed deferred HTTP responses and transport exceptions pause later refreshes for at least this long. A valid `Retry-After` can lengthen that delay. Foreground requests and the original response lifetime stay unchanged.
- `max_request_bytes` must be a positive integer and defaults to **1 MB (1,000,000 bytes)**. Request bodies exactly at the limit remain eligible. Larger bodies go live without reading or writing cached responses or scheduling deferred refreshes, even when a matching cached response exists. The limit applies to actual body bytes and is independent of `max_response_bytes`. It does not restrict live uploads or grouped mutation invalidation.
- `max_response_bytes` must be a positive integer and defaults to **1 MB (1,000,000 bytes)**. It limits the response body copied into the cache, including during deferred refreshes. Bodies exactly at the limit are eligible. Larger bodies pass through normally without being cached. Existing entries keep their original expiry when the limit changes. The limit applies to the actual body stream, after decompression when enabled, and excludes headers and cache serialization overhead.
- `event_logs.enabled` defaults to `true` when `APP_ENV` is `local`, `development`, or `dev`, and `false` otherwise. Set it explicitly to enable or disable event log output in any environment. Laravel listeners still receive event logs when file output is disabled.
- `event_logs.channel` defaults to `'http-remember'`. The package supplies a daily log at `storage/logs/http-remember-YYYY-MM-DD.log`, with a `debug` level and 7 days of retention. Define `logging.channels.http-remember` to customize that channel, choose another configured channel, or use `null` for the application's default. Existing cache failure warnings use the selected channel even when routine event log output is disabled.
- `event_logs.redact` defaults to `null`. Set a `callable(string): string` to mask sensitive values in log messages. See [Redact log messages](#redact-log-messages).
- `ignored_headers` is a positional list of valid HTTP header names excluded from cache keys. The defaults cover common tracing and correlation headers, so changing a request ID does not create another cache entry. Names match case-insensitively and duplicates are removed. Blank names, names containing whitespace, associative arrays, and non-string values are rejected immediately. Replace the list to customize it, or set it to `[]` to include every other request header. `Cache-Control` and `Pragma` always control cache use and stay outside the key. All headers are still sent upstream normally.

Calling `remember()` without arguments uses these defaults. Passing a lifetime or store overrides that setting for one request.

### 3. Rebuild cached configuration when needed

If your deployment uses cached configuration, rebuild it after editing.

```bash
php artisan config:cache
```

Skip this step if you don't cache configuration.

## Cache keys and responses

### Request identity

Response keys use the `http-remember:v2:` prefix followed by a SHA-256 hash of the method, URL (including its query string but excluding its fragment), representation headers, body, protocol, relevant authentication and transport options, and cache lifetime settings. Request `Cache-Control` and `Pragma` fields control cache use and are omitted from that identity, along with the configured `ignored_headers`.

The transport options included in the key are `auth`, `cert`, `ssl_key`, `verify`, `decode_content`, and `proxy`. Network timeouts and delays don't create separate cache entries.

Header names and their ordering are normalized. Included header values, body bytes, and query ordering stay as sent. Equivalent query strings or JSON bodies with different ordering can therefore create distinct entries. The default exclusions preserve separate entries for different bearer tokens, basic credentials, `Cookie` headers, bodies, or lifetimes. URLs and credentials aren't written into keys as plaintext.

Only exclude headers that do not affect the response data. Authentication, tenant, language, API-version, and idempotency headers remain in the key by default. Responses with `Vary: *` or a `Vary` field naming an ignored header, `Cache-Control`, or `Pragma` are returned live without being cached or reused, because their variants cannot be distinguished by the filtered key. Other `Vary` headers remain cacheable because their request headers are still included.

### Group generations and invalidation

Grouped keys also include the SHA-256 hash of the sorted group array and its current generation. Group values are not written into keys as plaintext. Requests without a group use the same filtered request fingerprint without group fields. `operation` does not change the identity of a cacheable response.

Invalidation replaces one shared generation record instead of scanning response keys, so groups work without cache tags or atomic locks. Generation records use the `http-remember:group:` prefix followed by the group hash. Old responses become unreachable and expire at their existing backend lifetimes. The store retains one small generation record per group until it is replaced or removed. Missing metadata creates a fresh generation and never restores older cached responses.

To remove one cached response manually, take the full `cache_key` from a `hit` or `stored` event log and call `Cache::store($store)->forget($key)` with that key and store. The package uses Laravel's cache API for manual removal.

### Response handling

The cache stores arrays and scalars for the response's status, headers, body, protocol, reason phrase, and creation metadata. Each cache hit creates a normal `Illuminate\Http\Client\Response` with its own body stream. You can keep using `json()`, `body()`, `header()`, `successful()`, and `throw()`.

Malformed cache payloads are treated as misses. Response headers and bodies are stored as ordinary cache data, even though the keys are hashed.

The `remember()` policy sets the maximum lifetime. The [HTTP cache rules](#http-cache-rules) can shorten it or prohibit storage. Cache hits update the `Age` header to include both the response's upstream age and its time in this cache. `ETag` and `Last-Modified` are retained, but the package does not generate conditional validation requests.

Responses containing `Set-Cookie` or `Set-Cookie2` are returned live and never stored. Existing request cookies remain part of the cache key, so established sessions can reuse their own eligible responses. Use ordinary live requests for authentication and session creation, including APIs that return session credentials only in the body.

Cache hits still emit Laravel's `RequestSending` and `ResponseReceived` events and retain the sending builder's request attributes and cookie jar. They have no network transfer statistics. `handlerStats()` returns `[]` and `effectiveUri()` returns `null`. Guzzle's `on_stats` callback runs only for live transfers.

### Async requests and pools

Async requests keep their usual promise behavior, including on cache hits.

```php
$promise = Http::async()->remember(600)
    ->get('https://api.example.com/products');

$products = $promise->wait()->json();
```

Add `remember()` and any authentication or other options to each request inside a pool.

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

Event logs use Laravel's standard log format. The message shows `[METHOD] hostname/path`, with the scheme, leading `www.`, port, credentials, query parameters, and fragment omitted for quick skimming. The request path keeps its original case and encoding. A request without a path shows `/`. The event type and all other fields are passed as a separate context array. The dedicated channel uses Laravel's normal formatter.

```text
[2026-01-01 12:00:10] local.DEBUG: [GET] api.example.com/products {"event":"hit","cache_key":"http-remember:v2:...","store":"redis","group_hash":null,"reason":"stale","age":10.0,"status":200,"operation":null,"exception":null,"retry_at":null}
```

Failure warnings use the same request message and structured context at the `warning` level. They remain enabled when routine event log output is disabled, and a failed operation produces one warning rather than duplicate debug and warning records. Application channel overrides retain their own formatting.

Direct group invalidation uses the message `HTTP remember group invalidation.` or `HTTP remember group invalidation failed.` with the selected store, hashed group, and `operation: 'invalidate'`. Its request key and HTTP status are `null` because it sends no HTTP request.

### Redact log messages

Set `event_logs.redact` when your API puts sensitive values in URL paths. The callback receives the existing log message, such as `[GET] api.example.com/tokens/secret`, and returns the masked message. Query strings, URL credentials, and fragments have already been omitted.

For example, add a static method to your application.

```php
namespace App\Support;

final class HttpLogRedactor
{
    public static function redact(string $message): string
    {
        return preg_replace('~(/tokens/)[^/\s]+~', '$1[redacted]', $message);
    }
}
```

Then set this entry inside `event_logs` in `config/http-remember.php`.

```php
'redact' => [App\Support\HttpLogRedactor::class, 'redact'],
```

Static callable arrays work with Laravel's cached configuration. Closures can also be supplied programmatically. The callback runs only for records being written, including failure warnings, and also receives direct group invalidation messages. It changes only the log message. Requests, cache keys, and structured event context remain unchanged. If it throws, is not callable, or returns a non-string value, that log record is skipped without logging the original message. Event listeners still receive the structured event.

### Listen for event logs

The package dispatches an immutable `HttpRememberEventLog` through Laravel for each decision, independently of log-channel output.

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
| `miss` | No usable cached response was selected. `only_if_cached` means HTTP 504 was returned without a live fetch. |
| `bypass` | A request runs live because of its transport options, hooks, a cache read failure, HTTP cache restrictions, or mutation intent. |
| `stored` | The cache accepted a live response or refreshed replacement. |
| `not_stored` | A response was not persisted. `reason` identifies the eligibility, generation, lock, or write outcome. |
| `refresh_scheduled` | A stale hit requested deferred work. |
| `refresh_started` | Deferred work passed its generation checks and is about to contact the API. |
| `refresh_succeeded` | A deferred refresh stored its replacement or retired a redirected source. |
| `refresh_skipped` | A refresh was cancelled, its cooldown was active, its lock was unavailable, or its response could not be stored. |
| `refresh_failed` | A refresh failed during scheduling, transport, upstream handling, or cache persistence. |
| `invalidated` | Direct invalidation or a successful grouped mutation replaced the group's generation. |
| `failed` | A foreground request or cache operation failed. `operation` identifies the failed operation. |

Each event log exposes `event`, `cacheKey`, `store`, `groupHash`, `reason`, `age`, `status`, `operation`, `exception`, and `retryAt`. Unavailable fields are `null`. Cooldown skips include `retryAt` as the next permitted refresh's Unix timestamp. `toArray()` uses `cache_key`, `group_hash`, and `retry_at` for log context. Keys and groups are hashed. Records omit query strings, fragments, credentials, headers, bodies, and exception messages. The method, hostname, and path appear only in the log message. Enabled event logs use the `debug` log level.

Request bypass reasons include `protocols`, `request_hooks`, `on_headers`, `on_trailers`, `stream`, `sink`, `curl`, `stream_context`, `multipart`, `request_body`, `request_size`, `request_cache_control`, `request_conditional`, `mutation`, and `cache_read_failed`. Miss reasons include `missing`, `invalid_entry`, `expired`, `request_freshness`, `only_if_cached`, `group_invalidated`, and `vary`. Storage reasons include `response_status`, `response_stream`, `response_size`, `response_predicate`, `response_cache_control`, `response_cookie`, `response_partial`, `response_expired`, `vary`, `group_invalidated`, `generation_changed`, `write_lock_unavailable`, and `cache_write_failed`. Refresh cancellation can also report `expired`, `request_hooks`, `request_size`, `refresh_lock_unavailable`, or `cooldown`.

Stale hits outside cooldown emit `refresh_scheduled` unless the request is `only-if-cached`, while pending refreshes are replaced by name and Laravel deduplicates actual execution. Hits during cooldown emit `refresh_skipped` instead. Count `refresh_started` and the final refresh outcome to measure refresh work. Listener and log-channel exceptions are contained so they do not replace HTTP responses, change cache lifetimes, or interfere with invalidation.

## Refreshes and failures

### When refreshes run

Refreshes use Laravel's deferred functions. They run after the HTTP response is sent, or when the current Artisan command or queue job finishes. They also run after failures if Laravel reaches its normal deferred callback handling.

Keep `InvokeDeferredCallbacks` and the normal termination hooks enabled. Long-running commands wait until the command ends to refresh. Deferred work runs in the current PHP worker and depends on that worker reaching termination. It has no durable job storage or queue retry policy.

Refreshes replay the prepared request from the stale hit with an independent copy of its complete body. They reuse its URL, headers, credentials, and selected cache store. Credentials are not fetched again from application configuration. Foreground `delay` options are removed from the refresh.

Deferred refreshes follow your request's `allow_redirects` settings. This includes the redirect limit, allowed protocols, method handling, and callbacks. Each refresh starts counting redirects from zero, and all requests in the chain share one timeout budget. Guzzle applies its usual rules for cookies and credentials when following redirects. Refreshes use a separate copy of your cookie jar, so they don't change your cookies.

If the destination's response can be cached, it gets its own cache entry based on its URL, headers, and credentials. The old source response is then removed unless another request has replaced or invalidated it. Later calls to the source still follow its live redirect, but can reuse the cached destination response. If the redirect fails or an application predicate rejects the destination, the old source keeps its original expiry. A destination that prohibits caching or issues cookies retires the old source too, so later callers obtain their own live response. Invalidation during the refresh prevents it from storing a response.

### Failure behavior

| What happens | Result |
| --- | --- |
| The API returns a non-2xx response | Return it as usual without caching it. |
| The response predicate rejects a live response | Return it as usual without caching it. |
| The response predicate throws or returns a non-boolean value | Skip caching and log the predicate failure. |
| A live request throws or times out | Keep the usual exception or rejected promise. |
| A deferred refresh fails | Keep the old entry until its effective expiry and log the failure. |
| A refresh introduces an HTTP cache restriction, a session cookie, or an unusable `Vary` field | Remove the observed old entry without removing a concurrent replacement. |
| An `only-if-cached` request has no usable entry | Return HTTP 504 without a foreground or deferred transfer. |
| A cache read or write fails | Let the live request proceed and log the cache failure. |

Refreshes make one attempt and follow any redirects allowed by your request settings. `retry()` still applies to foreground requests. Deferred refreshes don't repeat that outer retry loop. Failed refreshes never extend the stale period.

### Refresh cooldowns

A deferred refresh that returns a non-2xx response or throws a transport exception starts a fixed cooldown. The default is 15 seconds, configured by `refresh_cooldown_seconds`. Stale callers keep receiving the existing response, but they do not schedule another refresh until the cooldown ends. A later stale request triggers the next attempt without sleeps or automatic retries.

For failed HTTP responses, `Retry-After` can extend the cooldown beyond the configured minimum. Both non-negative integer seconds and HTTP dates are supported. Missing, malformed, negative, or past values use the configured minimum. Predicate rejections, oversized responses, lock contention, and invalidation do not start cooldowns.

Cooldowns live in the selected cache store and belong to one response generation. Replacement responses and invalidated groups do not inherit earlier failures. Stores with atomic locks recheck the deadline under the existing refresh lock, so callbacks already queued by another worker also respect the cooldown. Stores without locks share deadlines but cannot coordinate simultaneous attempts. Cooldown metadata failures are contained and reported through the existing event logs.

Cooldowns never extend the response's lifetime. Their deadlines are capped at the original hard expiry, and their metadata expires automatically. After hard expiry, requests fetch live data normally even if the server requested a longer wait. Foreground errors retain their normal responses or exceptions. Expired cached responses are never used as a fallback.

### Expiry and concurrent requests

When a response has an effective stale period, the cache store retains it for `refresh_timeout_seconds` plus 10 seconds beyond its logical lifetime. This gives a refresh that starts before expiry time to finish while its original generation can still be checked. Requests always enforce the shorter of the configured and origin-imposed lifetimes, so retained expired responses are never served. Responses without an effective stale period expire in the store at their effective lifetime, rounded up to whole seconds. Reads still enforce the exact deadline.

A refresh replaces only the generation it started with. Removing that cache entry or flushing the store before the refresh's final generation check cancels its write, even when the original lifetime has elapsed. A missing generation is never treated as proof of natural expiry. Deferred callbacks also skip generations that expire before the refresh starts.

Group invalidation cancels pending refreshes and prevents older in-flight reads or refreshes from populating the replacement group generation. An in-flight request still returns its live result to its own caller. Cache failures during invalidation are logged without replacing the mutation's original response.

When several callers request stale data, the package replaces pending refreshes within the current request, command, or job so duplicate request-body copies do not accumulate until termination. The latest successfully prepared request supplies the refresh body and settings. If preparing its replacement fails, the earlier refresh remains queued. Laravel deduplicates execution by callback name. Use a shared cache store with atomic locks to coordinate refreshes across servers. Stores without locks still cache responses, but separate workers may refresh independently.

Synchronous cache misses use best-effort deduplication on stores with atomic locks. A competing caller waits up to one second, then rechecks the cache. If no usable response is available, it sends its own request, so slow calls can still overlap. Use a shared cache store to coordinate across processes or servers. Async requests, pools, and stores without atomic locks skip miss deduplication.

A short, nonblocking lock separately protects cache writes on stores that support locks. A response that completes later keeps its caller's result without replacing a response another caller has already cached.

Warnings include the hashed cache key when available, plus an HTTP status or exception class where relevant. Their log message includes the method, hostname, and path described in [Event logs](#event-logs). Query strings, credentials, response bodies, and exception messages are omitted.

## Request options and limits

### Client setup and middleware order

Use `withToken()`, `withBasicAuth()`, or `withHeaders()` for authentication. Ordinary options such as `acceptJson()` and `timeout()` can go before or after `remember()`.

Add custom Guzzle middleware **before** `remember()` so the cache key includes its changes. This applies to `withMiddleware()`, `withRequestMiddleware()`, and `withResponseMiddleware()`. Middleware placed before the cache layer still runs on cache hits.

Use Laravel's normal client stack. A client passed to `setClient()` controls its own middleware, so `remember()` cannot install itself into that client's handler stack.

The Guzzle `protocols` option is checked before cache lookup and at every redirect destination, including deferred refreshes. For example, `withOptions(['protocols' => ['https']])` cannot reuse a cached HTTP response. Disallowed schemes and malformed protocol lists bypass caching so the live handler retains its normal validation behavior. When `only-if-cached` forbids a live transfer, these requests return HTTP 504 instead. Allowed schemes continue sharing the ordinary response cache entry. Omitting `protocols` or setting it to `null` permits HTTP and HTTPS.

### Requests that bypass caching

These requests skip caching even when a matching cached response already exists.

| Request feature | Reason |
| --- | --- |
| Mutation methods without `operation: 'read'` | They must execute every time and must never be repeated by a refresh. |
| A scheme excluded by `protocols`, or a malformed protocol list | Cached responses must not bypass the live handler's protocol restrictions. |
| Request `no-store`, `no-cache`, `max-age=0`, or legacy `Pragma: no-cache` | The caller requires a live transfer. |
| Conditional or range request headers | The origin must evaluate the caller's validators and requested byte range. |
| Custom `beforeSending()` callbacks | They can change the request after its key is calculated. |
| Guzzle `on_headers` callbacks | They must run on the live transport and can reject a response before its body is downloaded. |
| Guzzle `on_trailers` callbacks | They must run on the live transport and can reject a completed response after inspecting its trailers. |
| Middleware added after `remember()` | It can change credentials, the URL, or the body after the key is calculated. |
| Streaming or `sink()` downloads | They need to keep their streaming or file-writing behavior. |
| Multipart uploads | Upload streams and generated boundaries aren't suitable for reuse. |
| Unreadable, non-seekable, or already-consumed request bodies | The body can't be hashed and restored safely. |
| Request bodies larger than `max_request_bytes` | Hashing and deferred upload copies must stay within the request size budget. |
| Custom cURL options, including digest and NTLM authentication | They can change behavior outside the request used for the cache key. |
| Custom `stream_context` options | They can override headers, bodies, or transport behavior outside the request used for the cache key. |

Grouped mutations retain invalidation even when uploads, custom callbacks, or these other options bypass response caching.

Unreadable or non-seekable response bodies are also returned without being cached.

### Request size limits

`max_request_bytes` bounds the request bodies eligible for caching in both fixed and stale-while-revalidate modes. Hashing reads bounded chunks, stops after at most the limit plus one probe byte, and restores the original stream position before the live transfer. Known oversized bodies bypass caching without being read. Missing or inaccurate stream sizes and `Content-Length` headers cannot bypass the limit.

Deferred refreshes copy eligible uploads in chunks into an independent temporary stream that can spill to disk. They recheck the limit while copying in case request events changed the body after fingerprinting. An oversized copy cancels the refresh while preserving the cached response and the original stream position.

Configure request and response budgets separately. A large search payload may return a small result, while a small query may return a large response. Raising the request budget permits more hashing and temporary storage. Lowering it bypasses existing entries for oversized requests without removing those entries or changing their expiry. The setting is captured when `remember()` is called, and it does not change the cache key for requests that remain eligible.

### Response size limits

Response capture reads at most `max_response_bytes` plus one byte and restores the live stream's original position. Known oversized streams are skipped without reading them, and missing or inaccurate `Content-Length` headers cannot bypass the limit. An oversized deferred refresh keeps the previous generation until its original expiry.

The default 1 MB limit keeps cache buffering small and predictable across web requests, commands, and queue workers. Set `max_response_bytes` for your expected API payloads and cache backend's item limits. The serialized cache entry is larger than its body. The limit bounds the package's cache copy. Guzzle still downloads the full response, and application code can still read it normally. Use streaming or `sink()` for large transfers.

## Testing

### Test remembered requests in your application

`Http::fake()` works with remembered requests. A cache hit does not reach the fake transport, so `Http::assertSentCount()` counts live fetches, including deferred refreshes that you execute.

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

## Migrating from V1

The default `ttl_seconds` is now `[60, 300]`, allowing up to one fresh minute and five minutes of total use. Published configuration must use `ttl_seconds`, `refresh_timeout_seconds`, and `refresh_cooldown_seconds` for durations. Rename the corresponding keys and update any `config()` calls that use their previous names.

Review any `remember()` calls used with POST, PUT, PATCH, DELETE, or custom methods. They now run live unless you add `operation: 'read'`. Add that option only to searches, GraphQL queries, and other operations that are safe to skip and repeat. Leave it off real mutations. Grouped mutations still invalidate related reads after success.

HTTP restrictions now apply automatically. APIs returning `no-store`, `no-cache`, `private`, or session cookies will no longer populate the cache, and origin freshness limits can shorten the lifetime selected by `ttl_seconds` or `remember()`. Use the event logs to see why a response was not stored.

Response keys use a new namespace, so entries created under the previous permissive policy are not reused. Old entries expire normally, so there is no need to flush the cache. Group identities remain unchanged.

## License

Released under the [MIT license](LICENSE).
