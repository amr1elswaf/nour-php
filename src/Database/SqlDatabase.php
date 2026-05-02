<?php

namespace Nour\Database;

use Swoole\Database\MysqliConfig;
use Swoole\Database\MysqliPool;
use Swoole\Database\MysqliProxy;
use Throwable;

final class SqlDatabase
{
    private static ?MysqliPool $pool = null;
    private static ?array $config = null;
    private static ?string $configFile = null;

    private static int $poolSize = 32;

    // لو الاتصال قعد أكتر من الرقم ده بالثواني من غير استخدام
    // هنعمله ping قبل ما نرجّعه للكول باك، عشان نضمن انه حي
    private static float $idlePingThreshold = 20.0;

    // الحد الأقصى لمحاولات جلب اتصال حي من البول قبل الاستسلام
    private static int $maxAcquireAttempts = 3;

    // آخر وقت استخدام لكل اتصال (spl_object_id => microtime)
    private static array $lastUsed = [];

    private function __construct() {}

    /**
     * Is MySQL configured to be used by this app?
     *
     * Honours the optional `enabled` flag on `db_api_user`. When the flag
     * is `false`, the pool is never built and `withConnection` short-circuits
     * to a null callback argument — handlers that need a DB will fail loudly,
     * and handlers that don't can keep working.
     *
     * Returns `false` when:
     *  - `$GLOBALS['setting']['db_api_user']` is missing entirely, OR
     *  - it has `enabled: false`.
     */
    public static function isEnabled(): bool
    {
        $cfg = $GLOBALS['setting']['db_api_user'] ?? null;
        if (!is_array($cfg)) return false;
        if (array_key_exists('enabled', $cfg) && $cfg['enabled'] === false) {
            return false;
        }
        return true;
    }

    public static function init(?string $configFile = null, int $poolSize = 32): void
    {
        if (!self::isEnabled()) {
            // No-op — caller should gate withConnection on isEnabled().
            return;
        }
        self::$configFile = $configFile ?: realpath(__DIR__ . "/../../../data/sitting.json");
        self::$poolSize = max(1, $poolSize);
        self::reloadConfig();
        self::createPool();
    }

    private static function reloadConfig(): void
    {
        $settings = $GLOBALS['setting'];

        if (!$settings || !isset($settings['db_api_user'])) {
            throw new \RuntimeException("Error: Unable to load database settings.");
        }

        self::$config = $settings['db_api_user'];
    }

    private static function createPool(): void
    {
        self::$pool = new MysqliPool(
            (new MysqliConfig)
                ->withHost(self::$config['host'])
                ->withPort(3306)
                ->withUsername(self::$config['user'])
                ->withPassword(self::$config['password'])
                ->withDbName(self::$config['db'])
                ->withCharset('utf8mb4'),
            self::$poolSize
        );
        self::$lastUsed = [];
    }

    public static function rest(): void
    {
        if (self::$pool) {
            self::$pool->close();
            self::$lastUsed = [];
            echo "تم اغلاق البول القديم\n";
        }

        self::reloadConfig();
        self::createPool();

        echo "تم إنشاء بول جديد بنجاح\n";
    }

    public static function checkMySQLConnection(): bool
    {
        try {
            self::reloadConfig();

            $test = @new \mysqli(
                self::$config['host'],
                self::$config['user'],
                self::$config['password'],
                self::$config['db'],
                3306
            );

            if ($test->connect_error) {
                return false;
            }

            $test->close();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function isGoneAwayError(int $errno, string $error): bool
    {
        if ($errno === 2006 || $errno === 2013 || $errno === 2002 || $errno === 1927) {
            return true;
        }
        if ($error === '') {
            return false;
        }
        return (strpos($error, 'MySQL server has gone away') !== false)
            || (strpos($error, 'server has gone away') !== false)
            || (strpos($error, 'Lost connection to MySQL server') !== false)
            || (strpos($error, 'Connection was killed') !== false);
    }

    /**
     * يتأكد إن الاتصال لسه حي. لو مش حي بيقفله ويرجع null.
     * بنعمل ping بس لو الاتصال قاعد من غير استخدام لفترة طويلة.
     */
    private static function validateConnection(MysqliProxy $db): ?MysqliProxy
    {
        $id = spl_object_id($db);
        $last = self::$lastUsed[$id] ?? 0.0;
        $now = microtime(true);

        // اتصال جديد أو مستخدم حديثًا → منعملش ping (سريع)
        if ($last > 0.0 && ($now - $last) < self::$idlePingThreshold) {
            return $db;
        }

        try {
            $ok = @$db->ping();
            if ($ok === false) {
                return null;
            }
            if (self::isGoneAwayError((int) $db->errno, (string) $db->error)) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }

        return $db;
    }

    /**
     * بيجيب اتصال حي من البول. لو لقى اتصال ميت بيرميه ويجيب غيره.
     */
    private static function acquire(): ?MysqliProxy
    {
        if (self::$pool === null) {
            return null;
        }

        for ($i = 0; $i < self::$maxAcquireAttempts; $i++) {
            try {
                $db = self::$pool->get();
            } catch (Throwable $e) {
                echo "SqlDatabase acquire error: " . $e->getMessage() . "\n";
                return null;
            }

            if (!$db) {
                return null;
            }

            $valid = self::validateConnection($db);
            if ($valid !== null) {
                return $valid;
            }

            // اتصال ميت - اقفله وخلي البول يعمل واحد جديد
            self::discard($db);
        }

        return null;
    }

    /**
     * بيرمي الاتصال من البول (بيقفله) ويبلغ البول إنه يعمل واحد جديد مكانه.
     */
    private static function discard(MysqliProxy $db): void
    {
        unset(self::$lastUsed[spl_object_id($db)]);
        try {
            $db->close();
        } catch (Throwable $e) {
            // ignore
        }
        try {
            // put(null) بيخلي البول يعرف إن الـ slot ده ممكن يتعمله اتصال جديد
            self::$pool->put(null);
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * بيرجع الاتصال للبول مع إعادة ضبط الـ DB الافتراضي.
     * لو الاتصال مش سليم بنرميه بدل ما نرجعه.
     */
    private static function release(MysqliProxy $db, bool $healthy): void
    {
        $id = spl_object_id($db);

        if (!$healthy || self::isGoneAwayError((int) $db->errno, (string) $db->error)) {
            self::discard($db);
            return;
        }

        try {
            // ارجع للـ DB الافتراضي لأن الكود ممكن يكون عمل select_db لحاجة تانية
            $defaultDb = self::$config['db'] ?? null;
            if ($defaultDb) {
                @$db->select_db($defaultDb);
            }
            self::$lastUsed[$id] = microtime(true);
            self::$pool->put($db);
        } catch (Throwable $e) {
            self::discard($db);
        }
    }

    /**
     * بيشغل الـ callback مع اتصال حي من البول.
     * بيشتغل synchronously في نفس الـ coroutine الحالي وبيرجّع اللي الـ callback رجّعه.
     *
     * ملاحظة: مش بيعمل Coroutine::create تاني. لازم تكون جوه coroutine بالفعل
     * (HTTP handler / WebSocket handler / Timer مع enable_coroutine / Task مع task_enable_coroutine).
     */
    public static function withConnection(callable $callback)
    {
        $db = self::acquire();
        if ($db === null) {
            echo "❌ Could not acquire MySQL connection from pool\n";
            return null;
        }

        $healthy = true;
        try {
            return $callback($db);
        } catch (Throwable $e) {
            // لو الـ exception بسبب اتصال مقطوع، ميرجعش للبول
            $healthy = !self::isGoneAwayError((int) $db->errno, (string) $db->error);
            echo "SqlDatabase withConnection error: " . $e->getMessage() . "\n";
            return null;
        } finally {
            self::release($db, $healthy);
        }
    }

    public static function is_check_error(MysqliProxy $db): void
    {
        if (!$db->errno) {
            return;
        }

        if (self::isGoneAwayError((int) $db->errno, (string) $db->error)) {
            echo "🔁 محاولة إعادة الاتصال بقاعدة البيانات...\n";
            if (self::checkMySQLConnection()) {
                self::rest();
            } else {
                echo "❌ MySQL لا يزال غير متاح.\n";
            }
        }
    }
}
