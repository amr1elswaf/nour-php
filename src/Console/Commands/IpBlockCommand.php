<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\Output;
use Nour\Database\RedisDatabase;
use Nour\helpers\BlockIp;

/**
 * `nour ip:block <ip-or-cidr> [--ttl=3600] [--reason=<text>]`
 */
final class IpBlockCommand extends Command
{
    public function name(): string
    {
        return 'ip:block';
    }

    public function description(): string
    {
        return 'Block an IP or CIDR range';
    }

    public function signature(): string
    {
        return '<ip-or-cidr> [--ttl=3600] [--reason=<text>]';
    }

    public function handle(Input $input, Output $output): int
    {
        $target = $input->argument(0);
        if ($target === null || $target === '') {
            $output->error('Missing argument: ip-or-cidr');
            return 1;
        }
        if (!RedisDatabase::isEnabled()) {
            $output->error('Redis is disabled in sitting.json — cannot block.');
            return 2;
        }

        $ttl    = $input->int('ttl', 3600);
        $reason = (string) $input->option('reason', '');

        $ok = false;
        \Co\run(function () use ($target, $ttl, $reason, &$ok) {
            $ok = (new BlockIp())->block($target, $ttl, $reason);
        });

        if (!$ok) {
            $output->error("Failed to block {$target}.");
            return 2;
        }

        $ttlText = $ttl > 0 ? "for {$ttl}s" : 'permanently';
        $output->success("Blocked {$target} {$ttlText}" . ($reason !== '' ? " (reason: {$reason})" : ''));
        return 0;
    }
}
