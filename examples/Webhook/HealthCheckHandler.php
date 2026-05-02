<?php

declare(strict_types=1);

namespace App\Framework\Webhook;

use Nour\Contracts\Webhook\WebhookHandlerInterface;

/**
 * Trivial liveness probe. Returns OK so monitoring (Cloudflare,
 * uptime monitors, k8s liveness checks, …) can confirm the
 * webhook listener is reachable.
 *
 * Registered at `/health` in `data/Webhooks.json`.
 */
final class HealthCheckHandler implements WebhookHandlerInterface
{
    public function handle(array $payload, array $headers): bool
    {
        return true;
    }
}
