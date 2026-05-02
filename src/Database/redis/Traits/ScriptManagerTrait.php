<?php

namespace Nour\Database\redis\Traits;

use Redis;
use RedisException;
use Throwable;

trait ScriptManagerTrait
{
    protected static array $scripts = [];
    protected static array $scriptShas = [];

    /**
     * تحميل جميع السكربتات لأول مرة (بيتنادى من RedisManager::initializeAll).
     */
    public static function initializeScripts(): bool
    {
        static::ensureScriptsDefined();

        $redis = null;
        try {
            $redis = static::getRedisConnection();
            if ($redis === null) {
                return false;
            }
            static::loadScriptsOnConnection($redis);
            return true;
        } catch (Throwable $e) {
            static::logError(
                $e instanceof \Exception ? $e : new \Exception($e->getMessage(), 0, $e),
                "initializeScripts failed"
            );
            return false;
        } finally {
            if ($redis !== null) {
                static::returnRedisConnection($redis);
            }
        }
    }

    /**
     * تعريف نصوص السكربتات (بيتعمله override في كل Structure).
     */
    abstract protected static function defineScripts(): void;

    /**
     * بيتأكد إن السكربتات معرفة والـ SHAs محسوبة من غير ما يلمس Redis.
     */
    protected static function ensureScriptsDefined(): void
    {
        if (empty(static::$scripts)) {
            static::defineScripts();
        }
        if (empty(static::$scriptShas) || count(static::$scriptShas) !== count(static::$scripts)) {
            static::$scriptShas = [];
            foreach (static::$scripts as $name => $script) {
                static::$scriptShas[$name] = sha1($script);
            }
        }
    }

    /**
     * تحميل كل السكربتات على اتصال Redis معيّن (بيستخدم كمان للـ self-heal بعد NOSCRIPT).
     */
    protected static function loadScriptsOnConnection(Redis $redis): void
    {
        static::ensureScriptsDefined();

        foreach (static::$scripts as $name => $script) {
            $expected = static::$scriptShas[$name];
            $loaded = $redis->script('load', $script);
            if (is_string($loaded) && $loaded !== $expected) {
                // متوقع إن SHA يطابق sha1 محلياً - لو اختلف نثق في اللي جاي من السيرفر
                static::$scriptShas[$name] = $loaded;
            }
        }
    }

    /**
     * تنفيذ سكربت بـ evalSha مع self-heal تلقائي لو السيرفر فقد السكربت
     * (بسبب restart، flushall، أو eviction).
     */
    protected static function executeScript(
        Redis $redis,
        string $scriptName,
        array $keys,
        array $args
    ) {
        static::ensureScriptsDefined();

        if (!isset(static::$scriptShas[$scriptName])) {
            throw new \Exception("Script '$scriptName' is not defined");
        }

        $sha1 = static::$scriptShas[$scriptName];
        $combined = array_merge($keys, $args);
        $numKeys = count($keys);

        try {
            $result = $redis->evalSha($sha1, $combined, $numKeys);

            // بعض نسخ phpredis بترجع false وتحط الخطأ في lastError بدل ما ترمي exception
            if ($result === false && static::isNoScriptLastError($redis)) {
                $redis->clearLastError();
                static::loadScriptsOnConnection($redis);
                return $redis->evalSha(static::$scriptShas[$scriptName], $combined, $numKeys);
            }

            return $result;
        } catch (RedisException $e) {
            if (static::isNoScriptMessage($e->getMessage())) {
                static::loadScriptsOnConnection($redis);
                return $redis->evalSha(static::$scriptShas[$scriptName], $combined, $numKeys);
            }
            throw $e;
        }
    }

    private static function isNoScriptMessage(string $msg): bool
    {
        return $msg !== '' && stripos($msg, 'NOSCRIPT') !== false;
    }

    private static function isNoScriptLastError(Redis $redis): bool
    {
        if (!method_exists($redis, 'getLastError')) {
            return false;
        }
        $err = (string) $redis->getLastError();
        return static::isNoScriptMessage($err);
    }

    public static function getLoadedScripts(): array
    {
        return array_keys(static::$scriptShas);
    }
}
