<?php

namespace PhilipAnnis\HttpRemember;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Apply conservative HTTP cache rules within the application's configured lifetime.
 *
 * @internal
 */
final readonly class HttpRememberCachePolicy
{
    /**
     * Request controls affect cache use rather than the representation's identity.
     *
     * @var list<string>
     */
    public const CONTROL_HEADERS = ['cache-control', 'pragma'];

    /**
     * Retain response-specific deadlines and the origin's age at receipt.
     *
     * @param  float  $fresh  Fresh seconds remaining when the response arrived.
     * @param  float  $lifetime  Total usable seconds remaining when the response arrived.
     * @param  float  $initialAge  The corrected HTTP age at receipt, in seconds.
     * @param  float  $receivedAt  The response receipt time as a Unix timestamp.
     * @param  string|null  $reason  The reason this response must not be stored.
     */
    private function __construct(
        public float $fresh,
        public float $lifetime,
        public float $initialAge,
        public float $receivedAt,
        public ?string $reason = null,
    ) {}

    /**
     * Determine whether a request can be served without contacting the origin.
     *
     * @param  RequestInterface  $request  The prepared outgoing request.
     * @return string|null The conservative bypass reason, or null for an eligible request.
     */
    public static function requestBypassReason(RequestInterface $request): ?string
    {
        // Leave partial transfers and caller-managed validation to the ordinary HTTP client.
        foreach (['Range', 'If-Range', 'If-Match', 'If-None-Match', 'If-Modified-Since', 'If-Unmodified-Since'] as $header) {
            if ($request->hasHeader($header)) {
                return 'request_conditional';
            }
        }

        // Never reuse or retain responses when request controls require a live transfer.
        $directives = self::directives($request);
        if ($directives === null || isset($directives['no-store']) || isset($directives['no-cache'])) {
            return 'request_cache_control';
        }

        // Respect the legacy no-cache request directive when Cache-Control is absent.
        if (! $request->hasHeader('Cache-Control') && self::pragmaNoCache($request)) {
            return 'request_cache_control';
        }

        // Invalid or immediately expired age limits cannot authorize a cache hit.
        foreach (['max-age', 'min-fresh', 'max-stale'] as $name) {
            if (isset($directives[$name]) && ! ($name === 'max-stale' && $directives[$name] === true)) {
                $seconds = self::seconds($directives[$name]);
                if ($seconds === null || ($name === 'max-age' && $seconds === 0.0)) {
                    return 'request_cache_control';
                }
            }
        }

        // All remaining request controls are checked against the selected cached response.
        return null;
    }

    /**
     * Recognize requests that must never fall back to a network transfer.
     *
     * @param  RequestInterface  $request  The prepared outgoing request.
     * @return bool Whether an unavailable cached response must produce HTTP 504.
     */
    public static function onlyIfCached(RequestInterface $request): bool
    {
        // Preserve the network prohibition even when another directive is malformed.
        $directives = self::directives($request);

        return $directives === null
            ? stripos($request->getHeaderLine('Cache-Control'), 'only-if-cached') !== false
            : isset($directives['only-if-cached']);
    }

    /**
     * Apply request freshness limits without extending the cached response's own lifetime.
     *
     * @param  RequestInterface  $request  The prepared outgoing request.
     * @param  HttpRememberResponse  $cached  The candidate response and its age metadata.
     * @param  HttpRememberOptions  $settings  The application's upper lifetime bounds.
     * @return bool Whether the candidate satisfies every supplied request limit.
     */
    public static function allowsRequest(RequestInterface $request, HttpRememberResponse $cached, HttpRememberOptions $settings): bool
    {
        // Reject malformed directives instead of selecting whichever value is convenient.
        $directives = self::directives($request);
        if ($directives === null) {
            return false;
        }

        // Compare maximum age with the origin's age as well as time spent in this cache.
        if (isset($directives['max-age']) && $cached->httpAge() > self::seconds($directives['max-age'])) {
            return false;
        }

        // A minimum freshness requirement also rules out already stale responses.
        $freshRemaining = $cached->freshFor($settings->fresh) - $cached->age();
        if (isset($directives['min-fresh'])
            && ($freshRemaining <= 0 || $freshRemaining < self::seconds($directives['min-fresh']))) {
            return false;
        }

        // A caller may narrow the stale window, but cannot enlarge the configured lifetime.
        if (isset($directives['max-stale']) && $directives['max-stale'] !== true
            && -$freshRemaining > self::seconds($directives['max-stale'])) {
            return false;
        }

        // The middleware separately enforces response restrictions and hard expiry.
        return true;
    }

    /**
     * Derive an origin-aware policy before buffering or persisting a live response.
     *
     * @param  ResponseInterface  $response  The completed upstream response.
     * @param  HttpRememberOptions  $settings  The application's upper lifetime bounds.
     * @param  float  $responseDelay  Monotonic seconds spent waiting for this transfer.
     * @return self The bounded lifetime or the reason storage is prohibited.
     */
    public static function forResponse(ResponseInterface $response, HttpRememberOptions $settings, float $responseDelay = 0): self
    {
        // Start application lifetimes at receipt while retaining the origin's existing age.
        $now = Carbon::now()->getPreciseTimestamp() / 1000000;
        $reject = static fn (string $reason): self => new self(0, 0, 0, $now, $reason);
        $directives = self::directives($response);

        // Require unambiguous directives and honor qualified restrictions conservatively too.
        if ($directives === null) {
            return $reject('response_cache_control');
        }
        foreach (['no-store', 'no-cache', 'private', 'must-understand'] as $name) {
            if (isset($directives[$name])) {
                return $reject('response_cache_control');
            }
        }

        // Session creation must remain live even when cookies also appear in the response body.
        if ($response->hasHeader('Set-Cookie') || $response->hasHeader('Set-Cookie2')) {
            return $reject('response_cookie');
        }

        // Partial representations need range-aware storage and are never admitted here.
        if ($response->getStatusCode() === 206 || $response->hasHeader('Content-Range')) {
            return $reject('response_partial');
        }

        // Parse only integer delta-seconds, rejecting duplicate or malformed lifetime controls.
        $durations = [];
        foreach (['max-age', 's-maxage', 'stale-while-revalidate'] as $name) {
            if (isset($directives[$name])) {
                $durations[$name] = self::seconds($directives[$name]);
                if ($durations[$name] === null) {
                    return $reject('response_cache_control');
                }
            }
        }

        // Include both apparent age and upstream Age, with transfer delay added conservatively.
        $date = $response->hasHeader('Date') ? self::httpDate($response->getHeaderLine('Date')) : null;
        $age = $response->hasHeader('Age') ? self::seconds(trim($response->getHeaderLine('Age'))) : 0.0;
        if (($response->hasHeader('Date') && $date === null) || $age === null) {
            return $reject('response_cache_control');
        }
        $initialAge = max($date === null ? 0 : $now - $date, $age + max(0, $responseDelay));

        // Shared freshness takes precedence over max-age, which takes precedence over Expires.
        $originFresh = $durations['s-maxage'] ?? $durations['max-age'] ?? null;
        if ($originFresh === null && $response->hasHeader('Expires')) {
            $expires = self::httpDate($response->getHeaderLine('Expires'));
            if ($expires === null) {
                return $reject('response_expired');
            }
            $originFresh = max(0, $expires - ($date ?? $now));
        }

        // Keep the configured policy when the origin supplies no shorter freshness lifetime.
        $fresh = (float) $settings->fresh;
        $lifetime = (float) $settings->lifetime;
        if ($originFresh !== null) {
            $fresh = min($fresh, max(0, $originFresh - $initialAge));
            $lifetime = min($lifetime, max(0, $originFresh + ($durations['stale-while-revalidate'] ?? 0) - $initialAge));
        } elseif (isset($durations['stale-while-revalidate'])) {
            // An explicit stale allowance can narrow the application's own stale period.
            $lifetime = min($lifetime, $fresh + $durations['stale-while-revalidate']);
        }

        // Mandatory validation and shared max-age never permit stale responses from this cache.
        if (isset($directives['must-revalidate']) || isset($directives['proxy-revalidate']) || isset($directives['s-maxage'])) {
            $lifetime = min($lifetime, $fresh);
        }

        // Avoid retaining responses that have already exhausted their usable lifetime.
        return $lifetime <= 0
            ? $reject('response_expired')
            : new self(min($fresh, $lifetime), $lifetime, $initialAge, $now);
    }

    /**
     * Parse directives with a bounded linear scan that preserves quoted commas and escapes.
     *
     * @param  MessageInterface  $message  The request or response containing Cache-Control.
     * @return array<string, string|true>|null Normalized directives, or null for ambiguous syntax.
     */
    private static function directives(MessageInterface $message): ?array
    {
        // Reject excessive or invalid control data before allocating parser state.
        $header = $message->getHeaderLine('Cache-Control');
        $length = strlen($header);
        if ($length > 32768 || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $header)) {
            return null;
        }

        // Split only unquoted commas so field-name lists and extension arguments stay intact.
        $parts = [];
        $start = 0;
        $quoted = false;
        for ($index = 0; $index < $length; $index++) {
            if ($quoted && $header[$index] === '\\') {
                $index++;
            } elseif ($header[$index] === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $header[$index] === ',') {
                $parts[] = substr($header, $start, $index - $start);
                $start = $index + 1;
            }
        }
        if ($quoted) {
            return null;
        }
        $parts[] = substr($header, $start);

        // Validate every directive independently without backtracking over a complete header.
        $directives = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pair = explode('=', $part, 2);
            $name = strtolower(trim($pair[0]));
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
                return null;
            }

            // Decode quoted arguments without interpreting octal or control-character escapes.
            $value = isset($pair[1]) ? trim($pair[1]) : true;
            if (is_string($value) && str_starts_with($value, '"')) {
                if (strlen($value) < 2 || ! str_ends_with($value, '"')) {
                    return null;
                }
                $decoded = '';
                $last = strlen($value) - 1;
                for ($index = 1; $index < $last; $index++) {
                    if ($value[$index] === '\\') {
                        if (++$index >= $last) {
                            return null;
                        }
                    } elseif ($value[$index] === '"') {
                        return null;
                    }
                    $decoded .= $value[$index];
                }
                $value = $decoded;
            } elseif (is_string($value) && preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $value) !== 1) {
                return null;
            }

            // Reject duplicate values while allowing repeated directives without arguments.
            if (isset($directives[$name]) && ($directives[$name] !== true || $value !== true)) {
                return null;
            }
            $directives[$name] = $value;
        }

        // Unknown, syntactically valid extensions do not loosen any built-in restriction.
        return $directives;
    }

    /**
     * Convert delta-seconds without integer overflow or permissive numeric coercion.
     *
     * @param  string|true  $value  The directive or Age field value.
     * @return float|null Non-negative seconds, capped at the HTTP overflow sentinel.
     */
    private static function seconds(string|bool $value): ?float
    {
        // HTTP defines only decimal non-negative integers for these age values.
        return is_string($value) && $value !== '' && ctype_digit($value)
            ? min(2147483648.0, (float) $value)
            : null;
    }

    /**
     * Parse HTTP dates without accepting relative expressions or silently repaired dates.
     *
     * @param  string  $value  The Date or Expires field value.
     * @return float|null The UTC Unix timestamp, or null for an invalid date.
     */
    private static function httpDate(string $value): ?float
    {
        // Accept the preferred format and the two historical HTTP date formats.
        foreach ([DATE_RFC7231, 'l, d-M-y H:i:s \\G\\M\\T', 'D M j H:i:s Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, trim($value), new DateTimeZone('UTC'));
            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return (float) $date->getTimestamp();
            }
        }

        // Invalid expiration dates are treated as already expired by the caller.
        return null;
    }

    /**
     * Recognize the legacy request directive without inspecting quoted extension values.
     *
     * @param  RequestInterface  $request  The request without a Cache-Control field.
     * @return bool Whether Pragma requires an origin request.
     */
    private static function pragmaNoCache(RequestInterface $request): bool
    {
        // Pragma's no-cache directive is argument-free and case-insensitive.
        foreach (explode(',', $request->getHeaderLine('Pragma')) as $directive) {
            if (strtolower(trim($directive)) === 'no-cache') {
                return true;
            }
        }

        // Other legacy directives do not authorize or prohibit caching.
        return false;
    }
}
