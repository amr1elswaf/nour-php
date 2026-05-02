<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\Output;
use Nour\Database\RedisDatabase;
use Nour\helpers\BlockIp;

final class IpListCommand extends Command
{
    public function name(): string
    {
        return 'ip:list';
    }

    public function description(): string
    {
        return 'List currently blocked IPs and CIDR ranges';
    }

    public function signature(): string
    {
        return '[--limit=100]';
    }

    public function handle(Input $input, Output $output): int
    {
        if (!RedisDatabase::isEnabled()) {
            $output->error('Redis is disabled in sitting.json — block-list is empty.');
            return 2;
        }

        $limit = $input->int('limit', 100);
        $rows  = [];

        \Co\run(function () use ($limit, &$rows) {
            $rows = (new BlockIp())->listAll($limit);
        });

        if ($rows === []) {
            $output->dim('(no blocks)');
            return 0;
        }

        $tableRows = [];
        foreach ($rows as $r) {
            $ttl  = $r['ttl'];
            $ttlS = match (true) {
                $ttl === -1 => 'permanent',
                $ttl === -2 => 'expired',
                $ttl <  60  => "{$ttl}s",
                $ttl <  3600 => number_format($ttl / 60, 1) . 'm',
                default     => number_format($ttl / 3600, 1) . 'h',
            };
            $tableRows[] = [
                $r['ip'],
                $r['cidr'] ? 'cidr' : 'ip',
                $ttlS,
                $r['reason'] !== '' ? $r['reason'] : '-',
            ];
        }
        $output->table(['target', 'kind', 'ttl', 'reason'], $tableRows);
        $output->writeln('');
        $output->dim(count($tableRows) . ' block(s) total.');
        return 0;
    }
}
