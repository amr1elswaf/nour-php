<?php

declare(strict_types=1);

namespace Nour\Console;

/**
 * TTY-aware writer for console commands.
 *
 * Wraps stdout/stderr with semantic helpers (`info`, `success`,
 * `warn`, `error`) and a tiny table renderer. ANSI color codes are
 * emitted only when stdout is an interactive terminal — piping to a
 * file or another process gets clean text.
 *
 * Color detection runs once at construction; it can be forced via
 * `$colorMode` if the auto-detection guesses wrong (e.g. when
 * running under a CI runner that sets `TERM=dumb`).
 */
final class Output
{
    private const COLORS = [
        'reset'  => "\033[0m",
        'bold'   => "\033[1m",
        'dim'    => "\033[2m",
        'red'    => "\033[31m",
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'blue'   => "\033[34m",
        'cyan'   => "\033[36m",
        'gray'   => "\033[90m",
    ];

    private bool $useColor;

    public function __construct(?bool $colorMode = null)
    {
        $this->useColor = $colorMode ?? self::detectColor();
    }

    public function write(string $line): void
    {
        fwrite(STDOUT, $line);
    }

    public function writeln(string $line = ''): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    public function info(string $line): void
    {
        $this->writeln($this->colorize($line, 'cyan'));
    }

    public function success(string $line): void
    {
        $this->writeln($this->colorize($line, 'green'));
    }

    public function warn(string $line): void
    {
        $this->writeln($this->colorize($line, 'yellow'));
    }

    public function error(string $line): void
    {
        fwrite(STDERR, $this->colorize($line, 'red') . PHP_EOL);
    }

    public function dim(string $line): void
    {
        $this->writeln($this->colorize($line, 'gray'));
    }

    /**
     * Render a simple table. Columns are auto-sized to the widest
     * value in each column. Long values wrap by overflow (no
     * truncation) — readable trumps tidy.
     *
     * @param list<string>            $headers
     * @param list<list<string|int>>  $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_fill(0, count($headers), 0);
        foreach ($headers as $i => $h) {
            $widths[$i] = max($widths[$i], mb_strlen((string) $h));
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], mb_strlen((string) $cell));
            }
        }

        $renderRow = function (array $cells) use ($widths): string {
            $parts = [];
            foreach ($cells as $i => $cell) {
                $w  = $widths[$i] ?? 0;
                $pad = $w - mb_strlen((string) $cell);
                $parts[] = (string) $cell . str_repeat(' ', max(0, $pad));
            }
            return '  ' . implode('  ', $parts);
        };

        $sep = '  ' . implode('  ', array_map(
            fn (int $w) => str_repeat('-', $w),
            $widths,
        ));

        $this->writeln($this->colorize($renderRow($headers), 'bold'));
        $this->dim($sep);
        foreach ($rows as $row) {
            $this->writeln($renderRow($row));
        }
    }

    private function colorize(string $line, string $color): string
    {
        if (!$this->useColor || !isset(self::COLORS[$color])) {
            return $line;
        }
        return self::COLORS[$color] . $line . self::COLORS['reset'];
    }

    private static function detectColor(): bool
    {
        // Respect explicit opt-out
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') return false;

        // Respect explicit opt-in (override TTY check)
        $forceColor = getenv('FORCE_COLOR');
        if ($forceColor !== false && $forceColor !== '') return true;

        // Most common: are we attached to a real terminal?
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }
        return false;
    }
}
