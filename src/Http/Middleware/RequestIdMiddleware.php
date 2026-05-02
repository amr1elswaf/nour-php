<?php

declare(strict_types=1);

namespace Nour\Http\Middleware;

use Nour\Contracts\Http\MiddlewareInterface;
use Nour\Contracts\Http\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Tags every request with an `X-Request-Id` response header so logs +
 * client-side errors can be correlated. If the inbound request already
 * carries an `X-Request-Id` header (from an upstream proxy / API
 * gateway), that value is propagated; otherwise a fresh 16-hex-char
 * token is generated.
 *
 * ## Why before the terminal handler
 *
 * Setting the header pre-handler means downstream code (auth pipeline,
 * router, app handlers) can read `$response->header['x-request-id']`
 * via Swoole's API and stamp the same id into business-side logs. (The
 * Response is the same object passed through; Swoole exposes its
 * outgoing-headers map through its `header` reflective access.)
 *
 * ## Cost
 *
 * Two hex-encoded random_bytes calls when no inbound id exists, one
 * `header()` call. Effectively free.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $headerName = 'X-Request-Id',
        private readonly int $byteLength = 8,
    ) {}

    public function process(Request $request, Response $response, RequestHandlerInterface $next): void
    {
        $headerKey = strtolower($this->headerName);
        $existing  = $request->header[$headerKey] ?? '';
        $id        = $existing !== '' ? $existing : bin2hex(random_bytes($this->byteLength));
        $response->header($this->headerName, $id);

        $next->handle($request, $response);
    }
}
