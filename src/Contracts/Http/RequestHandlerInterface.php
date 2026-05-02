<?php

declare(strict_types=1);

namespace Nour\Contracts\Http;

use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * The class an HTTP listener delegates each request to.
 *
 * Setting this lets the host app **own the per-request lifecycle** —
 * read non-standard headers, run custom rate-limit decisions per-route,
 * wrap the response in a tenant-specific JSON envelope, etc. — instead
 * of having the framework's default `parse → auth → route` pipeline
 * baked in.
 *
 * The framework provides {@see \Nour\core\http\DefaultHttpRequestHandler}
 * as the default. Apps that don't need custom logic just don't bind a
 * different one.
 *
 * Wire a custom handler in `data/setup.json`:
 *
 * ```json
 * {
 *   "services": {
 *     "http": {
 *       "enabled": true,
 *       "port":    9501,
 *       "handler": "App\\Http\\MyHttpHandler"
 *     }
 *   }
 * }
 * ```
 *
 * Or programmatically — bind into the container at worker startup:
 *
 * ```php
 * \Nour\Container\App::container()->bind(
 *     RequestHandlerInterface::class,
 *     new MyHttpHandler()
 * );
 * ```
 *
 * Inside `handle()`, the implementation can call any of the framework
 * facades on {@see \Nour\Container\App}:
 *
 *   - `App::auth()`       → resolved {@see \Nour\Contracts\Auth\AuthPipelineInterface}
 *   - `App::rateLimit()`  → a {@see \Nour\helpers\RateLimiter} (or null)
 *   - `App::blockIp()`    → a {@see \Nour\helpers\BlockIp} instance
 *   - `App::respond()`    → standard JSON response writer
 *
 * Or just do whatever it wants — there's no required structure.
 */
interface RequestHandlerInterface
{
    public function handle(Request $request, Response $response): void;
}
