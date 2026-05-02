<?php

declare(strict_types=1);

namespace Nour\Contracts\Security;

/**
 * Apply per-API-key + per-IP rate limits to authenticated requests.
 *
 * The framework's `Security::start()` calls this on every authenticated
 * request before any DB work — to short-circuit abusive clients early
 * and stop them from running up DB cost.
 *
 * Implementations should be Redis-backed. Failure modes are the
 * implementation's choice; a sensible default is fail-open (return
 * true with a loud log) so a Redis hiccup doesn't take the whole
 * platform offline, while {@see BlacklistInterface} fails closed so
 * banned users stay banned even when the cache is unreachable.
 */
interface RateLimiterInterface
{
    /**
     * @return bool true if the request is within limits and may proceed,
     *              false if the caller should be rejected with HTTP 429.
     */
    public function checkLimit(string $apiKey, string $ip): bool;
}
