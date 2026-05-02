<?php

declare(strict_types=1);

namespace Nour\WebSocket\Events;

use Nour\Events\Event;

/**
 * Fired BEFORE the framework dispatches an inbound WebSocket frame
 * to its handler.
 *
 * Listeners can:
 *
 *   - **Read** the parsed JSON payload (or raw frame data when JSON
 *     parsing failed) and decide whether to let it through.
 *
 *   - **Veto** the frame by calling `stopPropagation()` — the
 *     framework skips the regular `SocketMain::start` dispatch.
 *     Useful for: per-message rate limiting, audit-only modes,
 *     admin overrides during maintenance.
 *
 *   - **Mutate** the parsed payload before handlers see it (the
 *     `setData()` method overwrites what gets passed to handlers).
 *
 * The `frameType` field reflects the top-level `type` from the JSON
 * payload (`"command"`, `"ping"`, `"heartbeat"`, etc.) when
 * available — listeners can dispatch off it without re-parsing.
 */
final class MessageReceivedEvent extends Event
{
    public function __construct(
        public readonly int $socketId,
        public readonly string $rawFrame,
        private array $data,
        public readonly string $frameType,
    ) {}

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): void
    {
        $this->data = $data;
    }
}
