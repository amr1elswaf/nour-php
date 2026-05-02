<?php

declare(strict_types=1);

namespace Nour\Events\Http;

use Nour\Events\Event;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Fired at the very top of {@see \Nour\core\http\DefaultHttpRequestHandler::handle()}
 * — before IP-block checks, before CORS, before fingerprint parsing, before
 * the auth pipeline runs.
 *
 * ## What listeners can do
 *
 * - **Read** — `$request->server`, `$request->header`, `$request->post`,
 *   client IP via `$ip`. Useful for request logging, request-id assignment,
 *   tracing headers.
 * - **Reject** — call `$response->status(...)` + `$response->end(...)` to
 *   write a response and then `stopPropagation()` to skip the rest of the
 *   request lifecycle. The handler shim sees the propagation-stopped
 *   flag and bails out before reaching `Main::start`. Common use: deny
 *   requests by tenant header, redirect on a maintenance flag, force
 *   HTTPS, etc.
 * - **Mutate userData** — attach metadata via `setAttribute()` that later
 *   listeners (or middleware once Session 3 lands) can read. The
 *   attributes do NOT flow into `Main::start` automatically; they're a
 *   listener-to-listener channel.
 *
 * Stoppable: yes. A listener that writes to `$response` MUST also call
 * `stopPropagation()`, otherwise downstream middleware/handlers will
 * try to write to a response that's already ended (Swoole logs an
 * error but doesn't crash).
 */
final class RequestReceivedEvent extends Event
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        public readonly Request $request,
        public readonly Response $response,
        public readonly string $ip,
    ) {}

    /**
     * Attach a free-form attribute that subsequent listeners can read.
     * Keyed by string for collision avoidance — namespace your keys
     * (e.g. `'myapp.request_id'`) when sharing with other plugins.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}
