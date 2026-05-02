<?php

declare(strict_types=1);

namespace Nour\Http\Middleware;

use Nour\Contracts\Http\MiddlewareInterface;
use Nour\Contracts\Http\RequestHandlerInterface;
use Nour\helpers\ClientIp;
use Nour\helpers\RateLimiter;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Per-IP rate limiter. Wraps {@see RateLimiter} into a middleware that
 * rejects with HTTP 429 when the cap is exceeded.
 *
 * ## Defaults
 *
 *   namespace: `nour:rl:http`
 *   max:       60 requests
 *   window:    60 seconds
 *
 * Picked to be a sensible "first line of defense" for typical web apps.
 * Tighten for sensitive endpoints (auth, password reset) by adding a
 * second instance with a smaller window in front of those routes — the
 * pipeline supports multiple instances of the same middleware class.
 *
 * ## Reset header
 *
 * When the cap is hit the middleware sends `Retry-After: <seconds>` so
 * compliant clients back off automatically. The header value is the
 * remaining seconds in the current bucket — never less than 1.
 *
 * ## When Redis is down
 *
 * `RateLimiter` itself fails-open (allows the request through) so the
 * cache being unavailable doesn't take the platform offline. This
 * mirrors the existing `BlockIp` failure mode, but inverted — IP
 * blocking is fail-closed because the security risk of letting a
 * banned IP through outweighs the availability cost.
 *
 * ## Tip
 *
 * Pair with `RequestIdMiddleware` so the 429 response carries an
 * X-Request-Id matching the client's logs.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private RateLimiter $limiter;

    public function __construct(
        string $namespace = 'nour:rl:http',
        private readonly int $max = 60,
        private readonly int $windowSeconds = 60,
    ) {
        $this->limiter = new RateLimiter($namespace, $max, $windowSeconds);
    }

    public function process(Request $request, Response $response, RequestHandlerInterface $next): void
    {
        $ip     = ClientIp::fromRequest($request);
        $result = $this->limiter->check($ip, $this->max, $this->windowSeconds);

        if (!$result['allowed']) {
            $retryAfter = max(1, $result['reset_at'] - time());
            $response->status(429);
            $response->header('Retry-After',         (string) $retryAfter);
            $response->header('X-RateLimit-Limit',   (string) $result['max']);
            $response->header('X-RateLimit-Remaining', '0');
            $response->header('X-RateLimit-Reset',   (string) $result['reset_at']);
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->end(json_encode([
                'error' => 'rate limit exceeded',
                'part'  => 'rate_limit',
                'code'  => 'too_many_requests',
            ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // Helpful info headers on the way out — let clients self-throttle.
        $remaining = max(0, $result['max'] - $result['count']);
        $response->header('X-RateLimit-Limit',     (string) $result['max']);
        $response->header('X-RateLimit-Remaining', (string) $remaining);
        $response->header('X-RateLimit-Reset',     (string) $result['reset_at']);

        $next->handle($request, $response);
    }
}
