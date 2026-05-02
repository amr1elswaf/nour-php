<?php

declare(strict_types=1);

namespace App\Framework\Auth;

use App\classes\community\ban\BanChecker as ConcreteChecker;
use Nour\Contracts\Auth\BanCheckerInterface;
use Swoole\Database\MysqliProxy;

/**
 * Adapter so the framework's Router can look up ban status without
 * importing `App\classes\community\ban\BanChecker` directly.
 */
final class AppBanChecker implements BanCheckerInterface
{
    public function check(MysqliProxy $mysql, int $userId, string $scope): mixed
    {
        return ConcreteChecker::check($mysql, $userId, $scope);
    }
}
