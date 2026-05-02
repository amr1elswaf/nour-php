<?php

declare(strict_types=1);

namespace Nour\Contracts\Security;

/**
 * Stores the outcome of expensive verification steps (currently the
 * fingerprint validator) so we don't re-run them on every request from
 * the same key/IP/hour.
 *
 * Two distinct storage shapes share this contract:
 *   - Boolean cache keyed by an arbitrary string (e.g. `"fp:apiX:ipY:hour"`).
 *   - "Last verification" timestamp per API key — used to decide whether
 *     a periodic re-verification is due.
 */
interface VerificationCacheInterface
{
    /**
     * Read a cached boolean verdict. Returns `null` on cache miss
     * (so callers can distinguish miss from `false`).
     */
    public function getCachedVerdict(string $cacheKey): ?bool;

    /**
     * Persist a verdict. `$ttl` in seconds.
     */
    public function cacheVerdict(string $cacheKey, bool $verdict, int $ttl = 3600): bool;

    /**
     * Unix timestamp of the last successful verification for `$apiKey`,
     * or `0` if the key has never been verified.
     */
    public function getLastVerificationTime(string $apiKey): int;

    /**
     * Mark the current time as the most recent successful verification
     * for `$apiKey`.
     */
    public function touchLastVerificationTime(string $apiKey): bool;
}
