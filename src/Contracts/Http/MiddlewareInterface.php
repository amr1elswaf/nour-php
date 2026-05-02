<?php

declare(strict_types=1);

namespace Nour\Contracts\Http;

use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * PSR-15-shaped HTTP middleware. Differs from PSR-15 only in that it
 * takes Swoole's `Request`/`Response` (which are mutated in-place rather
 * than returning a new Response) — the chaining semantics are identical.
 *
 * Middlewares are composed by {@see \Nour\Http\Pipeline}: each one runs
 * pre-step code, decides whether to call `$next->handle()` (delegating to
 * the rest of the chain + the terminal handler), and may run post-step
 * code afterward. Returning early without calling `$next` short-circuits
 * the chain — useful for IP blocking, auth gates, preflight responses.
 *
 * ## Example
 *
 * ```php
 * final class TimingMiddleware implements MiddlewareInterface
 * {
 *     public function process(Request $r, Response $resp, RequestHandlerInterface $next): void
 *     {
 *         $start = hrtime(true);
 *         $next->handle($r, $resp);                          // delegate
 *         $ms = (hrtime(true) - $start) / 1_000_000;
 *         $resp->header('X-Response-Time-Ms', (string) $ms); // post-hook
 *     }
 * }
 * ```
 *
 * ## Order
 *
 * Middlewares run in the order they appear in `setup.json:services.http.middlewares`.
 * The first one wraps the rest, then the terminal handler — so for
 * `[A, B, C]` and a terminal handler T, the call order is:
 *
 *     A.pre → B.pre → C.pre → T.handle → C.post → B.post → A.post
 *
 * Pick the order based on what each middleware needs to see / decide.
 * Block-list checks go FIRST (cheap reject); response-mutating
 * middlewares (CORS, request-id) often go LAST so they apply to all
 * downstream responses including the ones early-returned by other
 * middlewares.
 */
interface MiddlewareInterface
{
    public function process(Request $request, Response $response, RequestHandlerInterface $next): void;
}
