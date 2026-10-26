<?php

namespace PhilipAnnis\HttpRemember;

use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Store response data without serializing promises, streams, or client objects.
 *
 * @internal
 *
 * @phpstan-type Payload array{id: string, stored_at: int|float, status: int, headers: array<string, list<string>>, body: string, protocol: string, reason: string, fresh?: float|null, lifetime?: float|null, initial_age?: float}
 */
final class HttpRememberResponse
{
    /**
     * Wrap a validated response payload.
     *
     * @param  Payload  $data  The serializable response and its generation metadata.
     */
    private function __construct(private readonly array $data) {}

    /**
     * Capture a successful response within its size budget without changing its stream position.
     *
     * @param  ResponseInterface  $response  The response returned by the next handler.
     * @param  int  $maxResponseBytes  The maximum cached response body size in bytes.
     * @param  string|null  $reason  The reason capture was skipped, when requested.
     * @param  HttpRememberCachePolicy|null  $policy  The origin's restrictions, evaluated before capture.
     * @return self|null The captured response, or null when it cannot be cached.
     *
     * @throws RuntimeException When the response stream cannot be read or restored.
     */
    public static function capture(ResponseInterface $response, int $maxResponseBytes = HttpRememberOptions::DEFAULT_MAX_RESPONSE_BYTES, ?string &$reason = null, ?HttpRememberCachePolicy $policy = null): ?self
    {
        // Reset diagnostics before checking this response's status and stream.
        $reason = null;

        // Inspect the response without consuming its body.
        $status = $response->getStatusCode();
        $stream = $response->getBody();

        // Cache only successful responses whose body can be read and restored.
        if ($status < HttpStatus::HTTP_OK || $status >= HttpStatus::HTTP_MULTIPLE_CHOICES) {
            $reason = 'response_status';

            return null;
        }

        // Preserve unsuitable response streams without consuming their contents.
        if (! $stream->isSeekable() || ! $stream->isReadable()) {
            $reason = 'response_stream';

            return null;
        }

        // Skip known oversized bodies before allocating a cache copy.
        $size = $stream->getSize();
        if ($size !== null && $size > $maxResponseBytes) {
            $reason = 'response_size';

            return null;
        }

        // Remember the live response's cursor before reading its complete body.
        $position = $stream->tell();

        // Restore the cursor even when reading the response body fails.
        try {
            // Bound capture even when the stream size or content-length is unavailable or inaccurate.
            $stream->rewind();
            $body = Utils::copyToString(new LimitStream($stream, $maxResponseBytes));

            // Probe one extra byte without caching truncated bodies or streams that cannot finish reading.
            if (! $stream->eof() && ($stream->read(1) !== '' || ! $stream->eof())) {
                $reason = 'response_size';

                return null;
            }
        } finally {
            // Leave the live response at the caller's original stream position.
            $stream->seek($position);
        }

        // Keep the response cacheable without issuing its cookies to another caller.
        $headers = $response->withoutHeader('Set-Cookie')->getHeaders();

        // Record the lifetime from completion of the successful response.
        return new self([
            'id' => Str::random(),
            'stored_at' => $policy?->receivedAt ?? Carbon::now()->getPreciseTimestamp() / 1000000,
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'protocol' => $response->getProtocolVersion(),
            'reason' => $response->getReasonPhrase(),
            'fresh' => $policy?->fresh,
            'lifetime' => $policy?->lifetime,
            'initial_age' => $policy?->initialAge ?? 0.0,
        ]);
    }

    /**
     * Restore a payload from a cache store, treating malformed entries as misses.
     *
     * @param  mixed  $data  The untrusted value read from the cache repository.
     * @return self|null A validated response payload, or null for an invalid value.
     */
    public static function restore(mixed $data): ?self
    {
        // Require every field before allowing a cache value into the response path.
        if (! is_array($data) || ! isset($data['id'], $data['stored_at'], $data['status'], $data['headers'], $data['body'], $data['protocol'], $data['reason'])) {
            return null;
        }

        // Check scalar types and restrict stored statuses to successful responses.
        if (! is_string($data['id']) || (! is_int($data['stored_at']) && ! is_float($data['stored_at']))
            || ! is_finite($data['stored_at']) || ! is_int($data['status'])
            || $data['status'] < HttpStatus::HTTP_OK || $data['status'] >= HttpStatus::HTTP_MULTIPLE_CHOICES || ! is_array($data['headers'])
            || ! is_string($data['body']) || ! is_string($data['protocol']) || ! is_string($data['reason'])) {
            return null;
        }

        // Require header names and lists of values to contain only strings.
        foreach ($data['headers'] as $name => $values) {
            // Reject malformed headers before reconstructing the cached response.
            if (! is_string($name) || ! is_array($values) || ! array_is_list($values)
                || array_filter($values, 'is_string') !== $values) {
                return null;
            }
        }

        // Reject invalid HTTP lifetime metadata before it can extend a cache entry's use.
        foreach (['fresh', 'lifetime', 'initial_age'] as $field) {
            if (isset($data[$field]) && ((! is_int($data[$field]) && ! is_float($data[$field]))
                || ! is_finite($data[$field]) || $data[$field] < 0)) {
                return null;
            }
        }
        if (isset($data['fresh'], $data['lifetime']) && $data['fresh'] > $data['lifetime']) {
            return null;
        }

        // Reuse the validated payload without storing live PHP objects in the cache.
        return new self($data);
    }

    /**
     * Determine whether a response has reached the supplied age threshold.
     *
     * @param  int|float  $seconds  The age threshold measured from the successful write.
     * @return bool Whether the response is at least this old.
     */
    public function hasReached(int|float $seconds): bool
    {
        // Compare ages without extending the entry's lifetime on cache hits.
        return Carbon::now()->getPreciseTimestamp() / 1000000 >= $this->data['stored_at'] + $seconds;
    }

    /**
     * Limit freshness to the response policy captured when the origin answered.
     *
     * @param  int  $configured  The application's fresh threshold in seconds.
     * @return float The effective fresh threshold measured from response receipt.
     */
    public function freshFor(int $configured): float
    {
        // A later application policy cannot extend an origin's stored freshness limit.
        return min($configured, $this->data['fresh'] ?? $configured);
    }

    /**
     * Limit total use to the response policy captured when the origin answered.
     *
     * @param  int  $configured  The application's total lifetime in seconds.
     * @return float The effective hard expiry measured from response receipt.
     */
    public function lifetimeFor(int $configured): float
    {
        // Apply the origin's deadline even when the backend retains the generation longer.
        return min($configured, $this->data['lifetime'] ?? $configured);
    }

    /**
     * Include the response's age before it entered this cache.
     *
     * @return float The current HTTP age in seconds.
     */
    public function httpAge(): float
    {
        // Keep upstream Age and Date information from being reset on a cache hit.
        return ($this->data['initial_age'] ?? 0.0) + $this->age();
    }

    /**
     * Retrieve the cached response's age without extending its lifetime.
     *
     * @return float The non-negative age in seconds, including fractional seconds.
     */
    public function age(): float
    {
        // Keep clock adjustments from exposing negative response ages in event logs.
        return max(0.0, Carbon::now()->getPreciseTimestamp() / 1000000 - $this->data['stored_at']);
    }

    /**
     * Retrieve the identifier used to detect replacement or invalidation.
     *
     * @return string The unique identifier for this cached generation.
     */
    public function id(): string
    {
        // Expose the generation token without exposing response data.
        return $this->data['id'];
    }

    /**
     * Retrieve the serializable representation for a Laravel cache store.
     *
     * @return Payload The response payload and its creation metadata.
     */
    public function toArray(): array
    {
        // Return scalar data and arrays instead of serializing a PSR-7 response.
        return $this->data;
    }

    /**
     * Build a new response with an independent body stream for each cache hit.
     *
     * @param  bool  $withAge  Whether to expose the current HTTP age on a cache hit.
     * @return ResponseInterface A response ready for Laravel's normal response wrapper.
     *
     * @throws InvalidArgumentException When cached headers are invalid for PSR-7.
     */
    public function toResponse(bool $withAge = false): ResponseInterface
    {
        // Preserve the upstream status, headers, body, protocol, and reason phrase.
        $response = new Response(
            $this->data['status'],
            $this->data['headers'],
            $this->data['body'],
            $this->data['protocol'],
            $this->data['reason'],
        );

        // Preserve raw headers for internal inspection while updating Age on delivered cache hits.
        return $withAge ? $response->withHeader('Age', (string) min(2147483648.0, floor($this->httpAge()))) : $response;
    }
}
