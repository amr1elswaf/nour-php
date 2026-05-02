<?php

declare(strict_types=1);

namespace Nour\Contracts\Auth;

use Swoole\Database\MysqliProxy;

/**
 * Resolve API keys to user identities.
 *
 * The framework calls this from two places:
 *   - HTTP entry — `Main` runs `Security::start()` which uses the resolver
 *     to attach a user to the request context before routing.
 *   - WebSocket handshake — `WebsocketRequestHandleLogic` resolves once
 *     per connection and caches the result on the socket so per-message
 *     dispatch doesn't re-hit the DB.
 *
 * ## Contract
 *
 *  - Implementations MUST be Redis-cache-aware: check the cache before
 *    the database, and warm the cache on miss.
 *  - {@see resolveByApiKey()} MUST NOT throw for invalid keys; return
 *    `id => 0` instead. Callers translate that to a 401 if needed.
 *  - `role` MAY be null when the user has authenticated but hasn't
 *    completed profile setup. Callers decide whether that's allowed.
 *
 * @phpstan-type UserData array{
 *   id: int,
 *   role: string|null,
 *   ip?: string,
 *   expired_at?: string,
 *   fingerprint?: string|null
 * }
 */
interface UserResolverInterface
{
    /**
     * Cache-first lookup, falling back to a SQL query.
     *
     * @return UserData `id` is 0 when the key is invalid.
     */
    public function resolveByApiKey(MysqliProxy $mysql, string $apiKey): array;

    /**
     * Read-only cache lookup. Returns `[]` on miss.
     *
     * @return UserData|array{}
     */
    public function getCached(string $apiKey): array;

    /**
     * Warm the cache. `$ttl` in seconds.
     *
     * @param UserData $userData
     */
    public function cache(string $apiKey, array $userData, int $ttl): bool;

    /**
     * Drop a key's cached snapshot — call after token revocation,
     * password change, or role escalation so the next request
     * re-fetches from the source of truth.
     */
    public function invalidate(string $apiKey): void;

    /**
     * Permanently disable a key — both in the source of truth (DB row)
     * and in the cache. Called when the framework detects an expired
     * or revoked token.
     */
    public function revoke(MysqliProxy $mysql, string $apiKey): void;

    /**
     * Touch the key's last-seen state: update the persisted IP +
     * last-access timestamp in the source of truth, and invalidate
     * the cache so the next read picks the fresh values up.
     */
    public function touch(MysqliProxy $mysql, string $apiKey, string $ip): void;
}
