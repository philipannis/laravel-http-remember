<?php

namespace PhilipAnnis\HttpRemember\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpRemember\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify configured exclusions through Laravel's public HTTP client API.
 */
final class IgnoredHeadersTest extends TestCase
{
    /**
     * The shared fake endpoint used by remembered header variants.
     */
    private const API_URL = 'https://api.example.test/products';

    /**
     * The instant used as the start of time-sensitive cache policies.
     */
    private const STARTED_AT = '2026-01-01 12:00:00';

    /**
     * The first response generation returned by fake APIs.
     */
    private const INITIAL_VERSION = 1;

    /**
     * The next response generation returned by fake APIs.
     */
    private const UPDATED_VERSION = 2;

    /**
     * The age at which a stale-while-revalidate response becomes stale.
     */
    private const FRESH_SECONDS = 30;

    /**
     * The total duration of short test cache policies.
     */
    private const LIFETIME_SECONDS = 60;

    /**
     * Confirm every default exclusion reuses responses without removing outgoing headers.
     *
     * @param  string  $header  A request header excluded by the default configuration.
     */
    #[DataProvider('defaultIgnoredHeaders')]
    public function test_default_ignored_headers_reuse_responses(string $header): void
    {
        // Return another generation if an ignored header incorrectly changes the cache identity.
        $this->fakeVersionSequence();

        // Change the header's value and casing before omitting it from a third request.
        $first = Http::withHeaders([$header => 'first-request'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $second = Http::withHeaders([strtoupper($header) => 'second-request'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $withoutHeader = Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm every request reuses the same response without removing the live header.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame($first->json(), $second->json());
        self::assertSame($first->json(), $withoutHeader->json());
        Http::assertSentCount(1);
        Http::assertSent(
            /**
             * Inspect the header received by the fake upstream handler.
             *
             * @param  Request  $request  The outgoing request that populated the cache.
             * @return bool Whether the original correlation header was sent unchanged.
             */
            static fn (Request $request): bool => $request->hasHeader($header, 'first-request'),
        );
    }

    /**
     * Provide the lowercase defaults, including the plain request-id alias.
     *
     * @return iterable<string, array{string}> Default exclusions keyed by their lowercase names.
     */
    public static function defaultIgnoredHeaders(): iterable
    {
        // Cover standard trace context and the generic request correlation names.
        yield 'traceparent' => ['traceparent'];
        yield 'tracestate' => ['tracestate'];
        yield 'request-id' => ['request-id'];
        yield 'x-request-id' => ['x-request-id'];
        yield 'x-correlation-id' => ['x-correlation-id'];

        // Cover provider-specific correlation and tracing names.
        yield 'client-request-id' => ['client-request-id'];
        yield 'x-ms-client-request-id' => ['x-ms-client-request-id'];
        yield 'x-cloud-trace-context' => ['x-cloud-trace-context'];
        yield 'x-amzn-trace-id' => ['x-amzn-trace-id'];
    }

    /**
     * Confirm custom configuration replaces defaults and matches names case-insensitively.
     */
    public function test_custom_ignored_headers_replace_the_defaults(): void
    {
        // Replace the default exclusions with one custom field using mixed casing.
        config(['http-remember.ignored_headers' => ['X-Diagnostic-ID']]);
        $this->fakeVersionSequence();

        // Change the ignored diagnostic field before changing the retained request ID.
        $first = Http::withHeaders(['x-diagnostic-id' => 'first', 'X-Request-ID' => 'request-one'])
            ->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $remembered = Http::withHeaders(['X-DIAGNOSTIC-ID' => 'second', 'X-Request-ID' => 'request-one'])
            ->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $differentRequestId = Http::withHeaders(['x-diagnostic-id' => 'second', 'X-Request-ID' => 'request-two'])
            ->remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm only the configured field is ignored when building the cache key.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame($first->json(), $remembered->json());
        self::assertSame(self::UPDATED_VERSION, $differentRequestId->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm an empty list restores request-ID-sensitive cache identity.
     */
    public function test_empty_configuration_includes_all_request_headers(): void
    {
        // Disable exclusions before creating the request builders.
        config(['http-remember.ignored_headers' => []]);
        $this->fakeVersionSequence();

        // Send two distinct request IDs and repeat the first one.
        $first = Http::withHeaders(['X-Request-ID' => 'first'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $second = Http::withHeaders(['X-Request-ID' => 'second'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $firstAgain = Http::withHeaders(['X-Request-ID' => 'first'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm each request ID retains its own remembered response.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::UPDATED_VERSION, $second->json('version'));
        self::assertSame($first->json(), $firstAgain->json());
        Http::assertSentCount(2);
    }

    /**
     * Confirm other headers keep response variants and similarly named fields distinct.
     *
     * @param  string  $header  A request header retained in the default cache identity.
     */
    #[DataProvider('includedHeaders')]
    public function test_unlisted_headers_keep_separate_entries(string $header): void
    {
        // Return a separate response for each representation selected by a retained header.
        $this->fakeVersionSequence();

        // Change only the unlisted header's value between requests.
        $first = Http::withHeaders([$header => 'first'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $second = Http::withHeaders([$header => 'second'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm default exclusions leave other header values in the fingerprint.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::UPDATED_VERSION, $second->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Provide response-selection fields and a name resembling a default exclusion.
     *
     * @return iterable<string, array{string}> Retained fields keyed by their header names.
     */
    public static function includedHeaders(): iterable
    {
        // Preserve exact field names instead of applying prefix-based exclusions.
        foreach (['Accept-Language', 'X-API-Version', 'X-Tenant-ID', 'X-Request-ID-Extra'] as $header) {
            yield $header => [$header];
        }
    }

    /**
     * Confirm exclusions apply within groups while groups keep their own partitions.
     */
    public function test_groups_remain_isolated_when_request_ids_differ(): void
    {
        // Return distinct generations for two tenant partitions.
        $this->fakeVersionSequence();

        // Change request IDs within one group before selecting another tenant's group.
        $first = Http::withHeaders(['X-Request-ID' => 'first'])
            ->remember(self::LIFETIME_SECONDS, group: ['tenant:1', 'products'])->get(self::API_URL);
        $remembered = Http::withHeaders(['X-Request-ID' => 'second'])
            ->remember(self::LIFETIME_SECONDS, group: ['products', 'tenant:1'])->get(self::API_URL);
        $otherGroup = Http::withHeaders(['X-Request-ID' => 'first'])
            ->remember(self::LIFETIME_SECONDS, group: ['tenant:2', 'products'])->get(self::API_URL);

        // Confirm only matching complete groups share a remembered generation.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame($first->json(), $remembered->json());
        self::assertSame(self::UPDATED_VERSION, $otherGroup->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm response predicates still control writes when request IDs share a cache key.
     */
    public function test_response_predicates_apply_with_ignored_headers(): void
    {
        // Return an application error before an eligible response at the same HTTP status.
        Http::fakeSequence()
            ->push(['errors' => ['Unavailable']], HttpStatus::HTTP_OK)
            ->push(['version' => self::UPDATED_VERSION], HttpStatus::HTTP_OK);
        $inspected = [];
        $pending = Http::remember(self::LIFETIME_SECONDS, cacheWhen:
            /**
             * Accept only application payloads without errors and record live inspections.
             *
             * @param  Response  $response  The live candidate considered for caching.
             * @return bool Whether the application payload can be remembered.
             */
            static function (Response $response) use (&$inspected): bool {
                // Record each live candidate while declining application errors.
                $inspected[] = $response->json();

                return empty($response->json('errors'));
            },
        );

        // Reject the first response without caching it under the shared fingerprint.
        $rejected = $pending->withHeaders(['X-Request-ID' => 'rejected'])->get(self::API_URL);
        self::assertSame(['Unavailable'], $rejected->json('errors'));
        self::assertSame([], Cache::store()->getStore()->all());

        // Accept the next live response and reuse it with another ignored request ID.
        $accepted = $pending->withHeaders(['X-Request-ID' => 'accepted'])->get(self::API_URL);
        $remembered = $pending->withHeaders(['X-Request-ID' => 'remembered'])->get(self::API_URL);
        self::assertSame(self::UPDATED_VERSION, $accepted->json('version'));
        self::assertSame($accepted->json(), $remembered->json());

        // Apply the predicate only to live candidates while keeping cache hits shared.
        self::assertSame([
            ['errors' => ['Unavailable']],
            ['version' => self::UPDATED_VERSION],
        ], $inspected);
        Http::assertSentCount(2);
    }

    /**
     * Confirm accepting predicates cannot cache vary responses that depend on ignored fields.
     */
    public function test_response_predicates_cannot_cache_variants_on_ignored_headers(): void
    {
        // Declare a dependency that the filtered request fingerprint cannot represent.
        $this->fakeVersionSequence(['Vary' => 'X-Request-ID']);
        $pending = Http::remember(self::LIFETIME_SECONDS, cacheWhen:
            /**
             * Approve every application payload while retaining built-in cache eligibility.
             *
             * @param  Response  $response  The live candidate considered for caching.
             * @return bool Whether the application permits the response to be remembered.
             */
            static fn (Response $response): bool => true,
        );

        // Send separate request IDs even though the application accepts both responses.
        $first = $pending->withHeaders(['X-Request-ID' => 'first'])->get(self::API_URL);
        $second = $pending->withHeaders(['X-Request-ID' => 'second'])->get(self::API_URL);

        // Keep both variants live because the predicate cannot override header dependencies.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::UPDATED_VERSION, $second->json('version'));
        self::assertSame([], Cache::store()->getStore()->all());
        Http::assertSentCount(2);
    }

    /**
     * Confirm changing IDs does not split stale entries or deferred refresh callbacks.
     */
    public function test_stale_requests_share_one_refresh_despite_different_ids(): void
    {
        // Freeze time and prepare the initial and refreshed response generations.
        $startedAt = Carbon::parse(self::STARTED_AT);
        Carbon::setTestNow($startedAt);
        $this->fakeVersionSequence();

        // Populate the response before changing request IDs at the stale boundary.
        Http::withHeaders(['X-Request-ID' => 'initial'])->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        Carbon::setTestNow($startedAt->copy()->addSeconds(self::FRESH_SECONDS));
        $firstStale = Http::withHeaders(['X-Request-ID' => 'stale-one'])->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);
        $secondStale = Http::withHeaders(['X-Request-ID' => 'stale-two'])->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Confirm stale callers share one cached response without an immediate transfer.
        self::assertSame(self::INITIAL_VERSION, $firstStale->json('version'));
        self::assertSame($firstStale->json(), $secondStale->json());
        Http::assertSentCount(1);

        // Keep one deferred refresh despite the different correlation values.
        $callbacks = app(DeferredCallbackCollection::class);
        self::assertCount(1, $callbacks);

        // Execute the refresh and reuse it with another request ID.
        $callbacks->invoke();
        $refreshed = Http::withHeaders(['X-Request-ID' => 'fresh'])->remember([self::FRESH_SECONDS, self::LIFETIME_SECONDS])->get(self::API_URL);

        // Confirm the replacement response was remembered by the single refresh.
        self::assertSame(self::UPDATED_VERSION, $refreshed->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm declared variations on excluded headers are never cached.
     *
     * @param  string|list<string>  $vary  The upstream header dependencies that prevent caching.
     */
    #[DataProvider('unsupportedVariations')]
    public function test_responses_varying_on_ignored_headers_are_not_cached(string|array $vary): void
    {
        // Declare response variants that cannot be represented by the filtered key.
        $this->fakeVersionSequence(['Vary' => $vary]);

        // Send different request IDs through the normal remembered request chain.
        $first = Http::withHeaders(['X-Request-ID' => 'first'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $second = Http::withHeaders(['X-Request-ID' => 'second'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm both callers receive live responses without creating a cache entry.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame(self::UPDATED_VERSION, $second->json('version'));
        self::assertSame([], Cache::store()->getStore()->all());
        Http::assertSentCount(2);
    }

    /**
     * Provide declarations that depend on ignored fields or unrestricted variation.
     *
     * @return iterable<string, array{string|list<string>}> vary values keyed by declaration format.
     */
    public static function unsupportedVariations(): iterable
    {
        // Cover casing, whitespace, and both supported HTTP field encodings.
        yield 'ignored header' => ['X-Request-ID'];
        yield 'comma-separated names' => ['Accept-Language, x-ReQuEsT-iD'];
        yield 'multiple fields' => [['Accept-Language', ' X-Request-ID ']];

        // Reject responses whose dependencies extend beyond named request headers.
        yield 'unrestricted variation' => ['*'];
    }

    /**
     * Confirm vary on retained headers still allows caching of each representation.
     */
    public function test_responses_varying_on_included_headers_are_cached(): void
    {
        // Declare a language dependency that remains represented by the cache key.
        $this->fakeVersionSequence(['Vary' => 'Accept-Language']);

        // Change request IDs within one language before requesting another language.
        $first = Http::withHeaders(['Accept-Language' => 'en', 'X-Request-ID' => 'first'])
            ->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $remembered = Http::withHeaders(['Accept-Language' => 'en', 'X-Request-ID' => 'second'])
            ->remember(self::LIFETIME_SECONDS)->get(self::API_URL);
        $otherLanguage = Http::withHeaders(['Accept-Language' => 'fr', 'X-Request-ID' => 'first'])
            ->remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm language variants stay distinct while diagnostic values are ignored.
        self::assertSame(self::INITIAL_VERSION, $first->json('version'));
        self::assertSame($first->json(), $remembered->json());
        self::assertSame(self::UPDATED_VERSION, $otherLanguage->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Confirm newly ignored vary headers cannot cause reuse of an existing entry.
     */
    public function test_config_changes_recheck_existing_response_variants(): void
    {
        // Store a response whose declared dependency is initially included in the key.
        config(['http-remember.ignored_headers' => []]);
        $this->fakeVersionSequence(['Vary' => 'X-Request-ID']);
        Http::remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Ignore that dependency before making a request whose filtered key would match.
        config(['http-remember.ignored_headers' => ['x-request-id']]);
        $response = Http::withHeaders(['X-Request-ID' => 'new-request'])->remember(self::LIFETIME_SECONDS)->get(self::API_URL);

        // Confirm the existing variant is rejected instead of replayed to the new caller.
        self::assertSame(self::UPDATED_VERSION, $response->json('version'));
        Http::assertSentCount(2);
    }

    /**
     * Configure a fake API with two successful response generations.
     *
     * @param  array<string, string|list<string>>  $headers  Upstream headers shared by both responses.
     */
    private function fakeVersionSequence(array $headers = []): void
    {
        // Return different bodies so cache hits and live requests are observable.
        Http::fakeSequence()
            ->push(['version' => self::INITIAL_VERSION], HttpStatus::HTTP_OK, $headers)
            ->push(['version' => self::UPDATED_VERSION], HttpStatus::HTTP_OK, $headers);
    }
}
