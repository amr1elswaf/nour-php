<?php

declare(strict_types=1);

namespace Nour\helpers;

use Nour\Database\RedisDatabase;

/**
 * IP block-list — Redis-backed, with TTL support.
 *
 * Used by the framework's HTTP entry to short-circuit incoming requests
 * from banned IPs before they touch any expensive code (auth pipeline,
 * DB queries, handler dispatch). Operators set entries via the API or
 * a CLI script; the framework just reads.
 *
 * ## Lookup is O(1) for exact-IP entries
 *
 * Each exact entry is a single Redis key (`{namespace}:{ip}`) with
 * optional TTL. `isBlocked()` is a single `EXISTS` call — cheap enough
 * to run on every request without measurable overhead.
 *
 * ## CIDR blocking
 *
 * `block()` accepts both single IPs and CIDR ranges. CIDR entries are
 * stored separately (under `{namespace}:cidr`) and matched against
 * incoming IPs via {@see ClientIp::ipInCidrRanges()}. For very large
 * CIDR lists this becomes O(N) — keep it bounded.
 *
 * ## Failure modes
 *
 * - Redis disabled → `isBlocked()` always returns false. Apps without
 *   Redis don't get IP blocking.
 * - Redis unreachable → fail-CLOSED (return true / "blocked"). For
 *   security-critical paths this is the safer default; combine with
 *   monitoring on the "Redis unreachable" log line so the operator
 *   notices fast.
 *
 * ## Example
 *
 * ```php
 * $blocker = new \Nour\helpers\BlockIp();
 * $blocker->block('1.2.3.4', 3600, 'spam from this IP');
 * $blocker->block('10.0.0.0/8',   0, 'never allow this range');
 *
 * if ($blocker->isBlocked($clientIp)) {
 *     http_response_code(403);
 *     exit('blocked');
 * }
 * ```
 */
final class BlockIp
{
    private const CIDR_SUFFIX = ':cidr';

    public function __construct(
        private string $namespace = 'nour:blocked',
    ) {}

    /**
     * Block `$ipOrCidr`. Accepts single IPs (`1.2.3.4`, `2001:db8::1`)
     * or CIDR ranges (`10.0.0.0/8`, `2001:db8::/32`).
     *
     * @param int $ttlSeconds 0 = permanent. Otherwise auto-expires after N seconds.
     */
    public function block(string $ipOrCidr, int $ttlSeconds = 3600, string $reason = ''): bool
    {
        if (!RedisDatabase::isEnabled()) {
            return false;
        }
        $isCidr = str_contains($ipOrCidr, '/');
        $key    = $this->keyFor($ipOrCidr);
        $value  = json_encode([
            'reason'     => $reason,
            'blocked_at' => time(),
            'cidr'       => $isCidr,
        ], JSON_UNESCAPED_UNICODE);

        $result = RedisDatabase::withConnection(function ($redis) use ($key, $value, $ttlSeconds, $isCidr, $ipOrCidr) {
            if ($ttlSeconds > 0) {
                $redis->setex($key, $ttlSeconds, $value);
            } else {
                $redis->set($key, $value);
            }
            // Track CIDR entries in a set so isBlocked() can iterate them
            // efficiently (range matching can't be done with EXISTS).
            if ($isCidr) {
                $redis->sAdd($this->namespace . self::CIDR_SUFFIX, $ipOrCidr);
            }
            return true;
        });
        return $result ?? false;
    }

    /** Remove an entry. Returns false if Redis is unreachable. */
    public function unblock(string $ipOrCidr): bool
    {
        if (!RedisDatabase::isEnabled()) {
            return false;
        }
        $key    = $this->keyFor($ipOrCidr);
        $isCidr = str_contains($ipOrCidr, '/');

        $ok = RedisDatabase::withConnection(function ($redis) use ($key, $isCidr, $ipOrCidr) {
            $redis->del($key);
            if ($isCidr) {
                $redis->sRem($this->namespace . self::CIDR_SUFFIX, $ipOrCidr);
            }
            return true;
        });
        return $ok ?? false;
    }

    /**
     * Is this IP blocked, either by exact match or by a CIDR range?
     *
     * Fail-closed semantics: when Redis is unreachable we return true
     * so banned IPs can't sneak through during a cache outage.
     */
    public function isBlocked(string $ip): bool
    {
        if (!RedisDatabase::isEnabled()) {
            return false;
        }

        $result = RedisDatabase::withConnection(function ($redis) use ($ip) {
            // 1. Exact match — single Redis EXISTS.
            if ((bool) $redis->exists($this->keyFor($ip))) {
                return true;
            }
            // 2. CIDR ranges — pull the set, run our generic matcher.
            $cidrs = $redis->sMembers($this->namespace . self::CIDR_SUFFIX);
            if (!empty($cidrs)) {
                return ClientIp::ipInCidrRanges($ip, $cidrs);
            }
            return false;
        });

        if ($result === null) {
            error_log("[BlockIp] Redis unreachable, fail-closed for ip={$ip}");
            return true;
        }
        return (bool) $result;
    }

    /**
     * TTL for an entry. Returns:
     *  - the seconds remaining (positive int) if set
     *  - -1 if the entry exists but has no expiry (permanent)
     *  - -2 if the entry doesn't exist
     */
    public function ttl(string $ipOrCidr): int
    {
        if (!RedisDatabase::isEnabled()) {
            return -2;
        }
        $key = $this->keyFor($ipOrCidr);
        $ttl = RedisDatabase::withConnection(fn ($redis) => (int) $redis->ttl($key));
        return $ttl ?? -2;
    }

    /**
     * List up to `$limit` currently-blocked entries (exact + CIDR).
     * Useful for an admin UI. Uses SCAN — non-blocking.
     *
     * @return list<array{ip: string, cidr: bool, reason: string, ttl: int}>
     */
    public function listAll(int $limit = 100): array
    {
        if (!RedisDatabase::isEnabled()) {
            return [];
        }
        $out = RedisDatabase::withConnection(function ($redis) use ($limit) {
            $rows = [];

            // CIDR entries first (they're in a set, cheap to enumerate).
            $cidrs = $redis->sMembers($this->namespace . self::CIDR_SUFFIX);
            foreach ($cidrs as $c) {
                if (count($rows) >= $limit) break;
                $row = $this->describe($redis, $c, true);
                if ($row !== null) $rows[] = $row;
            }

            // Exact entries via SCAN.
            $cursor  = null;
            $pattern = $this->namespace . ':*';
            $cidrSet = $this->namespace . self::CIDR_SUFFIX;
            while (count($rows) < $limit) {
                $found = $redis->scan($cursor, $pattern, 200);
                if ($found === false) break;
                foreach ($found as $key) {
                    if ($key === $cidrSet) continue;
                    if (count($rows) >= $limit) break 2;
                    $ip  = substr($key, strlen($this->namespace) + 1);
                    if (str_contains($ip, '/')) continue; // covered above
                    $row = $this->describe($redis, $ip, false);
                    if ($row !== null) $rows[] = $row;
                }
                if ((int) $cursor === 0) break;
            }
            return $rows;
        });
        return $out ?? [];
    }

    // ── Internals ────────────────────────────────────────────────────

    private function keyFor(string $ipOrCidr): string
    {
        return $this->namespace . ':' . $ipOrCidr;
    }

    /**
     * @return array{ip: string, cidr: bool, reason: string, ttl: int}|null
     */
    private function describe($redis, string $ipOrCidr, bool $isCidr): ?array
    {
        $key = $this->keyFor($ipOrCidr);
        $raw = $redis->get($key);
        if ($raw === false || $raw === null) {
            return null;
        }
        $meta = json_decode((string) $raw, true) ?: [];
        return [
            'ip'     => $ipOrCidr,
            'cidr'   => $isCidr,
            'reason' => (string) ($meta['reason'] ?? ''),
            'ttl'    => (int) $redis->ttl($key),
        ];
    }
}
