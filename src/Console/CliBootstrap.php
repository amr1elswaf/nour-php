<?php

declare(strict_types=1);

namespace Nour\Console;

use Nour\Database\PostgresDatabase;
use Nour\Database\RedisDatabase;
use Nour\Database\SqlDatabase;
use RuntimeException;

/**
 * Lightweight bootstrap for CLI invocations.
 *
 * Boot::run starts the Swoole server — too heavy for one-shot CLI
 * commands. CliBootstrap does the subset that command handlers need:
 *
 *   1. Set `$GLOBALS['main_folder']` so framework code can find data/.
 *   2. Load `data/sitting.json` into `$GLOBALS['setting']`.
 *   3. Apply `runtime.timezone` (some commands print dates).
 *   4. Initialise DB/Redis/Postgres pools — same `init()` calls Boot
 *      makes, since the helpers (`BlockIp`, `RateLimiter`, etc.) all
 *      go through the pool.
 *
 * The CLI commands then wrap their pool work in `Co\run(...)` to get
 * a coroutine context. `RedisDatabase::withConnection` etc. require
 * one — outside a coroutine they simply hang.
 */
final class CliBootstrap
{
    private function __construct() {}

    public static function init(string $mainFolder): void
    {
        $mainFolder = rtrim($mainFolder, "/\\");
        if (!is_dir($mainFolder)) {
            throw new RuntimeException("CliBootstrap: main folder not a directory: {$mainFolder}");
        }
        $GLOBALS['main_folder'] = $mainFolder;

        $settingPath = $mainFolder . '/data/sitting.json';
        $setupPath   = $mainFolder . '/data/setup.json';

        $setting = self::loadJson($settingPath, required: false);
        $setup   = self::loadJson($setupPath,   required: false);

        $GLOBALS['setting']    = $setting;
        $GLOBALS['setup']      = $setup;
        $GLOBALS['socket_key'] = $setup['runtime']['socket_key'] ?? 'nour:socket_system';

        $tz = $setup['runtime']['timezone'] ?? 'UTC';
        date_default_timezone_set((string) $tz);

        // Pool init is safe-to-call-twice and short-circuits when the
        // service is disabled in sitting.json. Same wiring Boot uses.
        SqlDatabase::init();
        RedisDatabase::init();
        PostgresDatabase::init();
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadJson(string $path, bool $required): array
    {
        if (!file_exists($path)) {
            if ($required) {
                throw new RuntimeException("CliBootstrap: required config not found: {$path}");
            }
            return [];
        }
        $raw     = file_get_contents($path);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("CliBootstrap: invalid JSON in {$path}: " . json_last_error_msg());
        }
        return $decoded;
    }
}
