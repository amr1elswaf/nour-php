<?php

declare(strict_types=1);

namespace Nour\Events;

/**
 * Convenient base for framework-emitted events.
 *
 * Adds the stoppable-propagation flag so most events get veto
 * semantics for free. You don't have to extend this — any object
 * is a valid event for {@see \Nour\Contracts\Event\EventDispatcherInterface},
 * but the dispatcher honours `isPropagationStopped()` only on
 * objects that have one.
 *
 * Compatible with PSR-14's `Psr\EventDispatcher\StoppableEventInterface`
 * (same contract, different namespace — no hard dependency on the
 * PSR package).
 */
abstract class Event
{
    private bool $propagationStopped = false;

    final public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Halt the dispatcher before the next listener runs. Subsequent
     * listeners for this event are skipped.
     */
    final public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
