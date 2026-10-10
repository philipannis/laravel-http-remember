<?php

namespace PhilipAnnis\HttpRemember\Tests\Unit;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use PhilipAnnis\HttpRemember\HttpRememberOptions;
use PhilipAnnis\HttpRemember\HttpRememberResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verify the serializable representation of remembered responses.
 */
final class ResponseTest extends TestCase
{
    /**
     * The instant used as the response capture time.
     */
    private const STORED_AT = '2026-01-01 12:00:00';

    /**
     * The age threshold used by expiration assertions.
     */
    private const AGE_THRESHOLD_SECONDS = 60;

    /**
     * The small byte budget used to exercise response capture boundaries.
     */
    private const MAX_RESPONSE_BYTES = 16;

    /**
     * Restore the global clock after every response test.
     */
    protected function tearDown(): void
    {
        // Prevent response timestamps from affecting another test.
        Carbon::setTestNow();

        // Complete PHPUnit's normal cleanup.
        parent::tearDown();
    }

    /**
     * Confirm a successful response can be captured and reconstructed exactly.
     */
    public function test_successful_response_is_captured_and_restored(): void
    {
        // Freeze the metadata timestamp and prepare a partially consumed live stream.
        Carbon::setTestNow(self::STORED_AT);
        $response = new Response(
            HttpStatus::HTTP_CREATED,
            ['X-Request-Id' => ['request-id']],
            'response-body',
            '2.0',
            'Created by API',
        );
        $response->getBody()->seek(4);

        // Capture and reconstruct the response through its scalar payload.
        $captured = HttpRememberResponse::capture($response);
        self::assertNotNull($captured);
        $restored = HttpRememberResponse::restore($captured->toArray());
        self::assertNotNull($restored);
        $reconstructed = $restored->toResponse();

        // Confirm capture restored the caller's original stream position.
        self::assertSame(4, $response->getBody()->tell());

        // Confirm all externally observable response metadata was retained.
        self::assertSame(HttpStatus::HTTP_CREATED, $reconstructed->getStatusCode());
        self::assertSame('request-id', $reconstructed->getHeaderLine('X-Request-Id'));
        self::assertSame('response-body', (string) $reconstructed->getBody());
        self::assertSame('2.0', $reconstructed->getProtocolVersion());
        self::assertSame('Created by API', $reconstructed->getReasonPhrase());
    }

    /**
     * Confirm every reconstructed response receives an independent body stream.
     */
    public function test_reconstructed_responses_have_independent_body_streams(): void
    {
        // Capture one successful response for repeated reconstruction.
        $captured = HttpRememberResponse::capture(
            new Response(HttpStatus::HTTP_OK, [], 'response-body'),
        );
        self::assertNotNull($captured);

        // Reconstruct the cached payload twice and consume the first stream.
        $first = $captured->toResponse();
        $second = $captured->toResponse();
        $first->getBody()->getContents();

        // Confirm consuming one caller's response did not alter another caller's stream.
        self::assertSame('response-body', $second->getBody()->getContents());
    }

    /**
     * Confirm responses with cookies are cached without replaying their cookie headers.
     *
     * @param  string  $header  The case variation of the response cookie header.
     */
    #[DataProvider('cookieHeaderNames')]
    public function test_response_cookies_are_preserved_only_on_the_live_response(string $header): void
    {
        // Prepare a successful response that sets session and affinity cookies.
        $cookies = ['session=first-session; Path=/; HttpOnly', 'affinity=first-server; Path=/'];
        $response = new Response(HttpStatus::HTTP_OK, [
            $header => $cookies,
            'X-Request-Id' => ['request-id'],
        ], 'response-body');

        // Capture and restore the successful response through its stored payload.
        $captured = HttpRememberResponse::capture($response);
        self::assertNotNull($captured);
        $restored = HttpRememberResponse::restore($captured->toArray());
        self::assertNotNull($restored);
        $remembered = $restored->toResponse();

        // Keep cookies on the live response while omitting them from the cached copy.
        self::assertSame($cookies, $response->getHeader('Set-Cookie'));
        self::assertFalse($remembered->hasHeader('Set-Cookie'));
        self::assertArrayNotHasKey($header, $captured->toArray()['headers']);

        // Preserve the remaining response headers and body for cached callers.
        self::assertSame('request-id', $remembered->getHeaderLine('X-Request-Id'));
        self::assertSame('response-body', (string) $remembered->getBody());
    }

    /**
     * Provide cookie header names with case variations used by HTTP responses.
     *
     * @return iterable<string, array{string}> Cookie header names keyed by casing.
     */
    public static function cookieHeaderNames(): iterable
    {
        // Require cookie filtering to follow HTTP's case-insensitive header names.
        yield 'standard case' => ['Set-Cookie'];
        yield 'lowercase' => ['set-cookie'];
        yield 'mixed case' => ['sEt-CoOkIe'];
    }

    /**
     * Confirm unsuccessful and non-seekable responses are not captured.
     */
    public function test_unsuitable_responses_are_not_captured(): void
    {
        // Prepare a failed response and a successful response with no seek support.
        $failed = new Response(HttpStatus::HTTP_SERVICE_UNAVAILABLE, [], 'failure');
        $nonSeekable = new Response(
            HttpStatus::HTTP_OK,
            [],
            new NoSeekStream(Utils::streamFor('response-body')),
        );

        // Confirm neither response can enter the cache store.
        self::assertNull(HttpRememberResponse::capture($failed));
        self::assertNull(HttpRememberResponse::capture($nonSeekable));
    }

    /**
     * Confirm known oversized bodies are skipped without reading or moving their streams.
     */
    public function test_known_oversized_responses_are_not_read(): void
    {
        // Keep the caller's cursor partway through a body whose size exceeds the budget.
        $body = Utils::streamFor(str_repeat('x', self::MAX_RESPONSE_BYTES + 1));
        $body->seek(4);
        $stream = FnStream::decorate($body, [
            'read' =>
                /**
                 * Fail if capture buffers a body that can be rejected from its known size.
                 */
                static fn (int $length): string => throw new RuntimeException('An oversized body was read.'),
        ]);

        // Return no cache candidate while preserving the live body and its original cursor.
        self::assertNull(HttpRememberResponse::capture(new Response(HttpStatus::HTTP_OK, [], $stream), self::MAX_RESPONSE_BYTES));
        self::assertSame(4, $stream->tell());
        self::assertSame(str_repeat('x', self::MAX_RESPONSE_BYTES + 1), (string) $stream);
    }

    /**
     * Confirm actual bytes enforce the limit even when size metadata is unavailable or inaccurate.
     *
     * @param  int  $bodyBytes  The complete live body size in bytes.
     * @param  int|null  $reportedSize  The size advertised by the stream.
     */
    #[DataProvider('responseSizeBoundaries')]
    public function test_capture_bounds_reads_and_preserves_the_live_body(int $bodyBytes, ?int $reportedSize): void
    {
        // Advertise a misleading Content-Length independently of the stream's actual bytes.
        $contents = str_repeat('x', $bodyBytes);
        $body = Utils::streamFor($contents);
        $position = min(4, $bodyBytes);
        $body->seek($position);
        $readBytes = 0;
        $stream = FnStream::decorate($body, [
            'getSize' =>
                /**
                 * Hide or underreport the body's size so capture must enforce its own budget.
                 */
                static fn (): ?int => $reportedSize,
            'read' =>
                /**
                 * Count actual bytes consumed by bounded capture and its overflow probe.
                 */
                static function (int $length) use ($body, &$readBytes): string {
                    $chunk = $body->read($length);
                    $readBytes += strlen($chunk);

                    return $chunk;
                },
            'getContents' =>
                /**
                 * Reject unbounded reads even when the declared size is small.
                 */
                static fn (): string => throw new RuntimeException('An unbounded body read was attempted.'),
        ]);
        $response = new Response(HttpStatus::HTTP_OK, ['Content-Length' => '1'], $stream);

        // Capture only complete bodies that fit, including bodies exactly at the limit.
        $captured = HttpRememberResponse::capture($response, self::MAX_RESPONSE_BYTES);
        if ($bodyBytes <= self::MAX_RESPONSE_BYTES) {
            self::assertNotNull($captured);
            self::assertSame($contents, $captured->toArray()['body']);
        } else {
            self::assertNull($captured);
        }

        // Read no more than the budget and one probe byte, then restore the caller's stream.
        self::assertSame(min($bodyBytes, self::MAX_RESPONSE_BYTES + 1), $readBytes);
        self::assertSame($position, $stream->tell());
        self::assertSame($contents, (string) $stream);
    }

    /**
     * Provide body sizes around the limit with unknown or underestimated stream metadata.
     *
     * @return iterable<string, array{int, int|null}> Capture boundaries keyed by size and metadata.
     */
    public static function responseSizeBoundaries(): iterable
    {
        // Check empty bodies, inclusive boundaries, and bodies substantially above the budget.
        yield 'empty unknown body' => [0, null];
        yield 'small unknown body' => [self::MAX_RESPONSE_BYTES - 1, null];
        yield 'exact unknown body' => [self::MAX_RESPONSE_BYTES, null];
        yield 'oversized unknown body' => [self::MAX_RESPONSE_BYTES + 1, null];
        yield 'large unknown body' => [4096, null];
        yield 'underreported exact body' => [self::MAX_RESPONSE_BYTES, 1];
        yield 'underreported oversized body' => [4096, 1];
    }

    /**
     * Confirm capture assembles complete bodies when individual reads return fewer bytes.
     */
    public function test_capture_handles_short_reads_at_the_size_limit(): void
    {
        // Return a few bytes per read while advertising no total size.
        $contents = str_repeat('x', self::MAX_RESPONSE_BYTES);
        $body = Utils::streamFor($contents);
        $body->seek(4);
        $stream = FnStream::decorate($body, [
            'getSize' => static fn (): ?int => null,
            'read' => static fn (int $length): string => $body->read(min(3, $length)),
        ]);

        // Preserve the complete payload and the caller's cursor despite repeated short reads.
        $captured = HttpRememberResponse::capture(new Response(HttpStatus::HTTP_OK, [], $stream), self::MAX_RESPONSE_BYTES);
        self::assertNotNull($captured);
        self::assertSame($contents, $captured->toArray()['body']);
        self::assertSame(4, $stream->tell());
    }

    /**
     * Confirm a large configured budget never requires allocating that entire budget for a small body.
     */
    public function test_capture_reads_small_bodies_in_chunks_with_large_budgets(): void
    {
        // Hide the size while allowing the largest positive integer as the capture budget.
        $body = Utils::streamFor('response-body');
        $stream = FnStream::decorate($body, [
            'getSize' => static fn (): ?int => null,
            'read' =>
                /**
                 * Reject requests large enough to allocate the whole configured budget at once.
                 */
                static function (int $length) use ($body): string {
                    self::assertLessThanOrEqual(1024 * 1024, $length);

                    return $body->read($length);
                },
        ]);

        // Capture the complete small response without overflowing the extra-byte probe budget.
        $captured = HttpRememberResponse::capture(new Response(HttpStatus::HTTP_OK, [], $stream), PHP_INT_MAX);
        self::assertNotNull($captured);
        self::assertSame('response-body', $captured->toArray()['body']);
        self::assertSame(0, $stream->tell());
    }

    /**
     * Confirm a stream that stops yielding bytes before EOF is never cached as a truncated body.
     */
    public function test_incomplete_response_streams_are_not_captured(): void
    {
        // Simulate a readable stream that cannot finish without advancing the caller's cursor.
        $body = Utils::streamFor('response-body');
        $body->seek(4);
        $stream = FnStream::decorate($body, [
            'getSize' => static fn (): ?int => null,
            'read' => static fn (int $length): string => '',
            'eof' => static fn (): bool => false,
        ]);

        // Refuse the incomplete candidate and restore the original stream position.
        self::assertNull(HttpRememberResponse::capture(new Response(HttpStatus::HTTP_OK, [], $stream), self::MAX_RESPONSE_BYTES));
        self::assertSame(4, $stream->tell());
    }

    /**
     * Confirm bounded capture restores the caller's cursor when reading fails partway through.
     */
    public function test_capture_restores_the_stream_after_read_failures(): void
    {
        // Consume bytes before failing so the finally block must restore a changed cursor.
        $body = Utils::streamFor('response-body');
        $body->seek(4);
        $stream = FnStream::decorate($body, [
            'read' =>
                /**
                 * Fail after advancing the stream during response capture.
                 */
                static function (int $length) use ($body): string {
                    $body->read(2);

                    throw new RuntimeException('The bounded read failed.');
                },
        ]);
        $this->expectException(RuntimeException::class);

        // Leave the original body usable even when capture cannot produce a cache candidate.
        try {
            HttpRememberResponse::capture(new Response(HttpStatus::HTTP_OK, [], $stream), self::MAX_RESPONSE_BYTES);
        } finally {
            self::assertSame(4, $stream->tell());
        }
    }

    /**
     * Confirm restoring an existing entry preserves bodies written under a larger size budget.
     */
    public function test_restore_preserves_bodies_written_under_a_larger_size_budget(): void
    {
        // Capture a body at the original limit before an application lowers its budget.
        $limit = HttpRememberOptions::DEFAULT_MAX_RESPONSE_BYTES + 1;
        $contents = str_repeat('x', $limit);
        $captured = HttpRememberResponse::capture(new Response(HttpStatus::HTTP_OK, [], $contents), $limit);
        self::assertNotNull($captured);
        $payload = $captured->toArray();

        // Restore the complete stored body without applying the default capture budget again.
        $restored = HttpRememberResponse::restore($payload);
        self::assertNotNull($restored);
        self::assertSame($contents, $restored->toResponse()->getBody()->getContents());
        self::assertSame($contents, $payload['body']);
    }

    /**
     * Confirm response ages are measured from their successful capture.
     */
    public function test_age_threshold_uses_the_capture_timestamp(): void
    {
        // Freeze time and capture the successful response generation.
        $storedAt = Carbon::parse(self::STORED_AT);
        Carbon::setTestNow($storedAt);
        $captured = HttpRememberResponse::capture(new Response(HttpStatus::HTTP_OK));
        self::assertNotNull($captured);

        // Move immediately before the supplied age threshold.
        Carbon::setTestNow($storedAt->copy()->addSeconds(self::AGE_THRESHOLD_SECONDS - 1));
        self::assertFalse($captured->hasReached(self::AGE_THRESHOLD_SECONDS));

        // Reach the exact threshold used by freshness and expiry checks.
        Carbon::setTestNow($storedAt->copy()->addSeconds(self::AGE_THRESHOLD_SECONDS));
        self::assertTrue($captured->hasReached(self::AGE_THRESHOLD_SECONDS));
    }

    /**
     * Confirm fractional capture times do not shorten the configured lifetime.
     */
    public function test_age_threshold_preserves_fractional_seconds(): void
    {
        // Capture near the end of a second to expose premature timestamp rounding.
        $storedAt = Carbon::parse(self::STORED_AT)->addMicroseconds(900000);
        Carbon::setTestNow($storedAt);
        $captured = HttpRememberResponse::capture(new Response(HttpStatus::HTTP_OK));
        self::assertNotNull($captured);
        $restored = HttpRememberResponse::restore($captured->toArray());
        self::assertNotNull($restored);

        // Remain inside the lifetime after crossing its rounded whole-second boundary.
        Carbon::setTestNow($storedAt->copy()->addSeconds(self::AGE_THRESHOLD_SECONDS)->subMicrosecond());
        self::assertFalse($restored->hasReached(self::AGE_THRESHOLD_SECONDS));

        // Reach the full duration measured from the actual capture instant.
        Carbon::setTestNow($storedAt->copy()->addSeconds(self::AGE_THRESHOLD_SECONDS));
        self::assertTrue($restored->hasReached(self::AGE_THRESHOLD_SECONDS));
    }

    /**
     * Confirm existing cache entries with integer timestamps remain readable.
     */
    public function test_whole_second_timestamps_can_still_be_restored(): void
    {
        // Build a successful payload using the original whole-second timestamp format.
        Carbon::setTestNow(self::STORED_AT);
        $captured = HttpRememberResponse::capture(new Response(HttpStatus::HTTP_OK, [], 'response-body'));
        self::assertNotNull($captured);
        $payload = $captured->toArray();
        $payload['stored_at'] = Carbon::now()->getTimestamp();

        // Confirm the payload can be read without losing its response or age metadata.
        $restored = HttpRememberResponse::restore($payload);
        self::assertNotNull($restored);
        self::assertSame('response-body', (string) $restored->toResponse()->getBody());
        self::assertFalse($restored->hasReached(self::AGE_THRESHOLD_SECONDS));
    }

    /**
     * Confirm untrusted malformed cache values are treated as misses.
     *
     * @param  mixed  $payload  The malformed cache value under test.
     */
    #[DataProvider('malformedPayloads')]
    public function test_malformed_payload_is_rejected(mixed $payload): void
    {
        // Confirm invalid stored data never reaches PSR-7 response construction.
        self::assertNull(HttpRememberResponse::restore($payload));
    }

    /**
     * Provide malformed values that a cache backend could return.
     *
     * @return iterable<string, array{mixed}> Malformed payloads keyed by purpose.
     */
    public static function malformedPayloads(): iterable
    {
        // Establish one valid shape for field-specific mutations.
        $valid = [
            'id' => 'generation-id',
            'stored_at' => Carbon::parse(self::STORED_AT)->getTimestamp(),
            'status' => HttpStatus::HTTP_OK,
            'headers' => ['X-Test' => ['value']],
            'body' => 'response-body',
            'protocol' => '1.1',
            'reason' => 'OK',
        ];

        // Reject values without the complete scalar payload structure.
        yield 'null' => [null];
        yield 'scalar' => ['response-body'];
        yield 'empty array' => [[]];
        yield 'missing identifier' => [array_diff_key($valid, ['id' => true])];

        // Reject timestamps that cannot participate in finite age comparisons.
        yield 'infinite timestamp' => [array_replace($valid, ['stored_at' => INF])];
        yield 'not-a-number timestamp' => [array_replace($valid, ['stored_at' => NAN])];

        // Reject values that cannot represent a successful PSR-7 response.
        yield 'failed status' => [array_replace($valid, ['status' => HttpStatus::HTTP_SERVICE_UNAVAILABLE])];
        yield 'non-list header' => [array_replace($valid, ['headers' => ['X-Test' => [1 => 'value']]])];
        yield 'non-string header value' => [array_replace($valid, ['headers' => ['X-Test' => [123]]])];
    }
}
