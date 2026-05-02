<?php

declare(strict_types=1);

namespace Nour\core\http;

use Nour\Contracts\Auth\AuthPipelineInterface;
use Swoole\Database\MysqliProxy;

/**
 * Pure pass-through {@see AuthPipelineInterface} implementation.
 *
 * Used when:
 *  - the host app deliberately runs without authentication (read-only
 *    public APIs, static dashboards, internal tools), OR
 *  - the host app forgot to bind a real pipeline (sane fallback so
 *    `Main::start` doesn't crash on startup).
 *
 * Every request resolves to anonymous (`user_id = 0`). The Router's
 * `up:` permission gates and the framework's ban check both refuse
 * anonymous requests on routes that require an identity, so this
 * default is "open the public routes, deny everything else" by
 * construction — the right behaviour for a fresh install.
 *
 * Replace with a real pipeline by binding into the container:
 *
 * ```php
 * App::container()->bind(
 *     AuthPipelineInterface::class,
 *     new \App\MyAuthPipeline()
 * );
 * ```
 */
final class DefaultAuthPipeline implements AuthPipelineInterface
{
    public function authenticate(
        ?MysqliProxy $mysql,
        string $apiKey,
        string $ip,
        array $headers,
        string $requestKey
    ): array {
        return ['user_id' => 0, 'role' => null];
    }
}
