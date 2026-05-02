<?php
namespace Nour\core;
use Nour\Container\App;
use Nour\Events\TimerTickedEvent;
use Swoole\Table ;
use Swoole\Timer;
use Throwable;
final class Timers
{
    private static Table $timersTable;
    private static array $activeTimers = [];
    private static string $jsonPath = '';
    private static string $timersDir = '';


    public static function init()
    {
        // Resolve paths from $GLOBALS['main_folder'] (set by Nour\Server\Boot)
        // instead of __DIR__-relative paths — the framework now lives at
        // /opt/nour/ in Docker images while the host project sits at /app.
        $base = isset($GLOBALS['main_folder'])
            ? rtrim((string) $GLOBALS['main_folder'], "/\\")
            : dirname(__DIR__, 3); // legacy fallback when bundled in-place
        self::$jsonPath  = $base . '/data/Timers.json';
        self::$timersDir = $base . '/lib/timers/';

        self::make_table();
        self::reloadAll();         // قراءة أولية وتشغيل كل التايمرز
        //self::monitorChanges();    // تفعيل المراقبة لإعادة التحميل

    }
    private static function make_table(): void
    {
        // حجز مساحة لـ 256 مهمة
        self::$timersTable = new Table(256);
        self::$timersTable->column('path',    Table::TYPE_STRING, 256);
        self::$timersTable->column('interval', Table::TYPE_INT);
        self::$timersTable->create();
    }

    private static function loadToTable(): void
    {
        $data = @json_decode(file_get_contents(self::$jsonPath), true);
        if (!is_array($data)) {
            echo "[Timer Error] Invalid JSON in " . self::$jsonPath . "\n";
            return;
        }

        // نظف الجدول الحالي
        foreach (self::$timersTable as $key => $_) {
            self::$timersTable->del($key);
        }

        // أضف المهام للـ Table
        foreach ($data as $task) {
            if (empty($task['path']) || empty($task['interval'])) {
                echo "[Timer Warning] Skipping invalid task format\n";
                continue;
            }
            $intervalMs = (int)$task['interval'] * 1000;
            $tableKey   = md5($task['path']);
            $filePath   = realpath(self::$timersDir . $task['path']);

            self::$timersTable->set($tableKey, [
                'path'     => $filePath,
                'interval' => $intervalMs,
            ]);
        }
    }

    private static function registerAll(): void
    {
        // أولًا، نبني قائمة المهام الجديدة من الجدول
        $newTasks = [];
        foreach (self::$timersTable as $key => $row) {
            $newTasks[$key] = [
                'path'     => $row['path'],
                'interval' => $row['interval'],
            ];
        }

        // 1) حذف المؤقتات اللي اتمسحت من JSON
        foreach (self::$activeTimers as $key => $meta) {
            if (!isset($newTasks[$key])) {
                // مسح المؤقت القديم
                Timer::clear($meta['timerId']);
                unset(self::$activeTimers[$key]);
                echo "[Timer] Removed task {$key}\n";
            }
        }

        // 2) تحديث المؤقتات اللي اتغيرت أوقات تكرارها
        foreach ($newTasks as $key => $task) {
            if (
                isset(self::$activeTimers[$key])
                && self::$activeTimers[$key]['interval'] !== $task['interval']
            ) {
                // مسح القديم
                Timer::clear(self::$activeTimers[$key]['timerId']);
                unset(self::$activeTimers[$key]);
                echo "[Timer] Interval changed for {$key}, re-registering\n";
            }
        }

        // 3) إضافة المؤقتات اللي جديدة (مش مسجلة حاليًا)
        foreach ($newTasks as $key => $task) {
            if (!isset(self::$activeTimers[$key])) {
                $path     = $task['path'];
                $interval = $task['interval'];

                if (!file_exists($path)) {
                    echo "[Timer Error] File not found: {$path}\n";
                    continue;
                }

                // نحمّل الـ Closure من الملف
                $handler = require $path;
                if (!is_callable($handler)) {
                    echo "[Timer Error] Task at {$path} did not return a callable\n";
                    continue;
                }

                // تسجيل المؤقت
                $timerId = Timer::tick($interval, function () use ($handler, $path, $interval) {
                    //echo "[Timer] Running task from {$path} at " . date('Y-m-d H:i:s') . "\n";
                    $startedAt = hrtime(true);
                    $error     = null;
                    try {
                        $handler();
                    } catch (\Throwable $e) {
                        $error = $e->getMessage();
                        echo "[Timer Error] Exception in task from {$path}: " . $error . "\n";
                    }

                    // Fire TimerTickedEvent — best-effort. Listener
                    // throws are swallowed by Dispatcher; a missing
                    // container (very early boot) means we just skip.
                    try {
                        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
                        App::events()->dispatch(new TimerTickedEvent(
                            $path, $interval, $durationMs, $error,
                        ));
                    } catch (Throwable $e) {
                        error_log('[Timer] TimerTickedEvent dispatch threw: ' . $e->getMessage());
                    }
                });

                // حفظ بيانات المؤقت
                self::$activeTimers[$key] = [
                    'timerId'  => $timerId,
                    'interval' => $interval,
                ];
                echo "[Timer] Registered new task {$key} from {$path} every " . ($interval / 3600000) . "h\n";
            }
        }
    }


    public static function reloadAll(): void
    {
        self::loadToTable();
        self::registerAll();
    }

    private static function monitorChanges(): void
    {
        static $lastChecksum = '';
        // كل 5 ثواني نفحص الملف
        Timer::tick(5000, function () use (&$lastChecksum) {
            clearstatcache(true, self::$jsonPath);
            $checksum = @md5_file(self::$jsonPath) ?: '';
            if ($checksum !== $lastChecksum) {
                echo "[Timer] Detected change in Timers.json, reloading...\n";
                self::reloadAll();
                $lastChecksum = $checksum;
            }
        });
    }
}