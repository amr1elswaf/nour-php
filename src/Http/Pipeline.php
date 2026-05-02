<?php

declare(strict_types=1);

namespace Nour\Http;

use Nour\Contracts\Http\MiddlewareInterface;
use Nour\Contracts\Http\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Wraps a list of {@see MiddlewareInterface} instances around a terminal
 * {@see RequestHandlerInterface}, exposing the result as a single
 * `RequestHandlerInterface`. The pipeline IS a request handler — once
 * built, it can be bound into the container and dispatched to like any
 * other handler.
 *
 * The composition is done lazily-but-once at construction: each wrap
 * step creates a thin adapter that, when its `handle()` is called,
 * invokes the corresponding middleware's `process()` with the next
 * adapter in the chain. After the constructor finishes, dispatching
 * a request is just a single virtual call.
 *
 * ## Why a separate class instead of inlining
 *
 * - Apps can build their own pipelines outside the framework's
 *   automatic wiring (e.g. a test that asserts middleware A runs
 *   before middleware B).
 * - The pipeline is reusable across requests; we build it once at
 *   worker boot and hand the same instance to every request.
 *
 * ## Example
 *
 * ```php
 * $pipeline = new Pipeline(
 *     [new BlockIpMiddleware(), new CorsMiddleware()],
 *     new DefaultHttpRequestHandler()
 * );
 * $pipeline->handle($request, $response);
 * ```
 */
final class Pipeline implements RequestHandlerInterface
{
    private RequestHandlerInterface $entry;

    /**
     * @param list<MiddlewareInterface> $middlewares
     *        Run in array order — the first wraps the rest.
     */
    public function __construct(array $middlewares, RequestHandlerInterface $terminal)
    {
        // Compose right-to-left so call order matches array order.
        $handler = $terminal;
        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $handler = self::wrap($middlewares[$i], $handler);
        }
        $this->entry = $handler;
    }

    public function handle(Request $request, Response $response): void
    {
        $this->entry->handle($request, $response);
    }

    /**
     * Build a one-step adapter: a RequestHandlerInterface that, when
     * called, invokes `$middleware->process()` with `$next` as its
     * "rest of the chain" argument.
     */
    private static function wrap(MiddlewareInterface $middleware, RequestHandlerInterface $next): RequestHandlerInterface
    {
        return new class($middleware, $next) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface $middleware,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(Request $request, Response $response): void
            {
                $this->middleware->process($request, $response, $this->next);
            }
        };
    }
}
