<?php

declare(strict_types=1);

namespace Nour\Console;

/**
 * Parsed argv for a single CLI invocation.
 *
 * Splits `$argv` into:
 *   - the command name (first non-flag positional)
 *   - the remaining positional arguments
 *   - long-form options (`--key`, `--key=value`, `--key value`)
 *
 * Short-form options (`-f`) and option clustering (`-fvz`) are NOT
 * supported — keeping the parser tiny is worth more than CLI ergonomics
 * for a framework's housekeeping commands.
 *
 * ## Examples
 *
 * ```
 * nour ip:block 1.2.3.4 --ttl=60 --reason="bad actor"
 * nour migrate --force
 * nour route:list
 * ```
 */
final class Input
{
    /**
     * @param string                $command
     * @param list<string>          $arguments  positional args after the command
     * @param array<string, string> $options    long-form options (`--k=v`)
     */
    public function __construct(
        private readonly string $command,
        private readonly array $arguments,
        private readonly array $options,
    ) {}

    /**
     * Parse `$argv`. Index 0 (the script path) is ignored.
     *
     * @param list<string> $argv
     */
    public static function fromArgv(array $argv): self
    {
        $tail = array_slice($argv, 1);
        if ($tail === []) {
            return new self('', [], []);
        }

        $command   = (string) array_shift($tail);
        $arguments = [];
        $options   = [];

        for ($i = 0; $i < count($tail); $i++) {
            $token = $tail[$i];
            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                if ($body === '') continue; // bare `--`, ignore
                $eqAt = strpos($body, '=');
                if ($eqAt !== false) {
                    $options[substr($body, 0, $eqAt)] = substr($body, $eqAt + 1);
                    continue;
                }
                // `--key value` form — consume next token if it's not another flag
                $next = $tail[$i + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '--')) {
                    $options[$body] = $next;
                    $i++;
                } else {
                    $options[$body] = '1'; // boolean flag
                }
                continue;
            }
            $arguments[] = $token;
        }

        return new self($command, $arguments, $options);
    }

    public function command(): string
    {
        return $this->command;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return $this->options;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }

    public function bool(string $name): bool
    {
        return isset($this->options[$name])
            && in_array(strtolower($this->options[$name]), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $name, int $default = 0): int
    {
        return isset($this->options[$name]) ? (int) $this->options[$name] : $default;
    }
}
