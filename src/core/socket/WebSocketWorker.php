<?php

namespace Nour\core\socket;

use Nour\Database\redis\Structures\Queue;
use Swoole\Timer;
use Swoole\WebSocket\Server;
use Nour\Database\redis\Structures\SocketRooms;

/**
 * ⚡ Worker 0 فقط - للمهام الخلفية
 * 1. تنظيف السوكيتات الميتة
 * 2. مراقبة Heartbeats
 * 3. إحصائيات النظام
 */
class WebSocketWorker
{
    private static ?self $instance = null;
    private Server $server;
    private string $redisKey;

    // Config
    private const HEARTBEAT_CHECK_INTERVAL = 60 * 1000; // 10 ثواني
    private const CLEANUP_INTERVAL = 60000; // دقيقة
    private const STATS_INTERVAL = 60 * 60 * 1000; // ساعة
    private const BATCH_SIZE = 100;
    private const users_state_queue = 'nuor:user_last_seen';

    private function __construct(Server $server, string $redisKey = 'nuor:socket_system')
    {
        $this->server = $server;
        $this->redisKey = $GLOBALS['socket_key'];
    }

    public static function getInstance(Server $server, string $redisKey = 'nuor:socket_system'): self
    {
        if (self::$instance === null) {
            self::$instance = new self($server, $redisKey);
        }
        return self::$instance;
    }

    /**
     * بدء المهام الخلفية (يتم استدعاؤها من Worker 0 فقط)
     */
    public function start(): void
    {
        echo "🔧 WebSocketWorker started in Worker 0\n";

        // 1. مراقبة Heartbeats
        Timer::tick(self::HEARTBEAT_CHECK_INTERVAL, function () {
            $this->checkHeartbeats();
        });

        // 2. تنظيف دوري كل دقيقة
        /*Timer::tick(self::CLEANUP_INTERVAL, function () {
            $this->cleanup();
        });*/

        // 3. إحصائيات النظام كل 30 ثانية
        Timer::tick(self::STATS_INTERVAL, function () {
            $this->logStats();
        });
    }

    /**
     * التحقق من Heartbeats
     */
    private function checkHeartbeats(): void
    {
        try {
            // تحذير بعد 60 ثانية
            $warningSockets = SocketRooms::getStaleSockets(
                $this->redisKey,
                60,
                self::BATCH_SIZE
            );

            foreach ($warningSockets as $socket) {
                $this->sendHeartbeatWarning($socket);
            }

            // قطع الاتصال بعد 90 ثانية
            $disconnectSockets = SocketRooms::getStaleSockets(
                $this->redisKey,
                90,
                self::BATCH_SIZE
            );

            foreach ($disconnectSockets as $socket) {
                $this->forceDisconnect($socket);
            }

            if (count($warningSockets) > 0 || count($disconnectSockets) > 0) {
                $this->logEvent('heartbeat_check', [
                    'warnings' => count($warningSockets),
                    'disconnections' => count($disconnectSockets)
                ]);
            }
        } catch (\Exception $e) {
            error_log("Heartbeat check error: " . $e->getMessage());
        }
    }

    /**
     * إرسال تحذير Heartbeat
     */
    private function sendHeartbeatWarning(array $socket): void
    {
        //var_dump($socket);
        $socketId = $socket['socket_id'];
        $workerId = $socket['worker_id'];

        if ($workerId === 0) {
            // Worker 0 نفسه
            if ($this->server->exists($socketId)) {
                $this->server->push($socketId, json_encode(
                    [
                        'type' => 'heartbeat_warning',
                        'timestamp' => time()
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
            }
        } else {
            // إرسال إلى Worker المناسب
            $this->server->sendMessage([
                'type' => 'heartbeat_warning',
                'socket_id' => $socketId
            ], $workerId);
        }
    }

    /**
     * إجبار قطع الاتصال
     */
    private function forceDisconnect(array $socket): void
    {
        $socketId = $socket['socket_id'];
        $workerId = $socket['worker_id'];
        $nourId = $socket['nour_id'];
        if ($workerId === 0) {
            // Worker 0 نفسه
            if ($this->server->isEstablished($socketId)) {
                $this->server->close($socketId);
            } else {

                $res = SocketRooms::removeSocket($this->redisKey, $socketId);
                if (!$res) {
                    return ;
                }

                $socket_num = SocketRooms::getUserActiveConnectionsCount($this->redisKey, $nourId);

                if ($socket_num == 0) {
                    Queue::enqueue(self::users_state_queue, [
                        'nour_id' => $nourId,
                        'state' => 0,
                        'timestamp' => time()
                    ], 60 * 10);
                }

                return ;
            }
        } else {
            // إرسال إلى Worker المناسب
            $this->server->sendMessage([
                'type' => 'force_disconnect',
                'socket_id' => $socketId
            ], $workerId);
        }
    }

    /**
     * تنظيف النظام
     */
    private function cleanup(): void
    {
        try {
            $result = SocketRooms::cleanupInactiveSockets($this->redisKey);

            if ($result['disconnected'] > 0) {
                $this->logEvent('cleanup', [
                    'disconnected' => $result['disconnected'],
                    'users_cleaned' => $result['users_cleaned'] ?? 0
                ]);
            }
        } catch (\Exception $e) {
            error_log("Cleanup error: " . $e->getMessage());
        }
    }

    /**
     * تسجيل إحصائيات النظام
     */
    private function logStats(): void
    {
        try {
            $stats = SocketRooms::getStats($this->redisKey);

            $memory = memory_get_usage(true) / 1024 / 1024;
            $memoryPeak = memory_get_peak_usage(true) / 1024 / 1024;

            $stats['system'] = [
                'memory_usage_mb' => round($memory, 2),
                'memory_peak_mb' => round($memoryPeak, 2),
                'timestamp' => time(),
                'worker_id' => 0
            ];

            $this->logEvent('system_stats', $stats);
        } catch (\Exception $e) {
            error_log("Stats logging error: " . $e->getMessage());
        }
    }

    /**
     * تسجيل الأحداث
     */
    private function logEvent(string $event, array $data = []): void
    {
        $logData = array_merge([
            'event' => $event,
            'timestamp' => time(),
            'worker_id' => 0
        ], $data);

        error_log("WebSocketWorker: " . json_encode($logData));
    }

    /**
     * الحصول على إحصائيات النظام
     */
    public function getStats(): array
    {
        return [
            'background_tasks' => [
                'heartbeat_checks' => true,
                'cleanup' => true,
                'stats_logging' => true
            ],
            'intervals' => [
                'heartbeat_check_ms' => self::HEARTBEAT_CHECK_INTERVAL,
                'cleanup_ms' => self::CLEANUP_INTERVAL,
                'stats_ms' => self::STATS_INTERVAL
            ]
        ];
    }
}
