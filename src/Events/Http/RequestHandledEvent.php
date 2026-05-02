<?php

declare(strict_types=1);

namespace Nour\Events\Http;

use Nour\Events\Event;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Fired after {@see \Nour\core\http\Main::start()} returns and the
 * response has been written.
 *
 * Listeners typically:
 *   - Push request metrics (status code histogram, latency).
 *   - Log slow requests (`$durationMs > threshold`).
 *   - Forward access logs to an aggregator.
 *
 * The response is already closed by this point — any `$response->...`
 * calls inside a listener are no-ops. If you need to mutate the
 * response, listen to {@see RequestReceivedEvent} instead.
 *
 * Note that this event still fires when a listener stopped propagation
 * on `RequestReceivedEvent` — so a "request rejected by listener"
 * counter is straightforward (compare `getStatus()` and the early-exit
 * status the rejection wrote).
 *
 * Not stoppable — propagation control on a post-hook would be
 * confusing and there's no recipient downstream to short-circuit.
 */
final class RequestHandledEvent extends Event
{
    /**
     * @param int|null $status HTTP status code if the framework wrote it
     *   itself (block, OPTIONS, fingerprint-error, listener-rejection
     *   path). `null` when the status was set by `Main::start` or a
     *   downstream handler — Swoole's Response API doesn't expose a
     *   getter, so the framework can't read it back. A future
     *   middleware (Session 3) can wrap the response to capture this.
     */
    public function __construct(
        public readonly Request $request,
        public readonly Response $response,
        public readonly string $ip,
        public readonly float $durationMs,
        public readonly ?int $status,
    ) {}
}
