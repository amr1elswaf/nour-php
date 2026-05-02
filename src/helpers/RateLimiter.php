<?php

declare(strict_types=1);

namespace Nour\helpers;

use Nour\Database\RedisDatabase;

/**
 * Per-key rate limiter — generic, Redis-backed, fixed-window counter.
 *
 * The window is a calendar-style bucket (`floor($now / $window)`), which
 * means an attacker can burst up-to `$max` requests at the very end of
 * window N and another `$max` at the very start of window N+1 — so the
 * effective short-term burst can be up to `2 × max` over a `2 × window`
 * span. For most rate-limit use-cases (per-IP throttling, login attempt
 * caps) this is fine; for stricter "exactly N per N seconds" semantics
 * use a sliding-log limiter instead.
 *
 * ## Failure modes
 *
 * - Redis disabled (`isEnabled()` false) → `check()` always returns
 *   `allowed: true`. The limiter is effectively a no-op; useful for
 *   apps that haven't configured Redis.
 * - Redis hiccup (connection error, timeout) → fail-open with a loud
 *   error_log. The alternative (fail-closed, return 429) takes the
 *   whole platform offline on a transient cache blip; that's worse
 *   than no rate limiting for a few seconds.
 *
 * ## Example
 *
 * ```php
 * $rl = new \Nour\helpers\RateLimiter('myapp', 100, 60);  // 100 req / 60s
 * $res = $rl->check($_SERVER['REMOTE_ADDR']);
 * if (!$res['allowed']) {
 *     header('Retry-After: ' . max(1, $res['reset_at'] - time()));
 *     http_response_code(429);
 *     exit('rate limit exceeded');
 * }
 * ```
 */
final class RateLimiter
{
    public function __construct(
        private string $namespace = 'nour:rl',
        private int $defaultMaxRequests = 60,
        private int $defaultWindowSeconds = 60,
    ) {}

    /**
     * Increment the counter for `$key` and check whether the limit is
     * exceeded.
     *
     * @return array{allowed: bool, count: int, max: int, reset_at: int}
     *   - allowed:  true when count <= max.
     *   - count:    the post-increment count for this window.
     *   - max:      the cap that applied.
     *   - reset_at: unix timestamp the current window ends at.
     */
    public function check(string $key, ?int $max = null, ?int $window = null): array
    {
        $max    = $max    ?? $this->defaultMaxRequests;
        $window = $window ?? $this->defaultWindowSeconds;
        $window = max(1, $window);
        $now    = time();
        $bucket = (int) floor($now / $window);
        $resetAt = ($bucket + 1) * $window;

        if (!RedisDatabase::isEnabled()) {
            return ['allowed' => true, 'count' => 0, 'max' => $max, 'reset_at' => $resetAt];
        }

        $count = RedisDatabase::withConnection(function ($redis) use ($key, $bucket, $window) {
            $redisKey = $this->namespace . ':' . $key . ':' . $bucket;
            $count    = (int) $redis->incr($redisKey);
            if ($count === 1) {
                // First hit in the window — set TTL so old buckets self-clean.
                $redis->expire($redisKey, $window + 1);
            }
            return $count;
        });

        if ($count === null) {
            // Redis hiccup — fail open with alert.
            error_log("[RateLimiter] Redis unreachable, fail-open for key={$key}");
            return ['allowed' => true, 'count' => 0, 'max' => $max, 'reset_at' => $resetAt];
        }

        return [
            'allowed'  => $count <= $max,
            'count'    => $count,
            'max'      => $max,
            'reset_at' => $resetAt,
        ];
    }

    /**
     * Drop the current window's counter for `$key`. Admin override —
     * use with care.
     */
    public function reset(string $key, ?int $window = null): bool
    {
        if (!RedisDatabase::isEnabled()) {
            return true;
        }
        $window = $window ?? $this->defaultWindowSeconds;
        $bucket = (int) floor(time() / max(1, $window));
        $redisKey = $this->namespace . ':' . $key . ':' . $bucket;

        $ok = RedisDatabase::withConnection(function ($redis) use ($redisKey) {
            return (bool) $redis->del($redisKey);
        });
        return $ok ?? false;
    }

    /**
     * Read-only — what's the current count for `$key` in the active window?
     * Returns 0 when there are no entries (or Redis is unreachable).
     */
    public function peek(string $key, ?int $window = null): int
    {
        if (!RedisDatabase::isEnabled()) {
            return 0;
        }
        $window = $window ?? $this->defaultWindowSeconds;
        $bucket = (int) floor(time() / max(1, $window));
        $redisKey = $this->namespace . ':' . $key . ':' . $bucket;

        $count = RedisDatabase::withConnection(fn ($r) => (int) $r->get($redisKey));
        return (int) ($count ?? 0);
    }
}
