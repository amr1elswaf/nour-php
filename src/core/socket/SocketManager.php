<?php

namespace Nour\core\socket;

use Nour\Container\App;
use Nour\Contracts\WebSocket\SocketStoreInterface;
use Nour\Database\redis\Structures\Queue;
use Nour\Database\redis\Structures\SocketRooms;
use Swoole\WebSocket\Server;
use Swoole\Timer;

/**
 * ⚡ مدير السوكيتات (كل Worker له نسخته)
 * 1. إضافة/إزالة السوكيتات
 * 2. إدارة Heartbeats
 * 3. العمليات المحلية للسوكيتات
 */
class SocketManager
{
    public Server $server;
    public string $redisKey;
    private int $workerId;
    public  array $sockets;

    private const users_state_queue = 'nuor:user_last_seen';

    public function __construct(Server $server, string $redisKey = 'nuor:socket_system')
    {
        $this->server = $server;
        $this->sockets = [];
        $this->redisKey = $GLOBALS['socket_key'];
        $this->workerId = $server->worker_id;
    }

    /**
     * Generic add — used by the new event-driven WS handshake path.
     * Delegates to whichever {@see SocketStoreInterface} is bound,
     * so the same call works whether the host app picked Redis,
     * in-memory, or a custom store.
     *
     * Caller (WebsocketRequestHandleLogic) supplies user identity
     * harvested from the {@see \Nour\WebSocket\Events\HandshakeEvent}
     * — the framework no longer hard-codes how that gets resolved.
     *
     * @param int|string $userId
     * @param array<string, mixed> $userData
     */
    public function addSocketGeneric(
        int $socketId,
        int|string $userId,
        string $token,
        string $ip,
        array $userData = [],
        bool $retry = false
    ): bool {
        $store      = App::socketStore();
        $socket_num = $store->getUserConnectionsCount($userId);

        $result = $store->addSocket(
            $socketId,
            $this->workerId,
            $ip,
            $userId,
            $token,
            $userData
        );

        $removed = $result['removed_sockets'] ?? [];
        if (!empty($removed)) {
            foreach ($removed as $removedSocket) {
                if (isset($removedSocket['socket_id'], $removedSocket['worker_id'])) {
                    $this->removeSocket(
                        (int) $removedSocket['socket_id'],
                        (int) $removedSocket['worker_id']
                    );
                }
            }
        }

        if (empty($result['success'])) {
            if ($retry) {
                return false;
            }
            if (($result['reason'] ?? '') === 'duplicate_active_connection') {
                $existing = $result['existing_connection'] ?? null;
                if ($existing !== null) {
                    $isActive = $this->is_active(
                        (int) $existing['socket_id'],
                        (int) $existing['worker_id'],
                        is_int($userId) ? $userId : 0
                    );
                    if (!$isActive) {
                        return $this->addSocketGeneric(
                            $socketId,
                            $userId,
                            $token,
                            $ip,
                            $userData,
                            true
                        );
                    }
                }
            }
            return false;
        }

        // First-connection presence ping (legacy behaviour).
        if ($socket_num === 0 && (is_int($userId) ? $userId : '') !== '' && $userId !== 0) {
            Queue::enqueue(self::users_state_queue, [
                'nour_id'   => $userId,
                'state'     => 1,
                'timestamp' => time(),
            ], 60 * 10);
        }

        // Per-worker fast-lookup table — handlers reach for this via
        // get_from_local_sockets to avoid a Redis round-trip on each
        // inbound message.
        $this->sockets[$socketId] = [
            'user_id'   => $userId,
            'ip'        => $ip,
            'api_key'   => $token,         // back-compat field name
            'token'     => $token,         // canonical field name
            'user_data' => $userData,
            'role'      => $userData['role'] ?? null,  // back-compat
        ];
        return true;
    }

    /**
     * Legacy add — preserved for backward compat with any direct
     * caller. Forwards to {@see addSocketGeneric()} with role
     * folded into userData (which is where it conceptually belongs).
     *
     * New code should call addSocketGeneric directly with whatever
     * userData shape the host app chose.
     *
     * @deprecated since v0.2 — use addSocketGeneric()
     */
    public function addSocket(int $socketId, int $nourId, string $token, string $ip, ?string $role = null, bool $retry = false): bool
    {
        return $this->addSocketGeneric(
            $socketId,
            $nourId,
            $token,
            $ip,
            ['role' => $role],
            $retry
        );
    }




    public function is_active($socket_id, $workr_id = null, $nour_id  = -1): bool
    {
        if (is_null($workr_id)) {
            $workr_id = $this->workerId;
        }

        if ($workr_id !== $this->workerId) {

            $res =  $this->server->sendMessage([
                'type' => 'is_active_socket',
                'socket_id' => $socket_id,
                'timestamp' => time(),
                'nour_id' => $nour_id
            ], $workr_id);
            return $res;
        }

        if ($this->server->exists($socket_id)) {
            return true;
        } else {
            $this->removeSocket($socket_id, $this->workerId, $nour_id);
            return false;
        }
    }
    /**
     * إزالة سوكيت
     */
    public function removeSocket(int $socketId, int $targetWorkerId, int $nourId = -1): bool
    {
        //echo "Removing socket from removeSocket {$socketId} from worker {$targetWorkerId}\n";
        if ($targetWorkerId === $this->workerId) {
            $res = $this->forceDisconnect($socketId);
        } else {
            // إرسال إلى Worker المناسب
            $res = $this->server->sendMessage([
                'type' => 'force_disconnect',
                'socket_id' => $socketId
            ], $targetWorkerId);
        }

        if ($res) {
            return true;
        } else {
            return $this->check_user_stuts($socketId, $nourId);
        }
    }


    public function remove_from_local_sockets($socketId)
    {
        unset($this->sockets[$socketId]);
    }
    public function get_from_local_sockets($socketId): array
    {
        return ($this->sockets[$socketId]);
    }

    public function check_user_stuts($socketId, $userId = -1): bool
    {
        $store = App::socketStore();
        if ($userId === -1) {
            $info = $store->getSocketInfo($socketId);
            if (empty($info)) {
                return false;
            }
            $this->remove_from_local_sockets($socketId);
            $userId = $info['user_id'] ?? 0;
        }
        $res = $store->removeSocket($socketId);
        if (!$res) {
            return false;
        }

        $socket_num = $store->getUserConnectionsCount($userId);

        if ($socket_num === 0 && $userId !== 0 && $userId !== '') {
            Queue::enqueue(self::users_state_queue, [
                'nour_id'   => $userId,    // legacy field name preserved
                'state'     => 0,
                'timestamp' => time(),
            ], 60 * 10);
        }
        return true;
    }
    /**
     * ⚡ **دالة متطورة: إزالة جميع سوكيتات nour_id**
     */
    public function removeAllSocketsForUser(int $nourId): array
    {
        $results = [
            'user_id' => $nourId,
            'total_sockets' => 0,
            'removed' => 0,
            'failed' => 0,
            'details' => []
        ];

        // ⚡ **1. الحصول على جميع سوكيتات المستخدم**
        $userSockets = SocketRooms::getUserSockets($this->redisKey, $nourId);
        $results['total_sockets'] = count($userSockets);

        // ⚡ **2. حذف كل سوكيت**
        foreach ($userSockets as $socket) {
            if (!isset($socket['worker_id'])) {
                continue;
            }
            $res = $this->removeSocket($socket['socket_id'], $socket['worker_id'], $nourId);
            if ($res) {
                $results['removed']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * تحديث Heartbeat
     */
    public function updateHeartbeat(int $socketId): bool
    {
        return SocketRooms::updateHeartbeat($this->redisKey, $socketId);
    }

    /**
     * إضافة إلى غرفة
     */
    public function addToRoom(int $socketId, string $roomName): bool
    {
        return SocketRooms::addToRoom($this->redisKey, $socketId, $roomName);
    }

    /**
     * إزالة من غرفة
     */
    public function removeFromRoom(int $socketId, string $roomName): bool
    {
        return SocketRooms::removeFromRoom($this->redisKey, $socketId, $roomName);
    }

    /**
     * معالجة رسائل IPC
     */
    public function handleIPC(array $message)
    {
        $type = $message['type'] ?? '';
        $socketId = $message['socket_id'] ?? 0;

        switch ($type) {
            case 'heartbeat_warning':
                $this->sendHeartbeatWarning($socketId);
                break;

            case 'force_disconnect':
                return $this->removeSocket($socketId, $this->workerId);
                break;
            case 'is_active_socket':
                return $this->is_active($socketId, null,  $message['nour_id'] ?? -1);
            case 'send_worker_batch':
                self::SendToWorkerBatch($message['data'] ?? [], $this->server, $message['is_text'] ?? false);
                break;
            case 'socket_message':
                $this->sendMessage($socketId, $message['message'] ?? []);
                break;

            case 'socket_messages_batch':
                $this->sendMessagesBatch($socketId, $message['messages'] ?? []);
                break;
        }
    }

    //        $r = [[0,["message"=>"hello world !"]]];
    public static function SendToWorkerBatch(array $batchData, $server, $is_text): void
    {
        $sentCount = 0;
        $failedCount = 0;
        $totalMessages = 0;

        if ($is_text) {
            foreach ($batchData as $item) {
                try {
                    // $item = [$socket_id, $messages_array]
                    $socketId = $item[0];
                    $messages = $item[1];
                    // تحقق من وجود السوكيت
                    if (!$server->exists($socketId)) {
                        $failedCount++;
                        continue;
                    }
                    // إرسال كل الرسائل لهذا السوكيت
                    foreach ($messages as $message) {

                        $success = $server->push(
                            $socketId,
                            $message
                        );
                        if ($success) {
                            $sentCount++;
                        } else {
                            $failedCount++;
                        }
                        $totalMessages++;
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    error_log("Batch send error for socket {$socketId}: " . $e->getMessage());
                }
            }
        } else {
            //var_dump($batchData);
            foreach ($batchData as $item) {
                try {
                    // $item = [$socket_id, $messages_array]
                    $socketId = $item[0];
                    $messages = $item[1]; // هذه دائماً مصفوفة
                    // إرسال كل الرسائل لهذا السوكيت
                    foreach ($messages as $message) {
                        if (!$server->exists($socketId)) {
                            $failedCount++;

                            continue 2;
                        }
                        $success = $server->push(
                            $socketId,
                            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        );

                        if ($success) {
                            $sentCount++;
                        } else {
                            $failedCount++;
                        }
                        $totalMessages++;
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    error_log("Batch send error for socket {$socketId}: " . $e->getMessage());
                }
            }
        }


        // تسجيل النتائج
        /*$this->logEvent('send_batch_completed', [
            'sockets_processed' => count($batchData),
            'messages_sent' => $sentCount,
            'messages_failed' => $failedCount,
            'total_messages' => $totalMessages
        ]);*/
    }


    /**
     * 🔥 جديد: إرسال دفعة من الرسائل إلى سوكيت
     */
    private function sendMessagesBatch(int $socketId, array $messages): void
    {
        $sentCount = 0;
        $failedCount = 0;

        foreach ($messages as $message) {
            try {
                $success = $this->server->push($socketId, json_encode(
                    $message,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));

                if ($success) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            } catch (\Exception $e) {
                $failedCount++;
                error_log("Batch send error for socket {$socketId}: " . $e->getMessage());
            }
        }

        /*$this->logEvent('send_batch_completed', [
            'socket_id' => $socketId,
            'sent' => $sentCount,
            'failed' => $failedCount,
            'total' => count($messages)
        ]);*/
    }





    /**
     * إرسال تحذير Heartbeat
     */
    private function sendHeartbeatWarning(int $socketId): void
    {
        if ($this->server->exists($socketId)) {
            $this->server->push($socketId, json_encode(
                [
                    'type' => 'heartbeat_warning',
                    'timestamp' => time()
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } else {
            SocketRooms::removeSocket($this->redisKey, $socketId);
        }
    }

    /**
     * إجبار قطع الاتصال
     */
    private function forceDisconnect(int $socketId): bool
    {
        // استخدام exists بدلاً من isEstablished
        if ($this->server->exists($socketId)) {
            $this->server->close($socketId);
            return true;
        }
        return false;
    }

    /**
     * إرسال رسالة
     */
    public function sendMessage(int $socketId, array $message): void
    {
        if (!$this->server->exists($socketId))
            return;

        $success = $this->server->push($socketId, json_encode(
            $message,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        if (!$success) {
            $this->removeSocket($socketId, $this->workerId);
        }
    }


    /**
     * تسجيل الأحداث
     */
    private function logEvent(string $event, array $data = []): void
    {
        $logData = array_merge([
            'event' => $event,
            'worker_id' => $this->workerId,
            'timestamp' => time()
        ], $data);

        error_log("SocketManager [Worker {$this->workerId}]: " . json_encode($logData));
    }
}
