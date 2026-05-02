<?php

declare(strict_types=1);

namespace Nour\Http\Middleware;

use Nour\Contracts\Http\MiddlewareInterface;
use Nour\Contracts\Http\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * CORS allowlist + OPTIONS preflight handler.
 *
 * Reads the allowed-origin list from `sitting.json:cors.allowed_origins`
 * (already wired into `$GLOBALS['setting']` by `Boot::run`). Origins not
 * in the allowlist receive no CORS headers, so the browser blocks the
 * cross-origin call — same default as the legacy inline CORS code in
 * {@see \Nour\core\http\DefaultHttpRequestHandler}.
 *
 * On `OPTIONS` requests with an allowed origin, the middleware responds
 * with 204 and short-circuits the chain — the terminal handler never
 * runs. On non-OPTIONS requests, the headers are set and the chain
 * continues normally.
 *
 * ## Drop-in vs the inline version
 *
 * `DefaultHttpRequestHandler` still has its own copy of this logic for
 * apps that haven't migrated to a middleware pipeline. When this
 * middleware is in the pipeline AND the inline version runs, the
 * effects are idempotent — same headers, same preflight short-circuit
 * — but a future cleanup will remove the inline copy. For now both
 * exist for backward-compat.
 *
 * ## Configuration
 *
 * The allowlist is consulted via `$GLOBALS['setting']['cors']['allowed_origins']`
 * — an array of exact-match origin strings. There's no wildcard
 * support; explicit origins keep the framework safe-by-default. Apps
 * that need wildcard logic should subclass / replace this middleware.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $allowedMethods = 'POST, OPTIONS',
        private readonly string $allowedHeaders = 'Content-Type, Authorization, X-Requested-With, fingerprint',
    ) {}

    public function process(Request $request, Response $response, RequestHandlerInterface $next): void
    {
        $headers        = $request->header ?? [];
        $origin         = $headers['origin'] ?? '';
        $allowedOrigins = $GLOBALS['setting']['cors']['allowed_origins'] ?? [];

        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Vary', 'Origin');
            $response->header('Access-Control-Allow-Methods', $this->allowedMethods);
            $response->header('Access-Control-Allow-Headers', $this->allowedHeaders);
        }

        $method = strtoupper((string) ($request->server['request_method'] ?? ''));
        if ($method === 'OPTIONS') {
            $response->status(204);
            $response->end();
            return; // short-circuit — preflight done.
        }

        $next->handle($request, $response);
    }
}
