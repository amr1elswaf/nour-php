<?php

declare(strict_types=1);

namespace Nour\Console\Commands;

use Nour\Console\Command;
use Nour\Console\Input;
use Nour\Console\Output;
use Nour\Database\RedisDatabase;
use Nour\helpers\BlockIp;

final class IpUnblockCommand extends Command
{
    public function name(): string
    {
        return 'ip:unblock';
    }

    public function description(): string
    {
        return 'Remove an IP or CIDR range from the block-list';
    }

    public function signature(): string
    {
        return '<ip-or-cidr>';
    }

    public function handle(Input $input, Output $output): int
    {
        $target = $input->argument(0);
        if ($target === null || $target === '') {
            $output->error('Missing argument: ip-or-cidr');
            return 1;
        }
        if (!RedisDatabase::isEnabled()) {
            $output->error('Redis is disabled in sitting.json — nothing to unblock.');
            return 2;
        }

        $ok = false;
        \Co\run(function () use ($target, &$ok) {
            $ok = (new BlockIp())->unblock($target);
        });

        if (!$ok) {
            $output->error("Failed to unblock {$target}.");
            return 2;
        }
        $output->success("Unblocked {$target}.");
        return 0;
    }
}
