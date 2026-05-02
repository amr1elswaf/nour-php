<?php

declare(strict_types=1);

namespace Nour\WebSocket\Events;

use Nour\Events\Event;

/**
 * Fired AFTER a WebSocket connection has been removed from the
 * socket store.
 *
 * Listeners typically:
 *   - Push a "user is offline" notification.
 *   - Flush per-connection scratch state.
 *   - Decrement monitoring counters.
 *
 * The `userData` carries whatever the {@see HandshakeEvent} listener
 * had attached at handshake time — useful for fan-out by user id /
 * role / tenant after the connection is gone.
 */
final class ConnectionClosedEvent extends Event
{
    /** @param array<string, mixed> $userData */
    public function __construct(
        public readonly int $socketId,
        public readonly int $workerId,
        public readonly int|string $userId,
        public readonly string $token,
        public readonly array $userData,
    ) {}
}
