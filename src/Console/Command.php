<?php

declare(strict_types=1);

namespace Nour\Console;

/**
 * Base class for CLI commands.
 *
 * Subclasses declare a `name`, a `description`, and an optional
 * `signature` (one-line help showing args/options), and implement
 * `handle(Input, Output): int`. The integer return value is the
 * process exit code — 0 = success, anything else = failure.
 *
 * ## Picking exit codes
 *
 * - `0` — success (operation completed; for read commands, always 0)
 * - `1` — generic failure (validation error, command misuse)
 * - `2` — environment error (DB/Redis unreachable, config missing)
 * - `127` — command not found (handled by Application; don't return manually)
 *
 * Apps can subclass and add their own commands by registering them
 * with {@see Application::register()}.
 */
abstract class Command
{
    /**
     * Short, kebab-case command name. Conventionally namespaced with
     * a colon — `migrate`, `migrate:rollback`, `route:list`,
     * `ip:block`, etc.
     */
    abstract public function name(): string;

    /**
     * One-line description shown in `nour list`. Imperative, no
     * trailing period.
     */
    abstract public function description(): string;

    /**
     * Argument / option signature shown when the user runs
     * `nour <command> --help`. Free-form; example:
     *
     *   `<ip> [--ttl=3600] [--reason=<text>]`
     *
     * Returns an empty string when the command takes no args.
     */
    public function signature(): string
    {
        return '';
    }

    /**
     * Run the command. Return the process exit code.
     */
    abstract public function handle(Input $input, Output $output): int;
}
