<?php

declare(strict_types=1);

namespace App\Framework;

use App\Framework\Auth\AppBanChecker;
use App\Framework\Auth\AppUserResolver;
use App\Framework\Media\BunnyPhotoSigner;
use App\Framework\Security\AppSecurityEvents;
use App\Framework\Security\AppSecurityServices;
use Nour\Container\App;
use Nour\Contracts\Auth\BanCheckerInterface;
use Nour\Contracts\Auth\UserResolverInterface;
use Nour\Contracts\Media\PhotoSignerInterface;
use Nour\Contracts\Security\BlacklistInterface;
use Nour\Contracts\Security\RateLimiterInterface;
use Nour\Contracts\Security\SecurityEventsInterface;
use Nour\Contracts\Security\VerificationCacheInterface;

/**
 * Wires the application's concrete services into the framework's
 * container. This is the only place that knows about both
 * `App\…` and `Nour\…` types — when the framework is later
 * extracted to its own package, this file stays in the app and
 * is the only thing that needs to be edited per-deployment to
 * swap implementations.
 *
 * Call {@see register()} once per Swoole worker, in `workerStart`,
 * before the first request can land. The bindings live in process
 * memory, so each worker registers independently.
 */
final class Bootstrap
{
    private function __construct() {}

    public static function register(): void
    {
        $container = App::container();

        $container->bind(UserResolverInterface::class, new AppUserResolver());
        $container->bind(BanCheckerInterface::class,   new AppBanChecker());
        $container->bind(PhotoSignerInterface::class,  new BunnyPhotoSigner());

        // Security services — single adapter satisfies three interfaces
        // (rate limit + blacklist + verification cache all share one
        // Redis namespace; splitting would just duplicate plumbing).
        $security = new AppSecurityServices();
        $container->bind(RateLimiterInterface::class,        $security);
        $container->bind(BlacklistInterface::class,          $security);
        $container->bind(VerificationCacheInterface::class,  $security);

        // Audit log — persists fingerprint-validation failures.
        $container->bind(SecurityEventsInterface::class, new AppSecurityEvents());

        // Webhooks are NOT bound here — they're discovered from
        // data/Webhooks.json by WebhookRouter at worker startup. To add
        // a new webhook, write a class implementing
        // WebhookHandlerInterface and add a row in Webhooks.json.
    }
}
