<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\Output;
use Nour\Plugin\PluginLoader;

final class WebhookListCommand extends Command
{
    public function name(): string
    {
        return 'webhook:list';
    }

    public function description(): string
    {
        return 'List webhooks from Webhooks.json + providers';
    }

    public function handle(Input $input, Output $output): int
    {
        $mainFolder = (string) ($GLOBALS['main_folder'] ?? '');
        $configPath = $mainFolder . '/data/Webhooks.json';

        $rows = [];

        if (file_exists($configPath)) {
            $entries = json_decode((string) file_get_contents($configPath), true);
            if (is_array($entries)) {
                foreach ($entries as $e) {
                    $rows[] = self::format($e, 'json');
                }
            }
        } else {
            $output->dim("Webhooks.json not present (optional).");
        }

        $providers = (array) ($GLOBALS['setup']['providers'] ?? []);
        if ($providers !== []) {
            try {
                PluginLoader::reset();
                PluginLoader::loadAll($providers);
                foreach (PluginLoader::collectWebhooks() as $e) {
                    $rows[] = self::format($e, 'plugin');
                }
            } catch (\Throwable $t) {
                $output->warn('Could not load providers: ' . $t->getMessage());
            }
        }

        if ($rows === []) {
            $output->dim('(no webhooks)');
            return 0;
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
        $output->table(['path', 'method', 'handler', 'source'], $rows);
        $output->writeln('');
        $output->dim(count($rows) . ' webhook(s) total.');
        return 0;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function format(array $entry, string $source): array
    {
        $path    = (string) ($entry['path']   ?? '?');
        $method  = (string) ($entry['method'] ?? 'POST');
        $class   = (string) ($entry['class']  ?? '?');
        return [$path, $method, $class, $source];
    }
}
