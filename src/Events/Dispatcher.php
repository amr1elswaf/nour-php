<?php

declare(strict_types=1);

namespace Nour\Events;

use Nour\Contracts\Event\EventDispatcherInterface;
use Throwable;

/**
 * Default {@see EventDispatcherInterface} implementation —
 * synchronous, in-process, per-worker.
 *
 * ## Listener resolution
 *
 * When `dispatch($event)` runs, the dispatcher walks the event's
 * full class hierarchy (the class itself, every parent class, and
 * every interface) and concatenates all registered listeners in
 * priority order. So a listener registered against an interface
 * will see every event whose class implements that interface.
 *
 * This is intentionally PSR-14-flavoured. Listeners against the
 * concrete class run alongside listeners against base classes and
 * interfaces — no priority preference between specificity levels;
 * priority is the only sort key.
 *
 * ## Stoppable events
 *
 * If the event has an `isPropagationStopped(): bool` method (e.g.
 * extends {@see Event} or implements PSR-14's
 * `StoppableEventInterface`), the dispatcher checks it after each
 * listener. Once true, remaining listeners are skipped. Otherwise
 * every listener gets called.
 *
 * ## Errors in listeners
 *
 * A throwing listener is logged via `error_log()` and propagation
 * continues. The dispatcher is "best-effort": a single bad listener
 * doesn't break the others or the caller.
 */
final class Dispatcher implements EventDispatcherInterface
{
    /**
     * @var array<class-string, list<array{listener: callable, priority: int, seq: int}>>
     */
    private array $listeners = [];

    /**
     * Tie-breaker for listeners sharing a priority. Increment-only,
     * so registration order is preserved within a priority bucket.
     */
    private int $sequence = 0;

    public function dispatch(object $event): object
    {
        $stoppable = method_exists($event, 'isPropagationStopped');

        foreach ($this->listenersFor($event) as $listener) {
            try {
                $listener($event);
            } catch (Throwable $e) {
                error_log(
                    '[Events] listener for ' . get_class($event)
                    . ' threw: ' . $e->getMessage()
                );
            }
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }
        }
        return $event;
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventClass][] = [
            'listener' => $listener,
            'priority' => $priority,
            'seq'      => $this->sequence++,
        ];
    }

    public function removeListener(string $eventClass, callable $listener): void
    {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }
        $this->listeners[$eventClass] = array_values(array_filter(
            $this->listeners[$eventClass],
            fn (array $row): bool => $row['listener'] !== $listener
        ));
    }

    public function hasListeners(string $eventClass): bool
    {
        // Direct + any inherited listener fires for the concrete class,
        // so we walk the same chain dispatch() does.
        foreach ($this->classChain($eventClass) as $key) {
            if (!empty($this->listeners[$key])) {
                return true;
            }
        }
        return false;
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * Collect every listener that should fire for `$event`, sorted by
     * priority (high → low) with registration order as the tiebreaker.
     *
     * @return list<callable>
     */
    private function listenersFor(object $event): array
    {
        $rows = [];
        foreach ($this->classChain(get_class($event)) as $key) {
            if (isset($this->listeners[$key])) {
                foreach ($this->listeners[$key] as $row) {
                    $rows[] = $row;
                }
            }
        }
        // Higher priority first; older registration first within a tie.
        usort($rows, function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }
            return $a['seq'] <=> $b['seq'];
        });

        return array_map(fn (array $row): callable => $row['listener'], $rows);
    }

    /**
     * Class itself + parents + every implemented interface.
     *
     * @return list<string>
     */
    private function classChain(string $className): array
    {
        if (!class_exists($className) && !interface_exists($className)) {
            return [$className]; // listener registered against unknown name
        }

        $chain = [$className];
        $parents = class_parents($className) ?: [];
        foreach ($parents as $parent) {
            $chain[] = $parent;
        }
        $interfaces = class_implements($className) ?: [];
        foreach ($interfaces as $iface) {
            $chain[] = $iface;
        }
        return $chain;
    }
}
