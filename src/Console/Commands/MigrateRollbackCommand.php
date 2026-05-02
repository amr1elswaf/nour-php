<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\MigrationRunner;
use Nour\Console\Output;

/**
 * `nour migrate:rollback [--allow-empty]` — undo the last applied
 * migration. Looks for a sibling `.down.sql`; if missing, refuses
 * unless `--allow-empty` is passed (in which case the migration is
 * forgotten without running anything).
 */
final class MigrateRollbackCommand extends Command
{
    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Roll back the most recently applied migration';
    }

    public function signature(): string
    {
        return '[--allow-empty]';
    }

    public function handle(Input $input, Output $output): int
    {
        $runner = new MigrationRunner((string) ($GLOBALS['main_folder'] ?? ''));
        $result = $runner->rollback($input->bool('allow-empty'));

        if ($result['name'] === null) {
            $output->dim('No migrations have been applied — nothing to roll back.');
            return 0;
        }
        if ($result['error'] !== null) {
            $output->error("[rollback] {$result['name']}: {$result['error']}");
            return 1;
        }
        if ($result['ran']) {
            $output->success("[rollback] reverted: {$result['name']}");
        } else {
            $output->warn("[rollback] forgot {$result['name']} (no .down.sql)");
        }
        return 0;
    }
}
