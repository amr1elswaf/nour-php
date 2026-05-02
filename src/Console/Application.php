<?php

declare(strict_types=1);

namespace Nour\Console;

use Throwable;

/**
 * CLI command registry + dispatcher.
 *
 * Holds a map of `name → Command` instances and routes `Input::command()`
 * to the right one. `nour list` (or just `nour` with no args) prints
 * the registered commands. `nour help <name>` prints a single command's
 * signature.
 *
 * ## Lifecycle
 *
 * 1. `bin/nour` loads autoloaders and constructs an `Application`.
 * 2. The framework's built-in commands are registered.
 * 3. Apps can register additional commands (typically from a
 *    `bootstrap` hook or a future PluginLoader extension).
 * 4. `Application::run($argv)` dispatches.
 *
 * ## Exit codes
 *
 * - whatever the command returns
 * - `127` for unknown command
 * - `1` for uncaught throw
 */
final class Application
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(
        private readonly string $name = 'nour',
        private readonly string $version = '0.2.0',
    ) {}

    public function register(Command $command): self
    {
        $this->commands[$command->name()] = $command;
        return $this;
    }

    /**
     * @return array<string, Command>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $input  = Input::fromArgv($argv);
        $output = new Output();

        $name = $input->command();
        if ($name === '' || $name === 'list' || $name === '--help' || $name === '-h') {
            $this->printList($output);
            return 0;
        }
        if ($name === 'help') {
            $target = $input->argument(0);
            if ($target === null) {
                $this->printList($output);
                return 0;
            }
            return $this->printHelp($output, $target);
        }
        if ($name === '--version' || $name === '-V') {
            $output->writeln("{$this->name} {$this->version}");
            return 0;
        }

        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $output->error("Unknown command: {$name}");
            $output->writeln("Run `{$this->name} list` to see available commands.");
            return 127;
        }

        if ($input->bool('help')) {
            return $this->printHelp($output, $name);
        }

        try {
            return $command->handle($input, $output);
        } catch (Throwable $e) {
            $output->error('[' . get_class($e) . '] ' . $e->getMessage());
            $output->dim('  at ' . $e->getFile() . ':' . $e->getLine());
            if ($input->bool('verbose')) {
                $output->writeln($e->getTraceAsString());
            }
            return 1;
        }
    }

    private function printList(Output $output): void
    {
        $output->writeln("{$this->name} {$this->version} — Nour framework CLI");
        $output->writeln('');
        $output->writeln('Usage:  ' . $this->name . ' <command> [arguments]');
        $output->writeln('');
        $output->writeln('Available commands:');

        // Group by namespace prefix (text before the first colon).
        $groups = [];
        foreach ($this->commands as $cmd) {
            $name   = $cmd->name();
            $prefix = strstr($name, ':', true) ?: '';
            $groups[$prefix][] = $cmd;
        }
        ksort($groups);

        $rows = [];
        foreach ($groups as $cmds) {
            usort($cmds, fn (Command $a, Command $b) => strcmp($a->name(), $b->name()));
            foreach ($cmds as $cmd) {
                $rows[] = [$cmd->name(), $cmd->description()];
            }
        }
        $output->table(['command', 'description'], $rows);
    }

    private function printHelp(Output $output, string $name): int
    {
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $output->error("Unknown command: {$name}");
            return 127;
        }
        $output->writeln($command->name() . ' — ' . $command->description());
        $sig = $command->signature();
        if ($sig !== '') {
            $output->writeln('Usage:  ' . $this->name . ' ' . $command->name() . ' ' . $sig);
        }
        return 0;
    }
}
