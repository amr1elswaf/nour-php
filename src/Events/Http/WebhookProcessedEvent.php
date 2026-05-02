<?php

declare(strict_types=1);

namespace Nour\Events\Http;

use Nour\Events\Event;

/**
 * Fired by {@see \Nour\core\server\Webhook\WebhookRouter::dispatch()}
 * after a webhook handler returns (or throws).
 *
 * The router writes the standard `{ok, path}` envelope itself; this
 * event is purely for cross-cutting observers: payment audit logs,
 * webhook-replay queues, sentry breadcrumbs, etc.
 *
 * The `result` field is:
 *   - `true`  — handler returned `true` (HTTP 200 written)
 *   - `false` — handler returned `false` (HTTP 400 written)
 *   - `null` — handler threw (HTTP 500 written); inspect `error` for
 *              the message.
 *
 * Not stoppable — by the time this fires the response has already
 * been written.
 */
final class WebhookProcessedEvent extends Event
{
    /**
     * @param array<string, mixed> $payload Decoded request body — JSON or
     *   merged GET/POST form fields, whatever the router built before
     *   calling the handler.
     * @param array<string, string> $headers Lower-cased header map.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $method,
        public readonly string $handlerClass,
        public readonly array $payload,
        public readonly array $headers,
        public readonly ?bool $result,
        public readonly ?string $error,
        public readonly float $durationMs,
    ) {}
}
