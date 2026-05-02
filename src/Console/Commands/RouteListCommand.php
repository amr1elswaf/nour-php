<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\Output;
use Nour\Plugin\PluginLoader;

/**
 * `nour route:list` — print every HTTP route the framework would
 * dispatch to, including provider-supplied entries.
 *
 * Reads `data/FilesMap.json` directly (no Router::init / Swoole boot).
 * Provider routes are collected by instantiating each class listed in
 * `setup.json:providers` and calling `routes()` — same code path the
 * runtime uses, just outside a Swoole worker.
 */
final class RouteListCommand extends Command
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'List HTTP routes from FilesMap.json + providers';
    }

    public function handle(Input $input, Output $output): int
    {
        $mainFolder = (string) ($GLOBALS['main_folder'] ?? '');
        $filesMap   = $mainFolder . '/data/FilesMap.json';

        $rows = [];

        if (file_exists($filesMap)) {
            $entries = json_decode((string) file_get_contents($filesMap), true);
            if (is_array($entries)) {
                foreach ($entries as $e) {
                    $rows[] = self::format($e, 'json');
                }
            } else {
                $output->warn("FilesMap.json present but not valid JSON.");
            }
        } else {
            $output->warn("FilesMap.json not found at: {$filesMap}");
        }

        // Provider-supplied routes — load providers and collect.
        $providers = (array) ($GLOBALS['setup']['providers'] ?? []);
        if ($providers !== []) {
            try {
                PluginLoader::reset();
                PluginLoader::loadAll($providers);
                foreach (PluginLoader::collectRoutes() as $e) {
                    $rows[] = self::format($e, 'plugin');
                }
            } catch (\Throwable $t) {
                $output->warn('Could not load providers: ' . $t->getMessage());
            }
        }

        if ($rows === []) {
            $output->dim('(no routes)');
            return 0;
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
        $output->table(['req', 'handler', 'auth', 'roles', 'source'], $rows);
        $output->writeln('');
        $output->dim(count($rows) . ' route(s) total.');
        return 0;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function format(array $entry, string $source): array
    {
        $req     = (string) ($entry['req']       ?? '?');
        $handler = (string) ($entry['file_path'] ?? '?');
        $auth    = (int)    ($entry['pre']       ?? 1);
        $roles   = $entry['up'] ?? '';
        $rolesS  = is_array($roles) ? implode(',', $roles) : (string) $roles;
        if ($rolesS === '') $rolesS = '-';
        $authS   = $auth === 2 ? 'required' : ($auth === 3 ? 'high' : 'open');
        return [$req, $handler, $authS, $rolesS, $source];
    }
}
