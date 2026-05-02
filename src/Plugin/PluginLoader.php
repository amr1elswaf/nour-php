<?php

declare(strict_types=1);

namespace Nour\Plugin;

use Nour\Container\App;
use Nour\Contracts\Plugin\ProviderInterface;
use RuntimeException;

/**
 * Loads and orchestrates {@see ProviderInterface} instances.
 *
 * Driven from `setup.json:providers` (a list of fully-qualified
 * provider class names). Each worker calls {@see loadAll} once at
 * `workerStart` to instantiate every provider and run their
 * `register()` hooks; then the framework loads routes/webhooks; then
 * {@see bootAll} runs each provider's `boot()` hook.
 *
 * The loader keeps the instantiated providers in a per-worker static
 * array so {@see collectRoutes} / {@see collectWebhooks} /
 * {@see collectTimers} can be called between `loadAll` and `bootAll`
 * to feed routes/webhooks into the framework's existing init paths.
 *
 * ## Per-worker
 *
 * Provider state lives in process memory. Each worker registers
 * independently — there is no inter-worker shared registry. This
 * matches how the rest of the framework works (Container is
 * per-worker; `Bootstrap::register()` runs per-worker).
 *
 * ## Future: Composer auto-discovery
 *
 * The original plan called for scanning Composer's `installed.json`
 * and reading `extra.nour.providers` from each package. That's a
 * nice-to-have left for a follow-up; the current setup-driven model
 * covers the same use cases (apps explicitly list their providers)
 * with simpler, predictable boot semantics.
 */
final class PluginLoader
{
    /** @var list<ProviderInterface> */
    private static array $providers = [];

    private static bool $loaded = false;
    private static bool $booted = false;

    private function __construct() {}

    /**
     * Instantiate every provider class and call its `register()`. Idempotent
     * within a worker — calling twice is a no-op after the first call.
     *
     * @param list<string> $providerClasses
     */
    public static function loadAll(array $providerClasses): void
    {
        if (self::$loaded) {
            return;
        }
        $instances = [];
        foreach ($providerClasses as $i => $className) {
            if (!is_string($className) || $className === '') {
                throw new RuntimeException(
                    "PluginLoader: providers[{$i}] must be a non-empty class name."
                );
            }
            if (!class_exists($className)) {
                throw new RuntimeException("PluginLoader: provider class not found: {$className}");
            }
            if (!is_subclass_of($className, ProviderInterface::class)) {
                throw new RuntimeException(
                    "PluginLoader: {$className} must implement " . ProviderInterface::class
                );
            }
            /** @var ProviderInterface $instance */
            $instance = new $className();
            $instance->register(App::container());
            $instances[] = $instance;
        }
        self::$providers = $instances;
        self::$loaded    = true;

        if ($instances !== []) {
            $names = array_map(fn (ProviderInterface $p) => get_class($p), $instances);
            echo "[PluginLoader] registered " . count($instances) . " provider(s): "
                . implode(', ', $names) . "\n";
        }
    }

    /**
     * Run `boot()` on every previously-registered provider. Idempotent.
     */
    public static function bootAll(): void
    {
        if (self::$booted) {
            return;
        }
        foreach (self::$providers as $p) {
            $p->boot(App::container());
        }
        self::$booted = true;
    }

    /**
     * Aggregate routes from every loaded provider, preserving order.
     *
     * @return list<array<string, mixed>>
     */
    public static function collectRoutes(): array
    {
        $out = [];
        foreach (self::$providers as $p) {
            foreach ($p->routes() as $route) {
                $out[] = $route;
            }
        }
        return $out;
    }

    /**
     * Aggregate webhooks from every loaded provider, preserving order.
     *
     * @return list<array<string, mixed>>
     */
    public static function collectWebhooks(): array
    {
        $out = [];
        foreach (self::$providers as $p) {
            foreach ($p->webhooks() as $hook) {
                $out[] = $hook;
            }
        }
        return $out;
    }

    /**
     * Aggregate timers — currently informational only (see
     * {@see ProviderInterface::timers()} for context).
     *
     * @return list<array<string, mixed>>
     */
    public static function collectTimers(): array
    {
        $out = [];
        foreach (self::$providers as $p) {
            foreach ($p->timers() as $timer) {
                $out[] = $timer;
            }
        }
        return $out;
    }

    /**
     * Test-only: forget loaded providers. Production code never calls
     * this; per-worker state is fine.
     */
    public static function reset(): void
    {
        self::$providers = [];
        self::$loaded    = false;
        self::$booted    = false;
    }
}
