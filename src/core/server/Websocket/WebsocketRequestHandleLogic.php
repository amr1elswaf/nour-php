<?php

namespace Nour\core\server\Websocket;

use Nour\Container\App;
use Nour\Contracts\WebSocket\SocketStoreInterface;
use Nour\core\socket\GlobalRegistry;
use Nour\core\socket\SocketMain;
use Nour\core\socket\SocketManager;
use Nour\Database\redis\Structures\Queue;
use Nour\Database\RedisDatabase;
use Nour\Database\SqlDatabase;
use Nour\WebSocket\Events\ConnectionClosedEvent;
use Nour\WebSocket\Events\ConnectionOpenedEvent;
use Nour\WebSocket\Events\HandshakeEvent;
use Nour\WebSocket\Events\MessageReceivedEvent;
use Swoole\Coroutine;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;

/**
 * ⚡ معالج أحداث السوكيت (مصحح - بدون static manager مشترك)
 */
class WebsocketRequestHandleLogic
{
    // ⭐ الحل: استخدام مصفوفة بمفتاح worker_id بدلاً من static واحد
    private static array $managers = [];
    private static array $redisKeys = [];

    /**
     * تهيئة WebsocketRequestHandleLogic لكل Worker
     */
    public static function initialize(Server $server, string $redisKey = 'nuor:socket_system'): void
    {
        $workerId = $server->worker_id;

        // ⭐ كل Worker يحصل على manager خاص به
        self::$managers[$workerId] = new SocketManager($server, $redisKey);
        self::$redisKeys[$workerId] = $redisKey;

        //echo "✅ WebsocketRequestHandleLogic initialized for Worker #{$workerId}\n";
    }

    /**
     * الحصول على Manager للـ Worker الحالي
     */
    private static function getManager(Server $server): ?SocketManager
    {
        $workerId = $server->worker_id;
        return self::$managers[$workerId] ?? null;
    }

    /**
     * الحصول على Redis key للـ Worker الحالي
     */
    private static function getRedisKey(Server $server): string
    {
        $workerId = $server->worker_id;
        return self::$redisKeys[$workerId] ?? 'nuor:socket_system';
    }

    /**
     * Handshake — generic event-driven flow.
     *
     * The framework only:
     *   1. extracts the optional API key,
     *   2. fires {@see HandshakeEvent} so listeners can authenticate
     *      / authorise / attach user data,
     *   3. checks duplicate-connection + per-user cap via the
     *      {@see SocketStoreInterface} (no gooabb-specific calls),
     *   4. completes the handshake and fires {@see ConnectionOpenedEvent}.
     *
     * Apps with no listener registered get an "anonymous accepted"
     * flow — fine for public WS feeds. Apps that need auth register
     * a HandshakeEvent listener that calls `$e->reject(...)` +
     * `$e->stopPropagation()` for invalid clients.
     */
    public static function onHandshake(Request $request, Response $response): void
    {
        try {
            $server = self::getServerFromRequest($request);
            if (!$server) {
                self::rejectHandshake($response, 500, 'Server not available');
                return;
            }
            $manager = self::getManager($server);
            if (!$manager) {
                self::rejectHandshake($response, 503, 'SocketManager not initialized');
                return;
            }

            $apiKey = self::extractApiKey($request);
            $ip     = self::getClientIp($request);

            // 1. Fire HandshakeEvent. Listeners attach user identity
            //    or reject with rejection code/message.
            $event = new HandshakeEvent($request, $ip, $apiKey);
            App::events()->dispatch($event);

            if ($event->isRejected()) {
                self::rejectHandshake(
                    $response,
                    $event->getRejectionCode(),
                    $event->getRejectionMessage()
                );
                return;
            }

            $userId   = $event->getUserId();
            $token    = $event->getToken();
            $userData = $event->getUserData();
            $store    = App::socketStore();

            // 2. Best-effort: log connection attempt rate per IP.
            self::logConnectionAttempt($request, $ip);

            // 3. Duplicate-connection check (token-keyed).
            if ($token !== '') {
                $existing = $store->checkToken($token);
                if ($existing !== null) {
                    $isActive = $manager->is_active(
                        (int) $existing['socket_id'],
                        (int) $existing['worker_id'],
                        is_int($userId) ? $userId : 0
                    );
                    if ($isActive) {
                        self::rejectHandshake($response, 409, 'Duplicate active connection');
                        return;
                    }
                }
            }

            // 4. Per-user connection cap (10 by default; apps can
            //    enforce stricter limits in the HandshakeEvent listener).
            if (($userId !== 0 && $userId !== '') &&
                $store->getUserConnectionsCount($userId) >= 10) {
                self::rejectHandshake($response, 429, 'Maximum connections exceeded');
                return;
            }

            // 5. Complete the handshake.
            self::completeManualHandshakeWithOpen(
                $request,
                $response,
                $userId,
                $token,
                $userData,
                $manager,
                $server
            );
        } catch (\Exception $e) {
            $response->status(500);
            $response->end('Internal server error');
        }
    }

    /**
     * ⭐ دالة جديدة: الحصول على الـ server من الـ request
     */
    private static function getServerFromRequest(Request $request): ?Server
    {
        // محاولة 1: من خلال خاصية server في الـ request
        if (isset($request->server['server']) && $request->server['server'] instanceof Server) {
            return $request->server['server'];
        }

        // محاولة 2: من خلال خاصية manager (إذا كانت موجودة)
        $workerId = $request->server['worker_id'] ?? null;
        if ($workerId !== null && isset(self::$managers[$workerId])) {
            return self::$managers[$workerId]->server ?? null;
        }

        // محاولة 3: البحث في الـ managers
        foreach (self::$managers as $manager) {
            if ($manager && isset($manager->server)) {
                return $manager->server;
            }
        }

        return null;
    }



    /**
     * Per-IP connection-attempt counter. Best-effort, fails silently
     * when Redis is unavailable.
     */
    private static function logConnectionAttempt(Request $request, string $ip): void
    {
        $redis = RedisDatabase::get();
        if ($redis === null) {
            return;
        }
        try {
            $logKey   = "connection_attempts:{$ip}";
            $attempts = $redis->incr($logKey);
            $redis->expire($logKey, 3600);
            if ($attempts > 100) {
                error_log("🚨 Rate limiting for IP: {$ip}, Attempts: {$attempts}");
            }
        } catch (\Throwable $e) {
            // ignore
        } finally {
            RedisDatabase::put($redis);
        }
    }

    /**
     * Manual handshake + immediate connection processing.
     *
     * @param int|string $userId      from HandshakeEvent
     * @param string $token           from HandshakeEvent
     * @param array<string, mixed> $userData  from HandshakeEvent
     */
    private static function completeManualHandshakeWithOpen(
        Request $request,
        Response $response,
        int|string $userId,
        string $token,
        array $userData,
        SocketManager $manager,
        Server $server
    ): void {
        $fd = $request->fd;
        $secKey = $request->header['sec-websocket-key'] ?? '';

        if (empty($secKey)) {
            $response->status(400);
            $response->end('Missing Sec-WebSocket-Key');
            return;
        }

        $acceptKey = base64_encode(sha1(
            $secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true
        ));

        $response->header('Upgrade', 'websocket');
        $response->header('Connection', 'Upgrade');
        $response->header('Sec-WebSocket-Accept', $acceptKey);
        $response->header('Sec-WebSocket-Version', '13');
        // Echo back whatever id the app attached, when it's an int
        // — string ids are typed-tagged and may not be header-safe.
        if (is_int($userId)) {
            $response->header('X-User-ID', (string) $userId);
        }

        $response->status(101);
        $response->end();

        self::processConnectionAfterHandshake(
            $fd,
            $userId,
            $token,
            $userData,
            $request,
            $manager,
            $server
        );
    }

    /**
     * Register the new socket + send the welcome frame + fire
     * {@see ConnectionOpenedEvent}.
     *
     * @param int|string $userId
     * @param array<string, mixed> $userData
     */
    private static function processConnectionAfterHandshake(
        int $fd,
        int|string $userId,
        string $token,
        array $userData,
        Request $originalRequest,
        SocketManager $manager,
        Server $server
    ): void {
        if (!$manager) {
            return;
        }

        $workerId = $server->worker_id;
        $ip = self::getClientIp($originalRequest);

        // 1. Register the socket via the manager (which delegates to
        //    the bound SocketStoreInterface).
        $success = $manager->addSocketGeneric($fd, $userId, $token, $ip, $userData);

        if ($success) {
            // 2. Send welcome frame.
            $welcomeMsg = [
                'type'           => 'welcome',
                'socket_id'      => $fd,
                'user_id'        => $userId,
                'worker_id'      => $workerId,
                'timestamp'      => time(),
                'ip'             => $ip,
                'handshake_mode' => 'manual',
            ];
            $server->push($fd, json_encode($welcomeMsg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 3. Fire ConnectionOpenedEvent — listeners can run side
            //    effects (presence updates, monitoring counters, …).
            App::events()->dispatch(new ConnectionOpenedEvent(
                socketId: $fd,
                workerId: $workerId,
                ip:       $ip,
                userId:   $userId,
                token:    $token,
                userData: $userData,
            ));

            // 4. Best-effort connection-info log to Redis.
            self::logConnection($fd, $userId, $workerId, $ip);
        } else {
            $server->close($fd);
        }
    }

    /**
     * Connection-info log — best-effort hash entry per active fd.
     */
    private static function logConnection(int $fd, int|string $userId, int $workerId, string $ip): void
    {
        $redis = RedisDatabase::get();
        if ($redis === null) {
            return;
        }
        try {
            $key = "connection:{$fd}";
            $redis->hMSet($key, [
                'user_id'      => is_int($userId) ? $userId : (string) $userId,
                'worker_id'    => $workerId,
                'connected_at' => time(),
            ]);
            $redis->expire($key, 3600);
        } catch (\Throwable $e) {
            // ignore
        } finally {
            RedisDatabase::put($redis);
        }
    }

    /**
     * رفض مصافحة WebSocket
     */
    private static function rejectHandshake(Response $response, int $code, string $message): void
    {
        $response->status($code);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'error' => true,
            'code' => $code,
            'message' => $message,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Legacy `onOpen` — Swoole's built-in event when handshake is
     * automatic (no manual response). The framework's default flow
     * uses {@see onHandshake} + {@see processConnectionAfterHandshake}
     * instead, so this is rarely reached. Kept for safety in case a
     * deployment switches back to auto-handshake mode.
     *
     * Reads `$request->server['data']` for whatever the (custom)
     * handshake stage may have stashed — at minimum `user_id`,
     * `token`, `ip`, plus any `user_data` keys.
     */
    public static function onOpen(Server $server, Request $request): void
    {
        $manager = self::getManager($server);
        if (!$manager) {
            $server->close($request->fd);
            error_log("SocketManager not found for Worker #{$server->worker_id}");
            return;
        }

        $handshakeData = $request->server['data'] ?? [];
        if (empty($handshakeData)) {
            $server->close($request->fd);
            return;
        }

        $userId   = $handshakeData['user_id'] ?? 0;
        $token    = (string) ($handshakeData['token'] ?? $handshakeData['api_key'] ?? '');
        $ip       = (string) ($handshakeData['ip'] ?? '');
        $userData = $handshakeData['user_data'] ?? [];
        $fd       = $request->fd;
        $workerId = $server->worker_id;

        $success = $manager->addSocketGeneric($fd, $userId, $token, $ip, $userData);
        if ($success) {
            $server->push($fd, json_encode([
                'type'      => 'welcome',
                'socket_id' => $fd,
                'user_id'   => $userId,
                'worker_id' => $workerId,
                'timestamp' => time(),
                'ip'        => $ip,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            App::events()->dispatch(new ConnectionOpenedEvent(
                socketId: $fd,
                workerId: $workerId,
                ip:       $ip,
                userId:   $userId,
                token:    $token,
                userData: $userData,
            ));
        } else {
            $server->close($fd);
            error_log("Failed to add socket in onOpen for FD: {$fd}");
        }
    }

    /**
     * استخراج API key من الطلب
     */
    private static function extractApiKey(Request $request): string
    {
        $apiKey = '';

        // 1. من query string ?api_key=
        if (isset($request->get['api-key'])) {
            $apiKey = trim($request->get['api-key']);
        }
        // 2. من header Authorization
        elseif (isset($request->header['authorization'])) {
            $apiKey = str_replace('Bearer ', '', $request->header['authorization']);
        }
        // 3. من WebSocket subprotocol
        elseif (isset($request->header['sec-websocket-protocol'])) {
            $apiKey = trim($request->header['sec-websocket-protocol']);
        }
        // 4. من cookies
        elseif (isset($request->cookie['api_key'])) {
            $apiKey = trim($request->cookie['api_key']);
        }

        return $apiKey;
    }

    /**
     * Inbound frame handler.
     *
     *  1. Parse JSON — invalid → 'error' frame back, return.
     *  2. Fire {@see MessageReceivedEvent}. Listeners can mutate
     *     the parsed data or `stopPropagation()` to skip dispatch
     *     (rate-limit, audit-only, maintenance).
     *  3. Dispatch via {@see handleMessage} (ping / heartbeat /
     *     command).
     */
    public static function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $manger = self::getManager($server);

        try {
            $data = json_decode(json: $frame->data, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $manger->sendMessage($fd, ['type' => 'error', "data" => ['error' => 'Invalid JSON data']]);
            return;
        }

        // Fire the lifecycle event. Listeners may rewrite $data or
        // veto the frame entirely.
        $event = new MessageReceivedEvent(
            socketId:  $fd,
            rawFrame:  (string) $frame->data,
            data:      $data,
            frameType: (string) ($data['type'] ?? 'unknown'),
        );
        App::events()->dispatch($event);
        if ($event->isPropagationStopped()) {
            return;
        }
        $data = $event->getData();

        try {
            self::handleMessage($fd, $data, $manger);
        } catch (\Exception $e) {
            error_log("Message error: " . $e->getMessage());
            $manger->sendMessage(
                $fd,
                ['type' => 'error', 'data' => ['error' => 'Internal error']]
            );
        }
    }


    /**
     * معالجة أنواع الرسائل
     */
    private static function handleMessage(int $socketId, array $data, SocketManager $manger)
    {
        //var_dump($data);
        $type = $data['type'] ?? 'unknown';
        switch ($type) {
            case 'ping':
                $manger->sendMessage($socketId, ['type' => 'pong', 'timestamp' => time()]);
                return;

            case 'heartbeat':
                $manger->sendMessage($socketId, ['type' => 'heartbeat_ack', 'timestamp' => time()]);
                $manger->updateHeartbeat($socketId);
                return;
            case 'command':
                if (empty($data['body']) or !is_array($data['body'])) {
                    $manger->sendMessage($socketId, ['type' => 'error', ['error' => 'unfinded data , or not an array', 'timestamp' => time()]]);
                    return;
                }
                SqlDatabase::withConnection(function ($db) use ($data, $manger, $socketId) {
                    SocketMain::start($data['body'], $db, $socketId, $manger);
                });
                return ;
        }
        $manger->sendMessage($socketId, ['type' => 'unknown_command', 'received' => $type]);
        return;
    }

    /**
     * Connection close handler. Snapshots the socket data BEFORE
     * the manager removes it, so listeners on
     * {@see ConnectionClosedEvent} can still see who was connected.
     */
    public static function onClose(Server $server, int $fd): void
    {
        $manager = self::getManager($server);
        if (!$manager) {
            error_log("SocketManager not found for closing FD: {$fd}");
            return;
        }

        // Capture the stored info first — after check_user_stuts
        // removes the socket, the store will return null.
        $info = App::socketStore()->getSocketInfo($fd);

        $manager->check_user_stuts($fd);
        $manager->remove_from_local_sockets($fd);

        if ($info !== null) {
            App::events()->dispatch(new ConnectionClosedEvent(
                socketId: $fd,
                workerId: (int) ($info['worker_id'] ?? $server->worker_id),
                userId:   $info['user_id'] ?? 0,
                token:    (string) ($info['token'] ?? ''),
                userData: $info['user_data'] ?? [],
            ));
        }
    }

    /**
     * معالجة رسائل IPC
     */
    public static function onWorkerMessage(Server $server, array $message)
    {
        $manager = self::getManager($server);
        if ($manager) {
            return $manager->handleIPC($message);
        }
        return false;
    }

    /**
     * الحصول على IP العميل — يفوّض لـ ClientIp::fromRequest اللي بيتحقق
     * من إن الـ proxy موثوق قبل ما يقرأ X-Forwarded-For/CF-Connecting-IP.
     * شوف C5 في الـ audit.
     */
    private static function getClientIp(Request $request): string
    {
        return \Nour\helpers\ClientIp::fromRequest($request);
    }



    /**
     * ⭐ دالة جديدة: الحصول على إحصائيات الـ Workers
     */
    public static function getWorkersStats(): array
    {
        $stats = [];
        foreach (self::$managers as $workerId => $manager) {
            if ($manager && isset($manager->server)) {
                $stats[$workerId] = [
                    'manager_exists' => true,
                    'server_worker_id' => $manager->server->worker_id,
                    'redis_key' => self::$redisKeys[$workerId] ?? 'unknown'
                ];
            } else {
                $stats[$workerId] = ['manager_exists' => false];
            }
        }
        return $stats;
    }
}
