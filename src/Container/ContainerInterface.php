<?php

declare(strict_types=1);

namespace Nour\Container;

use Nour\Exceptions\BindingNotFoundException;
use Nour\Exceptions\BindingResolutionException;

/**
 * PSR-11-shaped container interface (without the hard `psr/container`
 * dependency — the framework keeps its required-package list small).
 *
 * Implementations MUST resolve bindings registered via {@see bind()}.
 * They MAY support lazy factory closures: when the binding is a callable,
 * it is invoked once on first {@see get()}, and the returned object is
 * memoized for subsequent calls.
 */
interface ContainerInterface
{
    /**
     * Register an implementation for an abstract / interface name.
     *
     * @param class-string $abstract
     * @param object|callable $concrete A ready-made instance OR a
     *                                  zero-argument factory.
     */
    public function bind(string $abstract, object|callable $concrete): void;

    /**
     * Resolve and return the bound implementation.
     *
     * @template T of object
     * @param class-string<T> $abstract
     * @return T
     *
     * @throws BindingNotFoundException   if nothing is bound to $abstract.
     * @throws BindingResolutionException if a factory closure failed.
     */
    public function get(string $abstract): object;

    /**
     * Best-effort variant — returns null instead of throwing when the
     * binding is missing. Use for optional contracts.
     *
     * @template T of object
     * @param class-string<T> $abstract
     * @return T|null
     */
    public function tryGet(string $abstract): ?object;

    /**
     * @param class-string $abstract
     */
    public function has(string $abstract): bool;
}
