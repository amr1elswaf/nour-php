<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\MigrationRunner;
use Nour\Console\Output;

/**
 * `nour migrate:status` — list every migration file and its applied /
 * pending state, plus drift warnings when an applied file's checksum
 * has changed since it ran.
 */
final class MigrateStatusCommand extends Command
{
    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show applied / pending migrations';
    }

    public function handle(Input $input, Output $output): int
    {
        $runner = new MigrationRunner((string) ($GLOBALS['main_folder'] ?? ''));
        $rows   = $runner->status();

        if ($rows === []) {
            $output->dim('No migrations found.');
            return 0;
        }

        $tableRows = [];
        foreach ($rows as $r) {
            $marker = $r['checksum_drift'] ? ' (drift!)' : '';
            $tableRows[] = [
                $r['name'],
                $r['status'] . $marker,
                $r['applied_at'] ?? '-',
            ];
        }
        $output->table(['name', 'status', 'applied_at'], $tableRows);
        return 0;
    }
}
