<?php

declare(strict_types=1);

namespace App\Framework\Security;

use App\helpers\ApiHelper;
use Nour\Contracts\Security\BlacklistInterface;
use Nour\Contracts\Security\RateLimiterInterface;
use Nour\Contracts\Security\VerificationCacheInterface;

/**
 * App-side adapter that satisfies the three security contracts the
 * framework's `Security` module needs:
 *
 *   - {@see RateLimiterInterface}        — per-API/IP rate limiting
 *   - {@see BlacklistInterface}          — temporary lockouts after
 *                                          repeated abuse
 *   - {@see VerificationCacheInterface}  — fingerprint-validation cache
 *
 * Backed by {@see ApiHelper}, which is Redis-backed in production. The
 * three interfaces are physically combined into one adapter because
 * they all share the same Redis namespace and connection-pool slot —
 * splitting them would just duplicate boilerplate.
 *
 * Bound under all three interface keys in {@see \App\Framework\Bootstrap}:
 * the `Container` resolves each interface to the same instance, but
 * call sites in `lib/nour/` see a clean, single-purpose contract.
 */
final class AppSecurityServices implements
    RateLimiterInterface,
    BlacklistInterface,
    VerificationCacheInterface
{
    // ── RateLimiterInterface ─────────────────────────────────────────

    public function checkLimit(string $apiKey, string $ip): bool
    {
        return ApiHelper::checkRateLimit($apiKey, $ip);
    }

    // ── BlacklistInterface ───────────────────────────────────────────

    public function isBlacklisted(string $apiKey, string $ip): bool
    {
        return ApiHelper::isBlacklisted($apiKey, $ip);
    }

    public function add(string $apiKey, string $ip, int $seconds = 300): bool
    {
        return ApiHelper::setBlacklist($apiKey, $ip, $seconds);
    }

    // ── VerificationCacheInterface ───────────────────────────────────

    public function getCachedVerdict(string $cacheKey): ?bool
    {
        return ApiHelper::getCachedVerification($cacheKey);
    }

    public function cacheVerdict(string $cacheKey, bool $verdict, int $ttl = 3600): bool
    {
        return ApiHelper::cacheVerification($cacheKey, $verdict, $ttl);
    }

    public function getLastVerificationTime(string $apiKey): int
    {
        return ApiHelper::getLastVerificationTime($apiKey);
    }

    public function touchLastVerificationTime(string $apiKey): bool
    {
        return ApiHelper::updateLastVerificationTime($apiKey);
    }
}
