<?php

declare(strict_types=1);

namespace Nour\Contracts\Auth;

use Swoole\Database\MysqliProxy;

/**
 * The full authentication-and-authorization pipeline that runs between
 * "request body parsed" and "router dispatches to handler".
 *
 * The framework does not prescribe what an auth pipeline does — that's
 * application policy. A concrete implementation typically composes
 * several smaller framework contracts:
 *
 *   - {@see RateLimiterInterface}        — abuse throttling
 *   - {@see \Nour\Contracts\Security\BlacklistInterface}  — persistent locks
 *   - {@see UserResolverInterface}       — API key → identity
 *   - {@see \Nour\Contracts\Security\VerificationCacheInterface} — fingerprint cache
 *   - {@see \Nour\Contracts\Security\SecurityEventsInterface} — audit
 *
 * but it can also do something completely different (HMAC headers,
 * JWT bearer, mTLS, cookie sessions, …) — the framework only sees the
 * single entry point declared here.
 *
 * ## Why this contract exists
 *
 * Before this interface, `Nour\core\http\Security` was the framework's
 * one-and-only auth pipeline, and it was hard-coded for gooabb's
 * account model (profile-less users → "INSERT MY PROFILE" whitelist;
 * security_level dispatched off `FilesMap.json`'s per-route field;
 * fingerprint validation against an AES-128-GCM envelope). Splitting
 * here lets:
 *
 *   - **gooabb** keep its current behaviour by binding
 *     `App\Framework\Security\GooabbAuthPipeline`.
 *   - **new projects** bind a different pipeline, or
 *     {@see \Nour\core\http\DefaultAuthPipeline} for "no auth at all".
 *
 * ## Return shape
 *
 * `authenticate()` returns a user-data array on success. The framework
 * passes the returned array straight into `$ctx['user']`, so handler
 * code reads `$user['user_id']` / `$user['role']` / etc. The exact
 * keys are pipeline-specific; the framework only needs `user_id`
 * (an int — `0` is conventionally "anonymous") for ban/permission
 * checks, and `role` (string|null) for the Router's `up:` gating.
 *
 * On failure, throw `\Exception` with an HTTP-shaped code (400/401/403/
 * 429) and a safe-to-display message — `Main::handleError` will format
 * the response.
 *
 * @phpstan-type UserData array{
 *   user_id: int,
 *   role:    string|null,
 *   ...
 * }
 */
interface AuthPipelineInterface
{
    /**
     * Run the pipeline. Either returns user data (request continues to
     * the Router) or throws Exception (request aborts with the message
     * + code on the exception).
     *
     * @param MysqliProxy|null $mysql  May be null when MySQL is disabled.
     *                                 Pipelines that need a DB should
     *                                 throw if it's null and they're
     *                                 not in "anonymous-allowed" mode.
     * @param string $apiKey           Whatever auth credential the request
     *                                 carried — empty string when missing.
     * @param string $ip               Real client IP (already passed
     *                                 through `ClientIp::fromRequest`).
     * @param array<string, mixed> $headers
     *                                 Lower-case header map, in case the
     *                                 pipeline reads custom headers
     *                                 (`X-Idempotency-Key`, etc.).
     * @param string $requestKey       The `req` field from the body.
     *                                 Pipelines may dispatch off it
     *                                 (different rules per route).
     *
     * @return array<string, mixed> User data — at minimum
     *                              `['user_id' => int, 'role' => ?string]`.
     */
    public function authenticate(
        ?MysqliProxy $mysql,
        string $apiKey,
        string $ip,
        array $headers,
        string $requestKey
    ): array;
}
