<?php

declare(strict_types=1);

namespace Nour\Database;

use PDO;
use PDOException;
use Throwable;

/**
 * PostgreSQL connection pool — mirrors {@see SqlDatabase}'s shape but
 * uses PDO with the `pgsql` driver instead of Swoole's MysqliPool.
 *
 * ## Why PDO instead of `Swoole\Coroutine\PostgreSQL`?
 *
 * Swoole's coroutine PostgreSQL client requires Swoole to be built with
 * `--with-postgresql`, which most distributed builds (PECL, the
 * `phpswoole/swoole` Docker images, the openswoole-php image this
 * framework's Dockerfile uses) skip. PDO + ext-pdo_pgsql is universally
 * available, which lets the framework support PostgreSQL out of the
 * box on any standard PHP runtime.
 *
 * **Caveat**: PDO is blocking. Inside a coroutine, a PDO query parks the
 * worker until it returns. For most apps this is fine — the connection
 * pool size limits concurrent blocking work. Apps that need fully-async
 * Postgres can drop a custom adapter into the same `Nour\Database`
 * namespace once their Swoole build supports it.
 *
 * ## sitting.json shape
 *
 * ```json
 * {
 *   "postgres": {
 *     "enabled":  true,
 *     "host":     "postgres",
 *     "port":     5432,
 *     "user":     "...",
 *     "password": "...",
 *     "db":       "...",
 *     "options":  { "PDO::ATTR_ERRMODE": "PDO::ERRMODE_EXCEPTION" }
 *   }
 * }
 * ```
 *
 * The pool is built lazily on first acquire, so disabled is genuinely
 * zero-cost.
 */
final class PostgresDatabase
{
    /** @var array<int, PDO> */
    private static array $idle = [];

    /** @var array<int, true> in-use object ids */
    private static array $busy = [];

    private static ?array $config = null;

    private static int $poolSize = 16;

    /** @var array<int, float> spl_object_id => last used microtime */
    private static array $lastUsed = [];

    private static float $idlePingThreshold = 30.0;

    private function __construct() {}

    /**
     * Is Postgres configured for this app? Mirrors
     * {@see SqlDatabase::isEnabled()}: missing block or explicit
     * `enabled: false` both return false.
     */
    public static function isEnabled(): bool
    {
        $cfg = $GLOBALS['setting']['postgres'] ?? null;
        if (!is_array($cfg)) return false;
        if (array_key_exists('enabled', $cfg) && $cfg['enabled'] === false) {
            return false;
        }
        return true;
    }

    public static function init(int $poolSize = 16): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::$poolSize = max(1, $poolSize);
        self::$config   = $GLOBALS['setting']['postgres'];
    }

    /**
     * Acquire a PDO connection from the pool. Returns null if Postgres
     * is disabled or the pool is exhausted with no live connections.
     */
    public static function acquire(): ?PDO
    {
        if (!self::isEnabled() || self::$config === null) {
            return null;
        }

        // Reuse an idle connection if one survives the ping check.
        while (!empty(self::$idle)) {
            $pdo = array_pop(self::$idle);
            if (self::validate($pdo)) {
                self::$busy[spl_object_id($pdo)] = true;
                return $pdo;
            }
            // dead — drop and try again
            self::discard($pdo);
        }

        // Below pool size? open a new connection.
        if (count(self::$busy) >= self::$poolSize) {
            return null;
        }

        try {
            $pdo = self::connect();
        } catch (Throwable $e) {
            error_log("[PostgresDatabase] connect failed: " . $e->getMessage());
            return null;
        }
        self::$busy[spl_object_id($pdo)] = true;
        return $pdo;
    }

    public static function release(?PDO $pdo, bool $healthy = true): void
    {
        if ($pdo === null) return;

        $id = spl_object_id($pdo);
        unset(self::$busy[$id]);

        if (!$healthy) {
            self::discard($pdo);
            return;
        }

        self::$lastUsed[$id] = microtime(true);
        self::$idle[$id]     = $pdo;
    }

    /**
     * Run `$callback` with a live PDO. Returns whatever the callback
     * returns; null on failure (no connection available, or the
     * callback throws).
     *
     * The connection is automatically released back to the pool;
     * exceptions from the callback are logged but not re-thrown so
     * a single bad query doesn't poison the pool.
     */
    public static function withConnection(callable $callback)
    {
        $pdo = self::acquire();
        if ($pdo === null) {
            return null;
        }

        $healthy = true;
        try {
            return $callback($pdo);
        } catch (PDOException $e) {
            $healthy = false;
            error_log("[PostgresDatabase] PDOException: " . $e->getMessage());
            return null;
        } catch (Throwable $e) {
            error_log("[PostgresDatabase] withConnection error: " . $e->getMessage());
            return null;
        } finally {
            self::release($pdo, $healthy);
        }
    }

    /** Drop all pooled connections — for tests / manual reset. */
    public static function reset(): void
    {
        foreach (self::$idle as $pdo) {
            self::discard($pdo);
        }
        self::$idle    = [];
        self::$busy    = [];
        self::$lastUsed = [];
    }

    // ── Internals ────────────────────────────────────────────────────

    private static function connect(): PDO
    {
        $cfg  = self::$config;
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int)    ($cfg['port'] ?? 5432);
        $db   = (string) ($cfg['db']   ?? '');
        $user = (string) ($cfg['user'] ?? '');
        $pass = (string) ($cfg['password'] ?? '');

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $db);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        return new PDO($dsn, $user, $pass, $options);
    }

    private static function validate(PDO $pdo): bool
    {
        $id   = spl_object_id($pdo);
        $last = self::$lastUsed[$id] ?? 0.0;
        $now  = microtime(true);

        // Recently used — trust it without a round-trip.
        if ($last > 0.0 && ($now - $last) < self::$idlePingThreshold) {
            return true;
        }
        try {
            $pdo->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function discard(PDO $pdo): void
    {
        $id = spl_object_id($pdo);
        unset(self::$lastUsed[$id], self::$idle[$id], self::$busy[$id]);
        // PDO has no explicit close — letting it go out of scope drops
        // the underlying socket.
    }
}
