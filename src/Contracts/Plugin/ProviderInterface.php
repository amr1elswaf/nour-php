<?php

declare(strict_types=1);

namespace Nour\Contracts\Plugin;

use Nour\Container\ContainerInterface;

/**
 * Service provider — the plugin system's extension point.
 *
 * A provider lets a third-party package (or first-party module that
 * the host wants to keep self-contained) hook into the framework's
 * boot lifecycle without editing `Bootstrap.php`. Providers contribute
 * container bindings, routes, webhooks, and timers — the framework's
 * existing config-driven loaders pick them up alongside the JSON
 * config in `data/`.
 *
 * ## Lifecycle (per-worker)
 *
 * Each worker calls, in order:
 *
 *   1. `register(ContainerInterface)` — bind contracts. Other providers
 *      may not have run yet, so don't resolve from the container here.
 *   2. Framework loads routes / webhooks (consumers are now ready).
 *   3. `boot(ContainerInterface)` — late-stage wiring. All providers
 *      have completed `register()` by this point, so resolving from
 *      the container is safe.
 *
 * Providers run in the order they appear in `setup.json:providers`.
 *
 * ## Returning empty arrays is fine
 *
 * Default implementations of `routes()`, `webhooks()`, and `timers()`
 * are not provided by an abstract base — implement only what your
 * provider needs and return `[]` from the others. The unused methods
 * are tiny and removing them would force providers that DO use them
 * to extend a base class instead of implementing an interface.
 *
 * ## Conflict policy
 *
 * Routes / webhooks with the same `req` / `path` as an existing entry
 * (in the host JSON or another provider) replace silently. The order
 * is: host JSON first → providers (in declared order). If you want
 * to override a host route, declare a provider AFTER the host's
 * Bootstrap, with a route entry sharing the same `req` key. This is
 * permissive by design; strict-mode conflict detection is a Session 6
 * follow-up.
 */
interface ProviderInterface
{
    /**
     * Bind contracts and register listeners. Container resolution is
     * NOT safe here — other providers haven't run yet.
     */
    public function register(ContainerInterface $container): void;

    /**
     * Late-stage initialization. All providers and the host's
     * Bootstrap have already run; the container is fully populated.
     */
    public function boot(ContainerInterface $container): void;

    /**
     * Routes contributed by this provider, in `data/FilesMap.json`
     * shape:
     *
     * ```php
     * [
     *   ['file_path' => 'myplugin/MyHandler', 'req' => 'MY ROUTE',
     *    'pre' => 1, 'up' => [], 'security_level' => 1],
     * ]
     * ```
     *
     * @return list<array<string, mixed>>
     */
    public function routes(): array;

    /**
     * Webhooks contributed by this provider, in `data/Webhooks.json`
     * shape:
     *
     * ```php
     * [
     *   ['path' => '/my/webhook', 'class' => '…\\MyHandler', 'method' => 'POST'],
     * ]
     * ```
     *
     * @return list<array<string, mixed>>
     */
    public function webhooks(): array;

    /**
     * Timers contributed by this provider, in `data/Timers.json`
     * shape:
     *
     * ```php
     * [['path' => 'myplugin/myTask.php', 'interval' => 60]]
     * ```
     *
     * NOTE: Timer extension is NOT merged in this version — Timers
     * are registered in the master process at server start, before
     * per-worker providers run. This method is here so the contract
     * is forward-compatible; provider-supplied timers are currently
     * logged but not registered. Use the timer wrapper file
     * convention for now.
     *
     * @return list<array<string, mixed>>
     */
    public function timers(): array;
}
