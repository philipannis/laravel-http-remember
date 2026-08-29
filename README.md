# Laravel HTTP Remember

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square)
![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat-square)
[![License](https://img.shields.io/badge/license-MIT-334155?style=flat-square)](LICENSE)

Add `remember()` to Laravel's HTTP client to cache successful API responses. It handles cache keys, response storage, and refreshes using your application's cache store. Requests without `remember()` work as usual.

## Installation

Requires PHP 8.2+ with Laravel 12, or PHP 8.3+ with Laravel 13.

```bash
composer config repositories.laravel-http-remember vcs https://github.com/philipannis/laravel-http-remember
composer require philipannis/laravel-http-remember:dev-main
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

All durations are in **seconds**. Both modes cache only successful **200–299 responses**.

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

The store name comes from your application's `config/cache.php`. Arguments you leave out use the package configuration. Calling `remember()` again before sending replaces the previous settings. A reused request builder keeps its options, so start a new `Http` chain for unrelated requests.

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

Changing the payload creates a separate entry. Any HTTP method can use `remember()`, but the operation must be safe to skip on a cache hit and repeat during a refresh. **Don't cache payments, order creation, or other operations with side effects.**

## Configuration

You only need to publish the config if you want to change the defaults for your application.

### 1. Publish the configuration

```bash
php artisan vendor:publish --tag=http-remember-config
```

### 2. Edit `config/http-remember.php`

```php
// Set defaults for remembered requests; all durations are in seconds.
return [
    // Keep responses fresh for 30 minutes and cached for 60 minutes in total.
    'ttl' => [1800, 3600],

    // Use the application's default cache store.
    'store' => null,

    // Limit network timeouts during deferred refreshes to 15 seconds.
    'refresh_timeout' => 15,
];
```

- `ttl` accepts the same values as `remember()`: use `3600` for a fixed hour, or `[1800, 3600]` for the default fresh/stale behavior.
- `store` is a configured cache store name. Leave it `null` to use your application's default, or set it to `'redis'` to use Redis.
- `refresh_timeout` must be a positive integer. It caps network timeouts for deferred refreshes only. Shorter timeouts stay unchanged; unlimited or longer timeouts use this cap. It doesn't change timeouts for requests that callers wait for.

Calling `remember()` without arguments uses these defaults. Passing a lifetime or store overrides that setting for one request.

### 3. Rebuild cached configuration when needed

If your deployment uses cached configuration, rebuild it after editing:

```bash
php artisan config:cache
```

Skip this step if you don't cache configuration.

## Cache keys and responses

Keys use the `http-remember:` prefix followed by a SHA-256 hash of the method, URL (including its query string but excluding its fragment), headers, body, protocol, relevant authentication and transport options, and cache lifetime settings.

Header names and their ordering are normalized. Header values, body bytes, and query ordering stay as sent. Different bearer tokens, basic credentials, cookie headers, bodies, or lifetimes get separate entries. URLs and credentials aren't written into keys as plaintext.

The cache stores arrays and scalars for the response's status, headers, body, protocol, reason phrase, and creation metadata. Each cache hit creates a normal `Illuminate\Http\Client\Response` with its own body stream. You can keep using `json()`, `body()`, `header()`, `successful()`, and `throw()`.

Async requests and pools keep their usual promise behavior. Add `remember()` to each request inside a pool.

## Refreshes and failures

Refreshes use Laravel's deferred functions. They run after the HTTP response is sent, or when the current Artisan command or queue job finishes. They also run after failures if Laravel reaches its normal deferred callback handling.

Keep `InvokeDeferredCallbacks` and the normal termination hooks enabled. Long-running commands wait until the command ends to refresh. The refresh still uses the current PHP worker, and a killed worker can't finish it; it isn't a durable background job.

| What happens | Result |
| --- | --- |
| The API returns a non-2xx response | Return it as usual without caching it. |
| A live request throws or times out | Keep the usual exception or rejected promise. |
| A deferred refresh fails | Keep the old entry until its original expiry and log the failure. |
| A cache read or write fails | Let the live request proceed and log the cache failure. |

Refreshes make one attempt through the remaining Guzzle handler stack. `retry()` still applies to foreground requests; deferred refreshes don't repeat that outer retry loop. Failed refreshes never extend the stale period.

When several callers request stale data, the package deduplicates callbacks within the current request, command, or job. Use a shared cache store with atomic locks to coordinate refreshes across servers. Stores without locks still cache responses, but separate workers may refresh independently. Cache misses aren't locked, so concurrent misses can make separate API calls, as with `Cache::remember()`.

Warnings include the hashed cache key when available, plus an HTTP status or exception class where relevant. They leave out URLs, credentials, response bodies, and exception messages.

## Request options and limits

Use `withToken()`, `withBasicAuth()`, or `withHeaders()` for authentication. Ordinary options such as `acceptJson()` and `timeout()` can go before or after `remember()`.

Add custom Guzzle middleware **before** `remember()` so the cache key includes its changes. These requests skip caching:

| Request feature | Reason |
| --- | --- |
| Custom `beforeSending()` callbacks | They can change the request after its key is calculated. |
| Middleware added after `remember()` | It can change credentials, the URL, or the body after the key is calculated. |
| Streaming or `sink()` downloads | They need to keep their streaming or file-writing behavior. |
| Multipart uploads | Upload streams and generated boundaries aren't suitable for reuse. |
| Unreadable, non-seekable, or already-consumed request bodies | The body can't be hashed and restored safely. |
| Custom cURL options, including digest and NTLM authentication | They can change behavior outside the request used for the cache key. |

Non-seekable response bodies are also returned without being cached. Use Laravel's normal client stack; a client passed to `setClient()` controls its own middleware.

Response bodies are buffered in memory when cached, so keep this for API responses of a manageable size rather than large file transfers. Guzzle handles redirects, and a redirecting URL may still make the initial redirect request. Use the API's final URL to avoid that extra call.

## License

Released under the [MIT license](LICENSE).
