<?php

declare(strict_types=1);

namespace Nour\Contracts\WebSocket;

/**
 * Where the framework keeps track of which WebSocket sockets are
 * currently connected, who's behind each, and which rooms each is in.
 *
 * Two implementations ship with the framework:
 *
 *   - {@see \Nour\WebSocket\InMemorySocketStore}: single-worker,
 *     no Redis. Suitable for dev, single-process apps, scenarios
 *     where Redis isn't available.
 *
 *   - {@see \Nour\WebSocket\RedisSocketStore}: multi-worker,
 *     wraps the existing {@see \Nour\Database\redis\Structures\SocketRooms}
 *     Lua-script-backed registry. Use this in production when more
 *     than one Swoole worker handles WebSockets.
 *
 * Apps that need a different backend (memcached, multi-region Redis,
 * an external state service) implement this interface and register
 * via `Container::bind(SocketStoreInterface::class, new MyStore())`.
 *
 * ## Identity model
 *
 * The framework cares about two pieces of identity per socket:
 *
 *   - **`socket_id`** (int): Swoole's `$fd`. Always present, unique
 *     within a worker process. Cross-worker uniqueness is provided
 *     by Swoole.
 *
 *   - **`user_id`** (int|string): an app-defined stable identifier
 *     for the human/account behind the socket. Optional — pass `0`
 *     or `''` for anonymous connections. Only used to answer
 *     "which sockets does user X have?".
 *
 *   - **`token`** (string): an app-defined credential or session
 *     key. Optional. Only used for duplicate-connection detection
 *     ("is this token already connected somewhere?"). Pass `''` to
 *     skip.
 *
 *   - **`userData`** (array): arbitrary extra data the app wants
 *     attached to the socket (role, name, custom flags). The framework
 *     reads it back via {@see getSocketInfo()}; otherwise opaque.
 *
 * ## Concurrency
 *
 * Implementations should be safe to call from multiple coroutines
 * within the same worker. Cross-worker concurrency is the
 * implementation's choice — `RedisSocketStore` uses Lua scripts;
 * `InMemorySocketStore` is single-worker by design.
 */
interface SocketStoreInterface
{
    /**
     * Register a new connection.
     *
     * @param int $socketId  Swoole `$fd`.
     * @param int $workerId  The Swoole worker that owns this socket.
     * @param string $ip     Client IP (resolved upstream by ClientIp).
     * @param int|string $userId  Stable per-user id (or 0 / '' if anon).
     * @param string $token  Per-connection credential (or '' to skip).
     * @param array<string, mixed> $userData  Free-form metadata.
     *
     * @return array{
     *   success: bool,
     *   reason?: string,
     *   existing_connection?: array,
     *   removed_sockets?: list<array>
     * }  `success` = stored. `reason` carries codes like
     *    `duplicate_active_connection` when applicable.
     */
    public function addSocket(
        int $socketId,
        int $workerId,
        string $ip,
        int|string $userId = 0,
        string $token = '',
        array $userData = []
    ): array;

    /** Remove a socket from the registry. Returns true on hit. */
    public function removeSocket(int $socketId): bool;

    /**
     * @return array{
     *   socket_id: int,
     *   worker_id: int,
     *   ip: string,
     *   user_id: int|string,
     *   token: string,
     *   connected_at: int,
     *   status: string,
     *   user_data?: array
     * }|null  Null when the socket isn't registered.
     */
    public function getSocketInfo(int $socketId): ?array;

    /** Cheap presence check. */
    public function exists(int $socketId): bool;

    /**
     * All sockets currently registered for a user.
     *
     * @return list<array>  Each entry shaped like {@see getSocketInfo()}.
     */
    public function getUserSockets(int|string $userId): array;

    /**
     * Bulk variant — get sockets for many users in one round-trip
     * where the implementation supports it.
     *
     * @param list<int|string> $userIds
     * @return array<int|string, list<array>>  Map keyed by user id.
     */
    public function getUsersSockets(array $userIds): array;

    /** How many sockets does this user currently have? */
    public function getUserConnectionsCount(int|string $userId): int;

    /**
     * Lookup by token — used by handshake to detect duplicate
     * connections. Returns existing-connection info or null.
     *
     * @return array{socket_id: int, worker_id: int, user_id: int|string, ...}|null
     */
    public function checkToken(string $token): ?array;

    /** Refresh last-seen — call from heartbeat handlers. */
    public function updateHeartbeat(int $socketId): bool;

    public function addToRoom(int $socketId, string $roomName): bool;
    public function removeFromRoom(int $socketId, string $roomName): bool;

    /**
     * @return list<array>  Sockets currently in the room.
     */
    public function getRoomSockets(string $roomName): array;

    /**
     * Total active sockets — for stats endpoints.
     */
    public function count(): int;
}
