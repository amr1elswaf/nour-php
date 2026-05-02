<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\Output;

final class TimerListCommand extends Command
{
    public function name(): string
    {
        return 'timer:list';
    }

    public function description(): string
    {
        return 'List background timers from Timers.json';
    }

    public function handle(Input $input, Output $output): int
    {
        $mainFolder = (string) ($GLOBALS['main_folder'] ?? '');
        $configPath = $mainFolder . '/data/Timers.json';

        if (!file_exists($configPath)) {
            $output->dim("Timers.json not present at: {$configPath}");
            return 0;
        }

        $entries = json_decode((string) file_get_contents($configPath), true);
        if (!is_array($entries)) {
            $output->error("Timers.json is present but not valid JSON.");
            return 1;
        }

        $rows = [];
        foreach ($entries as $e) {
            $path     = (string) ($e['path']     ?? '?');
            $interval = (int)    ($e['interval'] ?? 0);
            $human    = self::humanInterval($interval);
            $rows[] = [$path, $interval . 's', $human];
        }
        if ($rows === []) {
            $output->dim('(no timers)');
            return 0;
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
        $output->table(['path', 'interval', 'every'], $rows);
        $output->writeln('');
        $output->dim(count($rows) . ' timer(s) total.');
        return 0;
    }

    private static function humanInterval(int $secs): string
    {
        if ($secs < 60)         return "{$secs}s";
        if ($secs < 3600)       return number_format($secs / 60, 1) . 'm';
        if ($secs < 86400)      return number_format($secs / 3600, 1) . 'h';
        return number_format($secs / 86400, 1) . 'd';
    }
}
