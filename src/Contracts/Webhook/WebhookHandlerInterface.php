<?php

declare(strict_types=1);

namespace Nour\Contracts\Webhook;

/**
 * Handle incoming webhook payloads. The framework's webhook listener
 * (port 9502 in the default Swoole setup) decodes the request body
 * into an array, then delegates to whichever implementation is bound.
 *
 * Implementations are responsible for:
 *   - Verifying the request's authenticity (HMAC / shared secret /
 *     signature header — depends on the upstream service).
 *   - Idempotency, when the upstream may retry.
 *   - Persisting the side-effects of a successful payload.
 *
 * The interface is intentionally minimal — everything app-specific is
 * the implementation's concern.
 */
interface WebhookHandlerInterface
{
    /**
     * Process an inbound webhook.
     *
     * @param array<string, mixed> $payload Decoded JSON body.
     * @param array<string, string> $headers Request headers
     *                                       (lowercased keys).
     *
     * @return bool true on success (200 to upstream), false on
     *              validation / signature failure (400 to upstream).
     */
    public function handle(array $payload, array $headers): bool;
}
