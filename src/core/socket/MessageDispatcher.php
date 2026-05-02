<?php

namespace Nour\core\socket;

use Nour\Container\App;
use Nour\Contracts\Media\PhotoSignerInterface;
use Swoole\WebSocket\Server;
use Nour\Database\redis\Structures\SocketRooms;

/**
 * ⚡ مرسل الرسائل المركزي (محسّن للأداء)
 */
class MessageDispatcher
{
    private static ?self $instance = null;
    private static ?Server $server = null;
    private static string $redisKey = 'nuor:socket_system';

    private function __construct() {}

    public static function initialize(Server $server, string $redisKey = 'nuor:socket_system'): void
    {
        self::$server = $server;
        self::$redisKey = $GLOBALS['socket_key'];

        if (self::$instance === null) {
            self::$instance = new self();
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('MessageDispatcher not initialized');
        }
        return self::$instance;
    }

    /**
     * إرسال رسالة إلى مستخدم (مبسّط)
     */
    public function sendToUser(int $userId, array $message, bool $has_photos = false): int
    {
        $sent = 0;


        // الحصول على سوكيتات المستخدم
        $sockets = SocketRooms::getUserSockets(self::$redisKey, $userId);
        if ($has_photos) {
            // Resolve signer once per call instead of per-photo. tryResolve
            // so the framework still works (URLs pass-through unchanged) if
            // no PhotoSigner is bound — useful in dev environments.
            $signer = App::tryResolve(PhotoSignerInterface::class);

            foreach ($sockets as $socket) {
                $socketId = $socket['socket_id'] ?? 0;
                $workerId = $socket['worker_id'] ?? 0;
                $ip = $socket['ip'];
                $user_message = $message;

                if (isset($user_message['data']['photos']) && $signer !== null) {
                    foreach ($user_message['data']['photos'] as $key => $photo_link) {
                        $user_message['data']['photos'][$key] = $key === 'profile_photo'
                            ? $signer->signProfilePhoto((string) $photo_link, $ip)
                            : ($photo_link ? $signer->sign((string) $photo_link, $ip) : null);
                    }
                }
                // تخطي غير النشط
                if (($socket['status'] ?? 'active') !== 'active') {
                    continue;
                }
                // إرسال الرسالة
                if ($this->sendToSocket($socketId, $user_message, $workerId)) {
                    $sent++;
                }
            }
        } else {
            foreach ($sockets as $socket) {
                $socketId = $socket['socket_id'] ?? 0;
                $workerId = $socket['worker_id'] ?? 0;

                // تخطي غير النشط
                if (($socket['status'] ?? 'active') !== 'active') {
                    continue;
                }

                // إرسال الرسالة
                if ($this->sendToSocket($socketId, $message, $workerId)) {
                    $sent++;
                }
            }
        }


        return $sent;
    }

    /**
     * إرسال رسالة إلى غرفة
     */
    public function broadcastToRoom(string $roomName, array $message, ?int $excludeSocketId = null): int
    {
        $result = SocketRooms::broadcastToRoom(
            self::$redisKey,
            $roomName,
            $message,
            $excludeSocketId
        );

        return $result['sent'] ?? 0;
    }

    /**
     * إرسال إلى سوكيت محدد
     */
    public function sendToSocket(int $socketId, array $message, ?int $targetWorker = null): bool
    {
        if (!self::$server) {
            return false;
        }

        // إذا لم يتم تحديد Worker، نحصل عليه من Redis
        if ($targetWorker === null) {
            $socketInfo = SocketRooms::getSocketInfo(self::$redisKey, $socketId);
            $targetWorker = $socketInfo['worker_id'] ?? 0;
        }

        // Worker الحالي
        $currentWorker = self::$server->worker_id;

        // إذا كان في نفس Worker، إرسال مباشر
        if ($targetWorker === $currentWorker) {
            return $this->sendDirect($socketId, $message);
        }
        // إرسال عبر IPC إلى Worker المناسب
        return $this->sendViaIPC($socketId, $message, $targetWorker);
    }

    /**
     * إرسال مباشر (في نفس Worker)
     */
    private function sendDirect(int $socketId, array $message): bool
    {
        if (!self::$server->exists($socketId))
            return false;

        return self::$server->push($socketId, json_encode(
            $message,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    public function SendWorkerBatch(array $data, int $targetWorker, bool $is_text = false): bool
    {
        if (!self::$server) {
            return false;
        }
        if (self::$server->worker_id ===  $targetWorker) {
            SocketManager::SendToWorkerBatch($data, self::$server, $is_text);
            return true;
        }
        try {
            return self::$server->sendMessage([
                'type' => 'send_worker_batch',
                'socket_id' => 0,
                'data' => $data,
                'is_text' => $is_text,
                'timestamp' => time()
            ], $targetWorker);
        } catch (\Exception $e) {
            return false;
        }
    }



    /**
     * إرسال عبر IPC
     */
    private function sendViaIPC(int $socketId, array $message, int $targetWorker): bool
    {
        if (!self::$server) {
            return false;
        }

        try {
            return self::$server->sendMessage([
                'type' => 'socket_message',
                'socket_id' => $socketId,
                'message' => $message,
                'timestamp' => time()
            ], $targetWorker);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * إرسال إشعار بسيط
     */
    public function notify(int $userId, string $type, array $data = []): int
    {
        $message = [
            'type' => $type,
            'data' => $data,
            'timestamp' => time()
        ];

        return $this->sendToUser($userId, $message);
    }

    /**
     * إرسال إلى مستخدمين متعددين
     */
    public function sendToMultiple(array $userIds, array $message): array
    {
        $results = [];
        foreach ($userIds as $userId) {
            $results[$userId] = $this->sendToUser($userId, $message);
        }
        return $results;
    }

    /**
     * إرسال دفعة لمستخدم عبر جميع الـ Workers
     */
    public function sendBatchToUser(int $userId, array $messages): int
    {
        if (empty($messages)) {
            return 0;
        }

        // الحصول على سوكيتات المستخدم
        $sockets = SocketRooms::getUserSockets(self::$redisKey, $userId);
        $totalSent = 0;

        foreach ($sockets as $socket) {
            $workerId = $socket['worker_id'] ?? 0;
            $socketId = $socket['socket_id'] ?? 0;

            // تخطي غير النشط
            if (($socket['status'] ?? 'active') !== 'active') {
                continue;
            }

            if ($workerId === self::$server->worker_id) {
                // نفس الـ Worker - إرسال مباشر
                foreach ($messages as $message) {
                    try {
                        if (!self::$server->exists($socketId))
                            return false;
                        if (self::$server->push($socketId, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
                            $totalSent++;
                        }
                    } catch (\Exception $e) {
                        // تجاهل الخطأ
                    }
                }
            } else {
                // Worker مختلف - إرسال عبر IPC
                try {
                    $success = self::$server->sendMessage([
                        'type' => 'socket_messages_batch',
                        'socket_id' => $socketId,
                        'messages' => $messages,
                        'timestamp' => time()
                    ], $workerId);

                    if ($success) {
                        $totalSent += count($messages);
                    }
                } catch (\Exception $e) {
                    // تجاهل الخطأ
                }
            }
        }

        return $totalSent;
    }

    /**
     * 🔥 النسخة المثالية للأداء القصوى
     */
    public function sendToUserOptimized(int $userId, array $message): void
    {
        // الحصول على سوكيتات المستخدم
        $sockets = SocketRooms::getUserSockets(self::$redisKey, $userId);

        // إذا كان هناك سوكيت واحد فقط
        if (count($sockets) === 1) {
            $socket = $sockets[0];
            if (($socket['status'] ?? 'active') === 'active') {
                $this->sendToSocket($socket['socket_id'], $message, $socket['worker_id'] ?? 0);
            }
            return;
        }

        // تجميع حسب الـ Worker
        $socketsByWorker = [];
        foreach ($sockets as $socket) {
            if (($socket['status'] ?? 'active') !== 'active') {
                continue;
            }
            $workerId = $socket['worker_id'] ?? 0;
            $socketsByWorker[$workerId][] = $socket['socket_id'];
        }

        // إرسال لكل Worker
        foreach ($socketsByWorker as $workerId => $socketIds) {
            if ($workerId === self::$server->worker_id) {
                // نفس الـ Worker - إرسال مباشر
                foreach ($socketIds as $socketId) {
                    $this->sendDirect($socketId, $message);
                }
            } else {
                // Worker مختلف - إرسال مرة واحدة مع جميع السوكيتات
                try {
                    self::$server->sendMessage([
                        'type' => 'batch_user_message',
                        'user_id' => $userId,
                        'socket_ids' => $socketIds,
                        'message' => $message,
                        'timestamp' => time()
                    ], $workerId);
                } catch (\Exception $e) {
                    // تجاهل الخطأ
                }
            }
        }
    }
}
