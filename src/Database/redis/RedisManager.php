<?php

namespace Nour\Database\redis;

use Nour\Database\redis\Structures\KeyValue;
use Nour\Database\redis\Structures\Queue;
use Nour\Database\redis\Structures\Stack;
use Nour\Database\redis\Structures\SocketRooms;
use Swoole\Atomic;

class RedisManager
{
    public static Atomic $is_active;
    
    /**
     * تهيئة جميع الأنظمة مرة واحدة
     */
    public static function initializeAll(): array
    { 
        self::$is_active = new Atomic(1); 
        
        $results = [];
        
        // تهيئة المكدس
        $results['stack'] = Stack::initializeScripts();

        // تهيئة الطابور
        $results['queue'] = Queue::initializeScripts();
        
        // تهيئة تخزين المفاتيح
        $results['key_value'] = KeyValue::initializeScripts();
        
        // تهيئة غرف السوكيتات
        $results['socket_rooms'] = SocketRooms::initializeScripts();
        
        return $results;
    }
    
    /**
     * جبل حالة التهيئة مع معلومات الحدود
     */
    public static function getInitializationStatus(): array
    {
        return [
            'stack' => [
                'initialized' => !empty(Stack::getLoadedScripts()),
                'scripts' => Stack::getLoadedScripts(),
                'structure_type' => 'LIFO',
                'limits' => [
                    'max_memory_mb' => Stack::MAX_MEMORY_MB,
                    'max_items' => Stack::MAX_ITEMS
                ]
            ],
            'queue' => [
                'initialized' => !empty(Queue::getLoadedScripts()),
                'scripts' => Queue::getLoadedScripts(),
                'structure_type' => 'FIFO',
                'limits' => [
                    'max_memory_mb' => Queue::MAX_MEMORY_MB,
                    'max_items' => Queue::MAX_ITEMS
                ]
            ],
            'key_value' => [
                'initialized' => !empty(KeyValue::getLoadedScripts()),
                'scripts' => KeyValue::getLoadedScripts(),
                'structure_type' => 'KEY_VALUE',
                'limits' => [
                    'max_memory_mb' => KeyValue::MAX_MEMORY_MB,
                    'max_items' => KeyValue::MAX_ITEMS
                ]
            ],
            'socket_rooms' => [
                'initialized' => !empty(SocketRooms::getLoadedScripts()),
                'scripts' => SocketRooms::getLoadedScripts(),
                'structure_type' => 'SOCKET_ROOMS',
                'limits' => [
                    'max_memory_mb' => SocketRooms::MAX_MEMORY_MB,
                    'max_sockets_per_user' => SocketRooms::MAX_SOCKETS_PER_USER,
                    'max_rooms_per_socket' => SocketRooms::MAX_ROOMS_PER_SOCKET,
                    'heartbeat_warning' => SocketRooms::HEARTBEAT_WARNING_THRESHOLD,
                    'heartbeat_disconnect' => SocketRooms::HEARTBEAT_DISCONNECT_THRESHOLD
                ]
            ],

        ];
    }
    
    /**
     * مراقبة الحدود لجميع المفاتيح
     */
    public static function monitorLimits(array $keys): array
    {
        $results = [];
        
        foreach ($keys as $key) {
            $results[$key] = [
                'queue' => Queue::getStats($key),
                'stack' => Stack::getStats($key),
                'key_value' => KeyValue::getStats($key),
                'socket_rooms' => SocketRooms::getStats($key),
                'warnings' => [
                    'queue' => Queue::getWarnings($key, 5),
                    'stack' => Stack::getWarnings($key, 5),
                    'key_value' => KeyValue::getWarnings($key, 5)
                ]
            ];
        }
        
        return $results;
    }
    
    /**
     * تنظيف الذاكرة إذا تجاوزت الحدود
     */
    public static function enforceMemoryLimits(array $keys): array
    {
        $results = [];
        
        foreach ($keys as $key) {
            $results[$key] = [
                'queue' => Queue::autoCleanup($key),
                'stack' => Stack::autoCleanup($key),
            
                'key_value' => KeyValue::autoCleanup($key),
                'socket_rooms' => SocketRooms::cleanupInactiveSockets($key)
            ];
        }
        
        return $results;
    }
    
    /*
public static function startHeartbeatMonitoring(string $key, callable $warningCallback, callable $disconnectCallback): void
    {
        \Swoole\Timer::tick(30000, function () use ($key, $warningCallback, $disconnectCallback) {
            self::checkHeartbeats($key, $warningCallback, $disconnectCallback);
        });
    }
    
    /**
     * التحقق من Heartbeats
     */
    private static function checkHeartbeats(string $key, callable $warningCallback, callable $disconnectCallback): void
    {
        $warningSockets = SocketRooms::getStaleSockets($key, SocketRooms::HEARTBEAT_WARNING_THRESHOLD);
        $disconnectSockets = SocketRooms::getStaleSockets($key, SocketRooms::HEARTBEAT_DISCONNECT_THRESHOLD);
        
        // إرسال تحذيرات للسوكيتات المتأخرة
        foreach ($warningSockets as $socket) {
            if ($socket['last_heartbeat'] > 0) {
                $warningCallback($socket['socket_id'], $socket['nour_id'], $socket['worker_id']);
            }
        }
        
        // قطع اتصال السوكيتات المنتهية
        foreach ($disconnectSockets as $socket) {
            $disconnectCallback($socket['socket_id'], $socket['nour_id'], $socket['worker_id']);
        }
        
        // تنظيف السوكيتات غير النشطة
        SocketRooms::cleanupInactiveSockets($key);
    }
}
