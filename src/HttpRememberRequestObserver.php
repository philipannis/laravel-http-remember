<?php

namespace PhilipAnnis\HttpRemember;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Psr\Http\Message\RequestInterface;

/**
 * Observe each transfer's final request after Laravel's preparation callbacks.
 *
 * @internal
 */
final class HttpRememberRequestObserver
{
    /**
     * The transfer option carrying an observer without sharing state between requests.
     */
    public const OPTION = 'http_remember_request_observer';

    /**
     * The internal callback identified when counting or ordering preparation hooks.
     *
     * @var array{class-string<self>, string}
     */
    public const CALLBACK = [self::class, 'capture'];

    /**
     * Retain the original request until the final preparation callback observes a replacement.
     *
     * @param  RequestInterface  $request  The request associated with this transfer.
     */
    public function __construct(public RequestInterface $request) {}

    /**
     * Keep one observer after application callbacks, including callbacks added between sends.
     *
     * @param  Collection<int, callable>  $callbacks  The sending builder's preparation callbacks.
     */
    public static function register(Collection $callbacks): void
    {
        // Remove an earlier position without replacing the collection shared by cloned builders.
        foreach ($callbacks as $key => $callback) {
            if ($callback === self::CALLBACK) {
                $callbacks->forget($key);
            }
        }

        // Observe the request only after every existing application callback has run.
        $callbacks->push(self::CALLBACK);
    }

    /**
     * Capture the final PSR request for the observer selected by the current transfer.
     *
     * @param  Request  $request  The request returned by the preceding preparation callback.
     * @param  array<string, mixed>  $options  The options belonging to this transfer.
     */
    public static function capture(Request $request, array $options): void
    {
        // Leave unrelated transfers and cache-hit bookkeeping free from shared observer state.
        $observer = $options[self::OPTION] ?? null;
        if ($observer instanceof self) {
            $observer->request = $request->toPsrRequest();
        }
    }
}
