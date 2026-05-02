<?php

declare(strict_types=1);

namespace Nour\Events;

/**
 * Fired around each tick of a registered timer (the closures returned
 * by files in `lib/timers/`, registered via `data/Timers.json`).
 *
 * Listeners typically:
 *   - Count tick frequency (sanity-check that a timer is actually firing
 *     at the configured interval).
 *   - Log slow ticks (`$durationMs > intervalMs * 0.5` is a red flag).
 *   - Surface the throw — if `$error !== null`, the timer body raised
 *     and the framework logged it; an event listener can additionally
 *     forward to Sentry/Datadog without each timer needing its own
 *     wiring.
 *
 * The event fires AFTER the closure returns. There is no
 * before-tick variant because timers are "best-effort fire and forget"
 * by design — the framework doesn't offer a way to veto a tick.
 */
final class TimerTickedEvent extends Event
{
    public function __construct(
        public readonly string $path,
        public readonly int $intervalMs,
        public readonly float $durationMs,
        public readonly ?string $error,
    ) {}
}
