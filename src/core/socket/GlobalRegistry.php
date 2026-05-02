<?php

namespace Nour\core\socket;

use Exception;
use Nour\Container\App;
use Nour\Contracts\Media\PhotoSignerInterface;
use Nour\Database\redis\Structures\SocketRooms;
use Swoole\WebSocket\Server;

/**
 * ⚡ السجل العام للوصول للأنظمة من أي مكان
 */
class GlobalRegistry
{
    private static ?Server $server = null;
    private static ?MessageDispatcher $dispatcher = null;
    private static ?WebSocketWorker $worker = null;
    private static string $redisKey;

    /**
     * تهيئة السجل
     */
    public static function initialize(Server $server, string $redisKey = 'nuor:socket_system'): void
    {
        self::$server = $server;
        self::$redisKey = $GLOBALS['socket_key'];

        // تهيئة MessageDispatcher
        MessageDispatcher::initialize($server, $redisKey);
        self::$dispatcher = MessageDispatcher::getInstance();

        echo "📋 GlobalRegistry initialized\n";
    }

    /**
     * تهيئة WebSocketWorker (فقط في Worker 0)
     */
    public static function initializeWorker(): void
    {
        if (self::$server && self::$server->worker_id === 0) {
            self::$worker = WebSocketWorker::getInstance(self::$server, self::$redisKey);
            self::$worker->start();
            echo "🔧 WebSocketWorker initialized in Worker 0\n";
        }
    }

    /**
     * الحصول على السيرفر
     */
    public static function getServer(): ?Server
    {
        return self::$server;
    }

    /**
     * الحصول على MessageDispatcher
     */
    public static function getDispatcher(): ?MessageDispatcher
    {
        return self::$dispatcher;
    }


    /**
     * إرسال رسالة إلى مستخدم
     */
    public static function sendToUser(int $userId, array $message, bool $has_photos = false): int
    {
        return self::$dispatcher ?
            self::$dispatcher->sendToUser($userId, $message, $has_photos) :
            false;
    }

    public static function sendToWorkerBatch(array $data, int $workerId, bool $is_text = false): int
    {
        return self::$dispatcher ?
            self::$dispatcher->SendWorkerBatch($data, $workerId, $is_text) :
            false;
    }

    public static function sendToSoket(int $socket_id, string $type, array $data, ?int $targetWorker = null): bool
    {
        return self::$dispatcher ?
            self::$dispatcher->sendToSocket($socket_id, ['type' => $type, "data" => $data, 'timestamp' => time()], $targetWorker) :
            false;
    }
    /*public static function sendBatchToUser(int $userId, array $messages):int
    {
        return self::$dispatcher ?
            self::$dispatcher->sendBatchToUser($userId, $messages) :
            ['error' => 'Dispatcher not initialized'];
    }*/
    /**
     * إرسال إشعار بسيط
     */
    public static function notify(int $userId, string $type, array $data = []): int
    {
        return self::$dispatcher ?
            self::$dispatcher->notify($userId, $type, $data) :
            false;
    }


    public static function sendOneMessagesToMultiUsers(array $userIds, array $message, bool $has_photos = false): bool
    {
        $workers = [];
        $users_sockets = SocketRooms::getUsersSockets($GLOBALS['socket_key'], $userIds);
        if ($has_photos) {
            // Resolve signer once for the whole fan-out — `$has_photos = true`
            // implies the host app has bound a PhotoSigner. If they didn't,
            // pass URLs through unchanged (dev-friendly).
            $signer = App::tryResolve(PhotoSignerInterface::class);

            foreach ($users_sockets as $id => $sockets) {
                try {
                    if (empty($sockets)) {
                        continue;
                    }
                    foreach ($sockets as $socket) {
                        $user_message = $message;

                        if (isset($user_message['data']['photos']) && $signer !== null) {
                            foreach ($user_message['data']['photos'] as $key => $photo_link) {
                                $user_message['data']['photos'][$key] = $key === 'profile_photo'
                                    ? $signer->signProfilePhoto((string) $photo_link, $socket['ip'])
                                    : ($photo_link ? $signer->sign((string) $photo_link, $socket['ip']) : null);
                            }
                        }
                        $workers[$socket['worker_id']][] = [
                            $socket['socket_id'],
                            [$user_message]
                        ];
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage());

                    continue;
                }
            }
            $is_text = false;
        } else {
            $message_json = json_encode($message,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach ($users_sockets as $id => $sockets) {
                try {
                    if (empty($sockets)) {
                        continue;
                    }
                    foreach ($sockets as $socket) {
                        $workers[$socket['worker_id']][] = [
                            $socket['socket_id'],
                            [$message_json]
                        ];
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    continue;
                }
            }
            $is_text = true;
        }


        foreach ($workers as $worker_id => $notifications) {
            self::sendToWorkerBatch($notifications, $worker_id,  $is_text);
        }
        return true;
    }
    /**
     * البث إلى غرفة
     */
    public static function broadcast(string $roomName, array $message): int
    {
        return self::$dispatcher ?
            self::$dispatcher->broadcastToRoom($roomName, $message) :
            0;
    }


    public static function MultiNotify(array $notifications, bool $online_check = false): void
    {
        $user_ids = [];

        $workers = [];
        foreach ($notifications as $notification) {
            if (!isset($notification['user_id'])) {
                continue;
            }
            $user_ids[] = $notification['user_id'];
        }

        $users_sockets = SocketRooms::getUsersSockets($GLOBALS['socket_key'], $user_ids);

        foreach ($notifications as $notification) {
            $sockets = $users_sockets[$notification['user_id']];
            if (empty($sockets)) {
                continue;
            }
            foreach ($sockets as $socket) {
                $workers[$socket['worker_id']][] = [$socket['socket_id'],[$notification['message']]];
            }
        }

        foreach ($workers as $worker_id => $notifications) {
            
            self::sendToWorkerBatch($notifications, $worker_id);
        }
        return;
    }
}
