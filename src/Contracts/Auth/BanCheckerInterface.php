<?php

declare(strict_types=1);

namespace Nour\Contracts\Auth;

use Swoole\Database\MysqliProxy;

/**
 * Inspect a user's ban status. The Router calls this after authentication
 * and permission checks but before dispatching, so a banned account
 * sees `account_disabled` / `account_restricted` instead of leaking
 * information about the requested route.
 *
 * ## Scope strings
 *
 *  - `BanCheckerInterface::SCOPE_FULL` — total lockout. Every route
 *    is denied except those marked `bypass_ban: 1` in the route map
 *    (typically the support / appeal endpoints).
 *  - `BanCheckerInterface::SCOPE_ALL` — community/socket lockout. The
 *    user can still access account-management routes but cannot post,
 *    chat, or receive WS messages. Admins are exempt.
 *
 * Implementations are free to add more scopes; the framework only
 * dispatches on these two today.
 */
interface BanCheckerInterface
{
    public const SCOPE_FULL = 'full';
    public const SCOPE_ALL  = 'all';

    /**
     * @param string $scope One of SCOPE_FULL / SCOPE_ALL (or any
     *                      app-defined value the framework doesn't dispatch on).
     *
     * @return mixed Truthy / non-null = banned (the value MAY carry
     *               metadata such as ban reason / expiry — the framework
     *               doesn't inspect it). Null = not banned.
     */
    public function check(MysqliProxy $mysql, int $userId, string $scope): mixed;
}
