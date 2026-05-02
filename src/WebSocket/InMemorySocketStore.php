<?php

declare(strict_types=1);

namespace Nour\WebSocket;

use Nour\Contracts\WebSocket\SocketStoreInterface;

/**
 * In-process {@see SocketStoreInterface} implementation — no Redis,
 * no IPC, no shared state across workers.
 *
 * ## When to use
 *
 *   - Local dev — fastest to get running, zero infra.
 *   - Single-worker apps (`worker_num: 1` in setup.json).
 *   - Apps that don't need cross-worker WebSocket awareness.
 *
 * ## When NOT to use
 *
 * Any deploy with `worker_num > 1` — sockets registered in worker A
 * won't be visible from worker B, and the WS dispatcher needs that
 * to route messages between workers. Use {@see RedisSocketStore}
 * instead.
 *
 * ## Implementation notes
 *
 * - State lives in instance properties. Bind one instance per
 *   worker (the framework does this from `Boot::run`).
 * - Indexes are kept up-to-date on every mutation so the by-user
 *   and by-room lookups are O(1) → O(N-in-bucket).
 * - `count()` and `exists()` are O(1).
 */
final class InMemorySocketStore implements SocketStoreInterface
{
    /**
     * @var array<int, array{socket_id: int, worker_id: int, ip: string,
     *                      user_id: int|string, token: string,
     *                      connected_at: int, status: string,
     *                      user_data: array<string, mixed>,
     *                      last_heartbeat: int}>
     */
    private array $sockets = [];

    /** @var array<int|string, array<int, true>>  user_id → socket_id set */
    private array $byUser = [];

    /** @var array<string, int>  token → socket_id  (single owner) */
    private array $byToken = [];

    /** @var array<string, array<int, true>>  room → socket_id set */
    private array $rooms = [];

    public function addSocket(
        int $socketId,
        int $workerId,
        string $ip,
        int|string $userId = 0,
        string $token = '',
        array $userData = []
    ): array {
        // Duplicate-connection check (token-based).
        if ($token !== '' && isset($this->byToken[$token])) {
            return [
                'success' => false,
                'reason'  => 'duplicate_active_connection',
                'existing_connection' => $this->getSocketInfo($this->byToken[$token]),
            ];
        }

        $now = time();
        $this->sockets[$socketId] = [
            'socket_id'      => $socketId,
            'worker_id'      => $workerId,
            'ip'             => $ip,
            'user_id'        => $userId,
            'token'          => $token,
            'connected_at'   => $now,
            'last_heartbeat' => $now,
            'status'         => 'active',
            'user_data'      => $userData,
        ];

        if ($userId !== 0 && $userId !== '') {
            $this->byUser[$userId][$socketId] = true;
        }
        if ($token !== '') {
            $this->byToken[$token] = $socketId;
        }

        return ['success' => true, 'removed_sockets' => []];
    }

    public function removeSocket(int $socketId): bool
    {
        $info = $this->sockets[$socketId] ?? null;
        if ($info === null) {
            return false;
        }
        unset($this->sockets[$socketId]);

        if ($info['user_id'] !== 0 && $info['user_id'] !== '') {
            unset($this->byUser[$info['user_id']][$socketId]);
            if (empty($this->byUser[$info['user_id']])) {
                unset($this->byUser[$info['user_id']]);
            }
        }
        if ($info['token'] !== '') {
            unset($this->byToken[$info['token']]);
        }

        // Drop from any rooms.
        foreach ($this->rooms as $roomName => $members) {
            if (isset($members[$socketId])) {
                unset($this->rooms[$roomName][$socketId]);
                if (empty($this->rooms[$roomName])) {
                    unset($this->rooms[$roomName]);
                }
            }
        }

        return true;
    }

    public function getSocketInfo(int $socketId): ?array
    {
        return $this->sockets[$socketId] ?? null;
    }

    public function exists(int $socketId): bool
    {
        return isset($this->sockets[$socketId]);
    }

    public function getUserSockets(int|string $userId): array
    {
        if (!isset($this->byUser[$userId])) {
            return [];
        }
        $out = [];
        foreach (array_keys($this->byUser[$userId]) as $sid) {
            if (isset($this->sockets[$sid])) {
                $out[] = $this->sockets[$sid];
            }
        }
        return $out;
    }

    public function getUsersSockets(array $userIds): array
    {
        $out = [];
        foreach ($userIds as $uid) {
            $out[$uid] = $this->getUserSockets($uid);
        }
        return $out;
    }

    public function getUserConnectionsCount(int|string $userId): int
    {
        return count($this->byUser[$userId] ?? []);
    }

    public function checkToken(string $token): ?array
    {
        $sid = $this->byToken[$token] ?? null;
        return $sid !== null ? $this->getSocketInfo($sid) : null;
    }

    public function updateHeartbeat(int $socketId): bool
    {
        if (!isset($this->sockets[$socketId])) {
            return false;
        }
        $this->sockets[$socketId]['last_heartbeat'] = time();
        return true;
    }

    public function addToRoom(int $socketId, string $roomName): bool
    {
        if (!isset($this->sockets[$socketId])) {
            return false;
        }
        $this->rooms[$roomName][$socketId] = true;
        return true;
    }

    public function removeFromRoom(int $socketId, string $roomName): bool
    {
        if (!isset($this->rooms[$roomName][$socketId])) {
            return false;
        }
        unset($this->rooms[$roomName][$socketId]);
        if (empty($this->rooms[$roomName])) {
            unset($this->rooms[$roomName]);
        }
        return true;
    }

    public function getRoomSockets(string $roomName): array
    {
        if (!isset($this->rooms[$roomName])) {
            return [];
        }
        $out = [];
        foreach (array_keys($this->rooms[$roomName]) as $sid) {
            if (isset($this->sockets[$sid])) {
                $out[] = $this->sockets[$sid];
            }
        }
        return $out;
    }

    public function count(): int
    {
        return count($this->sockets);
    }
}
