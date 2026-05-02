<?php

declare(strict_types=1);

namespace Nour\WebSocket;

use Nour\Contracts\WebSocket\SocketStoreInterface;
use Nour\Database\redis\Structures\SocketRooms;

/**
 * Multi-worker {@see SocketStoreInterface} backed by Redis.
 *
 * Wraps the existing Lua-script-heavy {@see SocketRooms} class —
 * doesn't reimplement the scripts, just translates the new
 * generic interface to the legacy per-socket structures.
 *
 * The Redis namespace defaults to `$GLOBALS['socket_key']`
 * (set by `Nour\Server\Boot::run()` from
 * `setup.json:runtime.socket_key`). Apps that share one Redis
 * across multiple Nour deployments must set distinct namespaces
 * so the registries don't collide.
 *
 * ## Why this is two layers
 *
 * `SocketRooms` predates the v0.2 contract and carries a lot of
 * gooabb-specific assumptions (the legacy `nour_id` column, fixed
 * field layout). Splitting "the contract" from "the existing
 * implementation" lets us:
 *
 *   - keep the proven Lua scripts in production,
 *   - swap in alternate backends without touching SocketRooms,
 *   - eventually rewrite SocketRooms behind this stable contract.
 */
final class RedisSocketStore implements SocketStoreInterface
{
    public function __construct(
        private string $namespace = '',
    ) {}

    private function ns(): string
    {
        return $this->namespace !== ''
            ? $this->namespace
            : (string) ($GLOBALS['socket_key'] ?? 'nour:sockets');
    }

    public function addSocket(
        int $socketId,
        int $workerId,
        string $ip,
        int|string $userId = 0,
        string $token = '',
        array $userData = []
    ): array {
        // SocketRooms's signature wants nour_id (int) + token (string).
        // String userIds get hashed to fit. Apps that need string ids
        // should use InMemorySocketStore or implement a custom store.
        $intUserId = is_int($userId)
            ? $userId
            : (int) (crc32((string) $userId) & 0x7FFFFFFF);

        return SocketRooms::addSocket(
            $this->ns(),
            $socketId,
            $intUserId,
            $token,
            $ip,
            $workerId
        );
    }

    public function removeSocket(int $socketId): bool
    {
        return (bool) SocketRooms::removeSocket($this->ns(), $socketId);
    }

    public function getSocketInfo(int $socketId): ?array
    {
        $info = SocketRooms::getSocketInfo($this->ns(), $socketId);
        if (empty($info)) {
            return null;
        }
        return $this->normalize($info);
    }

    public function exists(int $socketId): bool
    {
        return $this->getSocketInfo($socketId) !== null;
    }

    public function getUserSockets(int|string $userId): array
    {
        $intUserId = is_int($userId)
            ? $userId
            : (int) (crc32((string) $userId) & 0x7FFFFFFF);

        $rows = SocketRooms::getUserSockets($this->ns(), $intUserId) ?: [];
        return array_map([$this, 'normalize'], $rows);
    }

    public function getUsersSockets(array $userIds): array
    {
        $ints = [];
        $back = [];
        foreach ($userIds as $uid) {
            $intUid = is_int($uid)
                ? $uid
                : (int) (crc32((string) $uid) & 0x7FFFFFFF);
            $ints[]        = $intUid;
            $back[$intUid] = $uid;
        }

        $bulk = SocketRooms::getUsersSockets($this->ns(), $ints) ?: [];

        $out = [];
        foreach ($bulk as $intUid => $rows) {
            $originalUid       = $back[$intUid] ?? $intUid;
            $out[$originalUid] = array_map([$this, 'normalize'], $rows);
        }
        return $out;
    }

    public function getUserConnectionsCount(int|string $userId): int
    {
        $intUserId = is_int($userId)
            ? $userId
            : (int) (crc32((string) $userId) & 0x7FFFFFFF);

        return (int) SocketRooms::getUserActiveConnectionsCount($this->ns(), $intUserId);
    }

    public function checkToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $result = SocketRooms::checkToken($this->ns(), $token);
        if (empty($result['existing_connection'])) {
            return null;
        }
        return $this->normalize($result['existing_connection']);
    }

    public function updateHeartbeat(int $socketId): bool
    {
        return (bool) SocketRooms::updateHeartbeat($this->ns(), $socketId);
    }

    public function addToRoom(int $socketId, string $roomName): bool
    {
        return (bool) SocketRooms::addToRoom($this->ns(), $socketId, $roomName);
    }

    public function removeFromRoom(int $socketId, string $roomName): bool
    {
        return (bool) SocketRooms::removeFromRoom($this->ns(), $socketId, $roomName);
    }

    public function getRoomSockets(string $roomName): array
    {
        // SocketRooms doesn't expose a "list room members" call directly
        // (its `broadcastToRoom` does the work internally). For now this
        // is a no-op fallback; apps that need it can extend SocketRooms
        // or implement their own adapter.
        return [];
    }

    public function count(): int
    {
        // SocketRooms doesn't currently expose a count primitive. Return
        // -1 so callers can detect the unsupported state instead of
        // misreading 0 as "no sockets".
        return -1;
    }

    /**
     * SocketRooms uses snake_case fields and `nour_id` historically.
     * Translate to the contract's shape so callers see consistent
     * keys regardless of the backing store.
     *
     * @param array<string, mixed> $row
     * @return array{
     *   socket_id: int, worker_id: int, ip: string,
     *   user_id: int|string, token: string, connected_at: int,
     *   status: string, user_data?: array
     * }
     */
    private function normalize(array $row): array
    {
        return [
            'socket_id'    => (int) ($row['socket_id'] ?? 0),
            'worker_id'    => (int) ($row['worker_id'] ?? 0),
            'ip'           => (string) ($row['ip'] ?? ''),
            'user_id'      => $row['user_id'] ?? $row['nour_id'] ?? 0,
            'token'        => (string) ($row['token'] ?? ''),
            'connected_at' => (int) ($row['connected_at'] ?? 0),
            'status'       => (string) ($row['status'] ?? 'active'),
            'user_data'    => $row['user_data'] ?? [],
        ];
    }
}
