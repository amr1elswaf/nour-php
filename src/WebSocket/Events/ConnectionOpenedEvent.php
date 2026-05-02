<?php

declare(strict_types=1);

namespace Nour\WebSocket\Events;

use Nour\Events\Event;

/**
 * Fired AFTER a WebSocket connection has been registered with the
 * {@see \Nour\Contracts\WebSocket\SocketStoreInterface} and the
 * client has been sent the initial `welcome` frame.
 *
 * Listeners can:
 *   - Push a "user is online" notification to peers.
 *   - Hydrate per-connection state in another store.
 *   - Increment monitoring counters.
 *
 * Stopping propagation here only affects later listeners — the
 * connection is already accepted by the time this event fires.
 */
final class ConnectionOpenedEvent extends Event
{
    /** @param array<string, mixed> $userData */
    public function __construct(
        public readonly int $socketId,
        public readonly int $workerId,
        public readonly string $ip,
        public readonly int|string $userId,
        public readonly string $token,
        public readonly array $userData,
    ) {}
}
