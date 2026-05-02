<?php

declare(strict_types=1);

namespace Nour\Contracts\Security;

/**
 * Persistent (TTL-bound) blacklist for API keys / IPs that have crossed
 * an abuse threshold.
 *
 * Implementations should be Redis-backed and fail **closed** — when the
 * cache is unreachable, treat the caller as blacklisted. The alternative
 * (fail-open) lets banned users right back in the moment Redis hiccups,
 * which defeats the whole point.
 */
interface BlacklistInterface
{
    public function isBlacklisted(string $apiKey, string $ip): bool;

    /**
     * Add an entry. `$seconds` controls how long the ban lasts.
     */
    public function add(string $apiKey, string $ip, int $seconds = 300): bool;
}
