<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\MigrationRunner;
use Nour\Console\Output;

/**
 * `nour migrate` — apply every pending migration in
 * `database/migrations/`.
 */
final class MigrateCommand extends Command
{
    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Apply pending database migrations';
    }

    public function handle(Input $input, Output $output): int
    {
        $runner = new MigrationRunner((string) ($GLOBALS['main_folder'] ?? ''));
        $result = $runner->migrate();

        foreach ($result['applied'] as $name) {
            $output->success("[migrate] applied: {$name}");
        }

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $err) {
                $output->error("[migrate] {$err}");
            }
            return 1;
        }

        if ($result['applied'] === []) {
            $output->dim('Nothing to migrate.');
        } else {
            $output->success(count($result['applied']) . ' migration(s) applied.');
        }
        return 0;
    }
}
