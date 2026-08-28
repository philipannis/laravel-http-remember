<?php

namespace PhilipAnnis\HttpRemember;

use GuzzleHttp\Psr7\Response;
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
 * @phpstan-type Payload array{id: string, stored_at: int, status: int, headers: array<string, list<string>>, body: string, protocol: string, reason: string}
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
     * Capture a successful, seekable response without changing its stream position.
     *
     * @param  ResponseInterface  $response  The response returned by the next handler.
     * @return self|null The captured response, or null when it cannot be cached.
     *
     * @throws RuntimeException When the response stream cannot be read or restored.
     */
    public static function capture(ResponseInterface $response): ?self
    {
        // Inspect the response without consuming its body.
        $status = $response->getStatusCode();
        $stream = $response->getBody();

        // Cache only successful responses whose body can be read and restored.
        if ($status < HttpStatus::HTTP_OK || $status >= HttpStatus::HTTP_MULTIPLE_CHOICES
            || ! $stream->isSeekable() || ! $stream->isReadable()) {
            return null;
        }

        // Remember the live response's cursor before reading its complete body.
        $position = $stream->tell();

        // Restore the cursor even when reading the response body fails.
        try {
            $stream->rewind();
            $body = $stream->getContents();
        } finally {
            $stream->seek($position);
        }

        // Record the lifetime from completion of the successful response.
        return new self([
            'id' => Str::random(),
            'stored_at' => Carbon::now()->getTimestamp(),
            'status' => $status,
            'headers' => $response->getHeaders(),
            'body' => $body,
            'protocol' => $response->getProtocolVersion(),
            'reason' => $response->getReasonPhrase(),
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
        if (! is_string($data['id']) || ! is_int($data['stored_at']) || ! is_int($data['status'])
            || $data['status'] < HttpStatus::HTTP_OK || $data['status'] >= HttpStatus::HTTP_MULTIPLE_CHOICES || ! is_array($data['headers'])
            || ! is_string($data['body']) || ! is_string($data['protocol']) || ! is_string($data['reason'])) {
            return null;
        }

        // Require header names and lists of values to contain only strings.
        foreach ($data['headers'] as $name => $values) {
            if (! is_string($name) || ! is_array($values) || ! array_is_list($values)
                || array_filter($values, 'is_string') !== $values) {
                return null;
            }
        }

        // Reuse the validated payload without storing live PHP objects in the cache.
        return new self($data);
    }

    /**
     * Determine whether a response has reached the supplied age threshold.
     *
     * @param  int  $seconds  The age threshold measured from the successful write.
     * @return bool Whether the response is at least this old.
     */
    public function hasReached(int $seconds): bool
    {
        // Compare ages without extending the entry's lifetime on cache hits.
        return Carbon::now()->getTimestamp() - $this->data['stored_at'] >= $seconds;
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
     * @return ResponseInterface A response ready for Laravel's normal response wrapper.
     *
     * @throws InvalidArgumentException When cached headers are invalid for PSR-7.
     */
    public function toResponse(): ResponseInterface
    {
        // Preserve the upstream status, headers, body, protocol, and reason phrase.
        return new Response(
            $this->data['status'],
            $this->data['headers'],
            $this->data['body'],
            $this->data['protocol'],
            $this->data['reason'],
        );
    }
}
