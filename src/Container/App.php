<?php

declare(strict_types=1);

namespace Nour\Container;

use Nour\Contracts\Auth\AuthPipelineInterface;
use Nour\Contracts\Event\EventDispatcherInterface;
use Nour\Contracts\WebSocket\SocketStoreInterface;
use Nour\core\http\DefaultAuthPipeline;
use Nour\Events\Dispatcher;
use Nour\helpers\BlockIp;
use Nour\helpers\RateLimiter;
use Nour\WebSocket\InMemorySocketStore;
use Swoole\Http\Response;

/**
 * Static facade over the per-worker {@see Container} instance.
 *
 * Most framework code can't conveniently receive a container as a
 * dependency (handlers are static, coroutine context is request-scoped).
 * `App` keeps the convenience of a global accessor while making the
 * underlying container instance swappable for tests.
 *
 * ```php
 * // boot
 * App::setContainer(new Container());
 * App::container()->bind(UserResolverInterface::class, new MyResolver());
 *
 * // anywhere in the framework
 * $resolver = App::resolve(UserResolverInterface::class);
 * ```
 *
 * In Swoole, each worker calls {@see setContainer()} once in `workerStart`,
 * so the static state is per-worker and survives across requests within
 * that worker.
 */
final class App
{
    private static ?ContainerInterface $container = null;

    private function __construct() {}

    /** Replace the underlying container instance — call once at boot. */
    public static function setContainer(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    /** Get the current container, lazily creating a default one if absent. */
    public static function container(): ContainerInterface
    {
        return self::$container ??= new Container();
    }

    /**
     * Resolve a binding directly — shorthand for `container()->get()`.
     *
     * @template T of object
     * @param class-string<T> $abstract
     * @return T
     */
    public static function resolve(string $abstract): object
    {
        return self::container()->get($abstract);
    }

    /**
     * Best-effort resolve — null if the binding is missing.
     *
     * @template T of object
     * @param class-string<T> $abstract
     * @return T|null
     */
    public static function tryResolve(string $abstract): ?object
    {
        return self::container()->tryGet($abstract);
    }

    // ── Convenience facades for app code ─────────────────────────────
    //
    // Custom request handlers (Phase 4 — RequestHandlerInterface) call
    // these from inside their `handle()` to use framework features
    // without juggling container keys. `App::auth()` etc. read more
    // naturally than `App::resolve(AuthPipelineInterface::class)`.

    /**
     * The bound {@see AuthPipelineInterface}, or a no-op default when
     * nothing's bound. Always returns a usable object — handler code
     * doesn't have to null-check.
     */
    public static function auth(): AuthPipelineInterface
    {
        return self::tryResolve(AuthPipelineInterface::class) ?? new DefaultAuthPipeline();
    }

    /**
     * A {@see RateLimiter} ready to use. The framework doesn't bind one
     * by default — callers can pass a custom namespace + window if they
     * want app-specific limits.
     */
    public static function rateLimit(
        string $namespace = 'nour:rl',
        int $defaultMax = 60,
        int $defaultWindow = 60
    ): RateLimiter {
        return new RateLimiter($namespace, $defaultMax, $defaultWindow);
    }

    /** A {@see BlockIp} instance. */
    public static function blockIp(string $namespace = 'nour:blocked'): BlockIp
    {
        return new BlockIp($namespace);
    }

    /**
     * Standard JSON response writer. Sends `$status` + JSON body and
     * closes the response.
     *
     * @param array<string, mixed> $body
     */
    public static function respond(Response $response, int $status, array $body): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->end(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The bound {@see EventDispatcherInterface}, lazily creating a
     * default {@see Dispatcher} on first call. The default is
     * promoted to a Container singleton so subsequent `App::events()`
     * calls return the same instance — listeners stay registered.
     */
    public static function events(): EventDispatcherInterface
    {
        $bound = self::tryResolve(EventDispatcherInterface::class);
        if ($bound !== null) {
            return $bound;
        }
        $default = new Dispatcher();
        self::container()->bind(EventDispatcherInterface::class, $default);
        return $default;
    }

    /**
     * The bound {@see SocketStoreInterface}, defaulting to the
     * single-worker {@see InMemorySocketStore} when nothing else is
     * bound. Production deployments running more than one Swoole
     * worker should bind {@see \Nour\WebSocket\RedisSocketStore}
     * (or an equivalent multi-process backend) — see the
     * `services.websocket.store` field in `setup.json`.
     */
    public static function socketStore(): SocketStoreInterface
    {
        $bound = self::tryResolve(SocketStoreInterface::class);
        if ($bound !== null) {
            return $bound;
        }
        $default = new InMemorySocketStore();
        self::container()->bind(SocketStoreInterface::class, $default);
        return $default;
    }
}
