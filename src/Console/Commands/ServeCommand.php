<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\Output;
use Nour\Server\Boot;

/**
 * `nour serve` — start the Swoole server. Convenience alias so the
 * default `CMD` of the Docker image and ad-hoc developer invocations
 * can both go through `nour`.
 *
 * Equivalent to invoking `bin/server.php` directly.
 */
final class ServeCommand extends Command
{
    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Start the Swoole HTTP/WebSocket server';
    }

    public function handle(Input $input, Output $output): int
    {
        $mainFolder = (string) ($GLOBALS['main_folder'] ?? '');
        $output->info("Starting Nour server from: {$mainFolder}");
        Boot::run($mainFolder);
        return 0; // Boot::run never returns under normal circumstances
    }
}
