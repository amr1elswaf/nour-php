<?php

declare(strict_types=1);

namespace Nour\Contracts\Event;

/**
 * The framework's event-dispatcher contract — PSR-14-shaped without
 * a hard dependency on `psr/event-dispatcher`.
 *
 * Two concerns:
 *
 *   1. **Dispatch**: framework code (and apps) call `dispatch($event)`
 *      with a typed event object. The dispatcher invokes every
 *      registered listener for that event's class (and its parent
 *      classes / interfaces). Returns the same event back so callers
 *      can read state listeners may have written to it.
 *
 *   2. **Subscription**: apps and plugins call `addListener(...)` to
 *      register interest. Listeners are sorted by priority (higher
 *      first); within a priority bucket, registration order wins.
 *
 * Stoppable events (those implementing
 * {@see \Nour\Events\StoppableEvent} or PSR-14's
 * `StoppableEventInterface`) short-circuit the dispatcher: once
 * `isPropagationStopped()` returns true, remaining listeners are
 * skipped. The framework's own events use this for "veto" semantics
 * — e.g. an auth listener stopping a `HandshakeEvent` to reject the
 * connection.
 *
 * ## Example
 *
 * ```php
 * App::events()->addListener(
 *     HandshakeEvent::class,
 *     function (HandshakeEvent $e) {
 *         if (!$e->apiKey) {
 *             $e->reject(401, 'Missing API key');
 *             $e->stopPropagation();
 *         }
 *     },
 *     priority: 100   // runs before less-urgent listeners
 * );
 * ```
 */
interface EventDispatcherInterface
{
    /**
     * Run all listeners registered for `$event`'s class hierarchy.
     *
     * @template T of object
     * @param T $event
     * @return T  the same event after listeners have processed it
     */
    public function dispatch(object $event): object;

    /**
     * Register a listener for events of a specific class (or any of
     * its subclasses).
     *
     * @param class-string $eventClass
     * @param callable(object): void $listener
     * @param int $priority Higher runs first. Default 0. Negative
     *                      priorities also valid for "run last".
     */
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void;

    /**
     * Remove a previously-registered listener. Identity comparison
     * (`===`) — pass the same callable that was added.
     *
     * @param class-string $eventClass
     */
    public function removeListener(string $eventClass, callable $listener): void;

    /**
     * Are any listeners registered for `$eventClass`?
     *
     * @param class-string $eventClass
     */
    public function hasListeners(string $eventClass): bool;
}
