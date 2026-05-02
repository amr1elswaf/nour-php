<?php

namespace Nour\Database;

use Exception;
use Nour\Database\redis\RedisManager;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
use Swoole\Coroutine;
use Swoole\Timer;
use Swoole\Atomic;
use Redis;
use RedisException;
use Throwable;

final class RedisDatabase
{
    private static ?RedisPool $pool = null;
    private static ?array $config = null;

    /** @var Atomic|null 0 = OK, 1 = error */
    public static ?Atomic $is_error = null;

    /** @var Atomic|null 0 = scripts missing, 1 = loaded */
    private static ?Atomic $script_initialized = null;

    private static bool $reconnectTaskRunning = false;

    private static int $poolSize = 32;

    // لو الاتصال قعد بدون استخدام أكتر من كده بالثواني هنعمله ping قبل الإرجاع للكول باك.
    // قيمة منخفضة (3s) بتقلل احتمال إن الاتصال يكون بايت من غير ما نلاحظ — مهم لو Redis
    // عمل restart أو الشبكة قطعت لثانية.
    private static float $idlePingThreshold = 3.0;

    private static int $maxAcquireAttempts = 3;

    /** @var array<int, float> spl_object_id => last used microtime */
    private static array $lastUsed = [];

    /** @var string run_id آخر Redis شفناه. لو اتغير معناه Redis عمل restart */
    private static string $lastRunId = '';

    private function __construct() {}

    /**
     * Is Redis configured to be used by this app?
     *
     * Mirrors {@see SqlDatabase::isEnabled()} — defaults to true when a
     * `redis` block exists, false when missing or explicitly disabled.
     * Helpers that need Redis ({@see \Nour\Helpers\RateLimiter},
     * {@see \Nour\Helpers\BlockIp}) check this first and degrade
     * gracefully rather than throw.
     */
    public static function isEnabled(): bool
    {
        $cfg = $GLOBALS['setting']['redis'] ?? null;
        if (!is_array($cfg)) return false;
        if (array_key_exists('enabled', $cfg) && $cfg['enabled'] === false) {
            return false;
        }
        return true;
    }

    public static function init(?string $configFile = null, int $poolSize = 32): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::$is_error = new Atomic(0);
        self::$script_initialized = new Atomic(0);
        self::$poolSize = max(1, $poolSize);

        self::reloadConfig();
        self::createPool();
    }

    private static function reloadConfig(): void
    {
        $settings = $GLOBALS['setting'];
        if (!$settings || !isset($settings['redis'])) {
            throw new \RuntimeException("Error: Unable to load Redis settings.");
        }
        self::$config = $settings['redis'];
    }

    private static function createPool(): void
    {
        $config = (new RedisConfig())
            ->withHost(self::$config['host'])
            ->withPort(self::$config['port'] ?? 6379)
            ->withDbIndex(self::$config['db_index'] ?? 0)
            ->withTimeout(self::$config['connect_timeout'] ?? 1);

        if (!empty(self::$config['auth'])) {
            $config->withAuth(self::$config['auth']);
        }

        self::$pool = new RedisPool($config, self::$poolSize);
        self::$lastUsed = [];
    }

    public static function rest(): void
    {
        if (self::$pool) {
            try {
                self::$pool->close();
            } catch (Throwable $e) {
                // ignore
            }
            self::$lastUsed = [];
            echo "تم اغلاق البول القديم\n";
        }

        self::reloadConfig();
        self::createPool();
        self::$lastRunId = '';
        echo "تم إنشاء بول Redis جديد بنجاح\n";
    }

    public static function checkRedisConnection(): bool
    {
        try {
            self::reloadConfig();
            $redis = new \Redis();
            $connected = @$redis->connect(
                self::$config['host'],
                self::$config['port'] ?? 6379,
                self::$config['timeout'] ?? 0.5
            );

            if (!$connected) {
                return false;
            }

            if (!empty(self::$config['auth'])) {
                if (!@$redis->auth(self::$config['auth'])) {
                    return false;
                }
            }

            $pingResult = @$redis->ping();
            @$redis->close();
            return $pingResult === true || $pingResult === '+PONG' || $pingResult === 'PONG';
        } catch (Throwable $e) {
            echo "خطأ في التحقق من اتصال Redis: " . $e->getMessage() . "\n";
            self::$is_error?->set(1);
            return false;
        }
    }

    /**
     * بيتأكد إن الاتصال لسه حي. بيعمل ping بس لو الاتصال قاعد من غير استخدام لفترة طويلة.
     */
    private static function validateConnection(Redis $redis): bool
    {
        $id = spl_object_id($redis);
        $last = self::$lastUsed[$id] ?? 0.0;
        $now = microtime(true);

        if ($last > 0.0 && ($now - $last) < self::$idlePingThreshold) {
            return true;
        }

        try {
            $result = @$redis->ping();
            return $result === true || $result === '+PONG' || $result === 'PONG';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * بيجيب اتصال حي من البول. لو الاتصال ميت بيرميه ويجيب غيره.
     */
    private static function acquire(): ?Redis
    {
        if (self::$pool === null) {
            return null;
        }

        if (self::$is_error && self::$is_error->get() === 1) {
            return null;
        }

        for ($i = 0; $i < self::$maxAcquireAttempts; $i++) {
            try {
                $redis = self::$pool->get();
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'php_network_getaddresses') !== false) {
                    echo "❌ Redis واقع (DNS/Network error)\n";
                }
                self::$is_error?->set(1);
                return null;
            }

            if (!$redis) {
                return null;
            }

            if (self::validateConnection($redis)) {
                return $redis;
            }

            // اتصال ميت - نرميه ونخلي البول يعمل واحد جديد
            self::discard($redis);
        }

        self::$is_error?->set(1);
        return null;
    }

    /**
     * بيرمي الاتصال ويبلغ البول إنه يقدر يعمل واحد جديد مكانه.
     */
    private static function discard(Redis $redis): void
    {
        unset(self::$lastUsed[spl_object_id($redis)]);
        try {
            $redis->close();
        } catch (Throwable $e) {
            // ignore
        }
        try {
            self::$pool->put(null);
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * جلب اتصال Redis من البول. بيرجع null لو البول مش متاح أو Redis فاصل.
     */
    public static function get(): ?Redis
    {
        return self::acquire();
    }

    /**
     * إرجاع اتصال للبول. لو الاتصال ميت بنرميه (بـ put(null)) عشان ما نسربش سلوتس من البول.
     */
    public static function put(?Redis $redis): void
    {
        if ($redis === null || self::$pool === null) {
            return;
        }

        try {
            // ping خفيف بدون coroutine switch زيادة
            $ok = @$redis->ping();
            if ($ok === true || $ok === '+PONG' || $ok === 'PONG') {
                self::$lastUsed[spl_object_id($redis)] = microtime(true);
                self::$pool->put($redis);
                return;
            }
        } catch (Throwable $e) {
            // ignore - هنرميه تحت
        }

        self::discard($redis);
    }

    /**
     * بيشغل الـ callback مع اتصال Redis حي من البول synchronously.
     * بيرجع قيمة الكول باك. لو الاتصال فشل بيرجع null.
     */
    public static function withConnection(callable $callback)
    {
        $redis = self::acquire();
        if ($redis === null) {
            return null;
        }

        $healthy = true;
        try {
            return $callback($redis);
        } catch (RedisException $e) {
            $healthy = false;
            echo "RedisDatabase withConnection RedisException: " . $e->getMessage() . "\n";
            return null;
        } catch (Throwable $e) {
            echo "RedisDatabase withConnection error: " . $e->getMessage() . "\n";
            return null;
        } finally {
            if ($healthy) {
                self::put($redis);
            } else {
                self::discard($redis);
            }
        }
    }

    /**
     * Task: يفحص وضع Redis ويعمل reconnect + reload scripts لو عاد.
     */
    public static function startReconnectTask(): void
    {
        if (self::$reconnectTaskRunning) {
            return;
        }
        self::$reconnectTaskRunning = true;

        try {
            if (self::checkRedisConnection()) {
                $wasDown = self::$is_error?->get() === 1;
                $scriptsMissing = self::$script_initialized?->get() === 0;

                if ($wasDown) {
                    echo "✅ Redis عاد للاتصال\n";
                    self::rest();
                    self::$is_error?->set(0);
                }

                if ($wasDown || $scriptsMissing) {
                    Coroutine::create(function () {
                        try {
                            RedisManager::initializeAll();
                            self::$script_initialized?->set(1);
                            echo "✅ تم إعادة تحميل Redis scripts\n";
                        } catch (Throwable $e) {
                            echo "❌ فشل إعادة تحميل scripts: " . $e->getMessage() . "\n";
                            self::$script_initialized?->set(0);
                        }
                    });
                }
            } else {
                if (self::$is_error?->get() === 0) {
                    echo "❌ Redis انقطع\n";
                }
                self::$script_initialized?->set(0);
                self::$is_error?->set(1);
            }
        } finally {
            self::$reconnectTaskRunning = false;
        }
    }

    /**
     * Timer دوري بيفحص وضع Redis كل 5 ثواني.
     */
    public static function checkConnect(): void
    {
        Timer::tick(5000, function () {
            if (self::$is_error?->get() === 1 || self::$script_initialized?->get() === 0) {
                self::startReconnectTask();
            }
        });
    }

    public static function command(string $method, array $params = [])
    {
        return self::withConnection(function (Redis $redis) use ($method, $params) {
            return call_user_func_array([$redis, $method], $params);
        });
    }
}
