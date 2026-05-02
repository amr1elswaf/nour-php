<?php

declare(strict_types=1);

namespace App\Framework\Webhook;

use App\classes\logic\PayLogic;
use Nour\Contracts\Webhook\WebhookHandlerInterface;

/**
 * Adapts {@see PayLogic::start()} to {@see WebhookHandlerInterface}.
 *
 * MyFatoorah POSTs payment-status updates to the framework's webhook
 * listener (port 9502). The framework decodes the JSON body and hands
 * it to whatever implementation is bound; this one delegates to the
 * existing PayLogic, which handles signature verification, idempotency,
 * and DB updates.
 */
final class MyfatoorahWebhookHandler implements WebhookHandlerInterface
{
    public function handle(array $payload, array $headers): bool
    {
        return PayLogic::start($payload, $headers);
    }
}
