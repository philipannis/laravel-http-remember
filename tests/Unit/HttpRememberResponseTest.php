<?php

namespace PhilipAnnis\HttpRemember\Tests\Unit;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use PhilipAnnis\HttpRemember\HttpRememberResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify the serializable representation of remembered responses.
 */
final class HttpRememberResponseTest extends TestCase
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

        // Reject values that cannot represent a successful PSR-7 response.
        yield 'failed status' => [array_replace($valid, ['status' => HttpStatus::HTTP_SERVICE_UNAVAILABLE])];
        yield 'non-list header' => [array_replace($valid, ['headers' => ['X-Test' => [1 => 'value']]])];
        yield 'non-string header value' => [array_replace($valid, ['headers' => ['X-Test' => [123]]])];
    }
}
