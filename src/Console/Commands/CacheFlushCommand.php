<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\Output;
use Nour\Database\RedisDatabase;

/**
 * `nour cache:flush <pattern> [--force]`
 *
 * Deletes Redis keys matching the given glob pattern via SCAN+DEL.
 * Refuses to flush an unbounded pattern (`*` alone) without `--force`
 * to prevent accidental whole-database wipes.
 *
 * Common patterns:
 *   nour cache:flush 'nour:rl:*'      — clear rate-limit counters
 *   nour cache:flush 'login:*'        — clear login fingerprint locks
 *   nour cache:flush 'nour:blocked:*' — clear block-list (use ip:unblock instead)
 */
final class CacheFlushCommand extends Command
{
    public function name(): string
    {
        return 'cache:flush';
    }

    public function description(): string
    {
        return 'Delete Redis keys matching a glob pattern';
    }

    public function signature(): string
    {
        return '<pattern> [--force]';
    }

    public function handle(Input $input, Output $output): int
    {
        $pattern = $input->argument(0);
        if ($pattern === null || $pattern === '') {
            $output->error('Missing argument: pattern');
            return 1;
        }
        if ($pattern === '*' && !$input->bool('force')) {
            $output->error('Refusing to flush "*" without --force.');
            return 1;
        }
        if (!RedisDatabase::isEnabled()) {
            $output->error('Redis is disabled in sitting.json — nothing to flush.');
            return 2;
        }

        $deleted = 0;
        \Co\run(function () use ($pattern, &$deleted) {
            $deleted = (int) RedisDatabase::withConnection(function ($redis) use ($pattern) {
                $count  = 0;
                $cursor = null;
                while (true) {
                    $batch = $redis->scan($cursor, $pattern, 500);
                    if ($batch === false || $batch === []) {
                        if ((int) $cursor === 0) break;
                        continue;
                    }
                    foreach (array_chunk($batch, 200) as $chunk) {
                        $count += (int) $redis->del(...$chunk);
                    }
                    if ((int) $cursor === 0) break;
                }
                return $count;
            });
        });

        $output->success("Deleted {$deleted} key(s) matching: {$pattern}");
        return 0;
    }
}
