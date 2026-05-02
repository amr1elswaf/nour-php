<?php

declare(strict_types=1);

namespace Nour\Http\Middleware;

use Nour\Contracts\Http\MiddlewareInterface;
use Nour\Contracts\Http\RequestHandlerInterface;
use Nour\helpers\BlockIp;
use Nour\helpers\ClientIp;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * IP block-list rejection middleware. Any request from an IP that
 * matches an exact-IP entry or a CIDR range in {@see BlockIp} is
 * rejected with HTTP 403 — the terminal handler never runs.
 *
 * ## Relationship to the framework's global gate
 *
 * `\Nour\core\server\Http\HttpRequestHandleLogic::onRequest` already
 * runs an IP-block check at the top, before delegating to any handler.
 * This middleware is therefore **redundant** for apps using the default
 * wiring — by the time a request reaches the pipeline, blocked IPs
 * have already been bounced.
 *
 * The middleware exists so apps that disable the global gate (e.g. by
 * replacing `HttpRequestHandleLogic` or running the pipeline outside
 * the framework's listener shim) can still get the same behaviour by
 * adding it to their middleware list. It's kept as a no-op-on-default-
 * config drop-in so apps don't have to copy/paste the rejection logic.
 *
 * ## Failure mode
 *
 * Mirrors `BlockIp::isBlocked` — fail-CLOSED on Redis unavailability
 * (return 403). The security risk of letting banned IPs through during
 * a cache outage outweighs the availability cost. This is the SAME
 * default the global gate uses; see `BlockIp` PHPDoc for the rationale.
 */
final class BlockIpMiddleware implements MiddlewareInterface
{
    private BlockIp $blockIp;

    public function __construct(string $namespace = 'nour:blocked')
    {
        $this->blockIp = new BlockIp($namespace);
    }

    public function process(Request $request, Response $response, RequestHandlerInterface $next): void
    {
        $ip = ClientIp::fromRequest($request);
        if ($this->blockIp->isBlocked($ip)) {
            $response->status(403);
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->end(json_encode([
                'error' => 'IP blocked',
                'part'  => 'firewall',
                'code'  => 'ip_blocked',
            ], JSON_UNESCAPED_UNICODE));
            return;
        }

        $next->handle($request, $response);
    }
}
