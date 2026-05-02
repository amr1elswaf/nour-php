<?php

declare(strict_types=1);

namespace Nour\Container;

use Closure;
use Nour\Exceptions\BindingNotFoundException;
use Nour\Exceptions\BindingResolutionException;
use Throwable;

/**
 * Default {@see ContainerInterface} implementation — a tiny, zero-magic
 * service container. The framework's single point of coupling to the host
 * application: app code binds concrete implementations of framework
 * interfaces here at boot time, and framework code resolves them at
 * runtime without ever importing `App\…` classes.
 *
 * ## Lifecycle
 *
 * Bindings live in instance state. The framework exposes a global instance
 * via {@see App::container()} so call-sites that don't carry a container
 * reference (most of `lib/nour/`) can still resolve dependencies.
 *
 * In Swoole, a single `Container` instance lives in each worker process.
 * Bindings should be registered in `workerStart` — never inside a request
 * handler — so every request in that worker sees the same, fully-bound
 * container.
 *
 * ## Example
 *
 * ```php
 * // lib/nour/Container/App.php
 * App::container()->bind(UserResolverInterface::class, new MyResolver());
 *
 * // somewhere inside the framework
 * $resolver = App::resolver(UserResolverInterface::class);
 * $user = $resolver->resolveByApiKey($mysql, $apiKey);
 * ```
 *
 * Lazy factories are supported — the closure runs on first `get()` and
 * its return value is memoized:
 *
 * ```php
 * App::container()->bind(
 *     ExpensiveServiceInterface::class,
 *     fn () => new ExpensiveService($dependency1, $dependency2)
 * );
 * ```
 */
final class Container implements ContainerInterface
{
    /** @var array<class-string, object|callable> */
    private array $bindings = [];

    /** @var array<class-string, object> */
    private array $resolved = [];

    public function bind(string $abstract, object|callable $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        // Re-binding invalidates a previously cached factory result.
        unset($this->resolved[$abstract]);
    }

    public function get(string $abstract): object
    {
        if (isset($this->resolved[$abstract])) {
            return $this->resolved[$abstract];
        }

        if (!isset($this->bindings[$abstract])) {
            throw BindingNotFoundException::for($abstract);
        }

        $concrete = $this->bindings[$abstract];

        // Pre-built instance.
        if (is_object($concrete) && !($concrete instanceof Closure)) {
            return $this->resolved[$abstract] = $concrete;
        }

        // Factory closure → invoke once, memoize.
        try {
            $instance = $concrete();
        } catch (Throwable $e) {
            throw BindingResolutionException::factoryFailed($abstract, $e);
        }

        if (!is_object($instance)) {
            throw BindingResolutionException::nonObjectReturn(
                $abstract,
                get_debug_type($instance)
            );
        }

        return $this->resolved[$abstract] = $instance;
    }

    public function tryGet(string $abstract): ?object
    {
        return $this->has($abstract) ? $this->get($abstract) : null;
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }

    /**
     * Drop all bindings — for tests only.
     */
    public function reset(): void
    {
        $this->bindings = [];
        $this->resolved = [];
    }
}
