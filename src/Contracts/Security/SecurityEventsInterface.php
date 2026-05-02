<?php

declare(strict_types=1);

namespace Nour\Contracts\Security;

use Swoole\Database\MysqliProxy;

/**
 * Persist security-relevant events the framework wants to audit.
 *
 * The two events the default `Security` flow records:
 *   - {@see recordFailedFingerprint()} — fingerprint validation failed.
 *     We persist a row in the app's audit log AND bump the per-key
 *     failure counter so repeat offenders trip a different threshold.
 *
 * Implementations are app-specific because the audit log's schema
 * (`security_logs` / `api.failed_attempts`) is app-specific. The
 * framework just calls the interface and trusts it to do the right
 * persistence.
 */
interface SecurityEventsInterface
{
    public function recordFailedFingerprint(
        MysqliProxy $mysql,
        int $userId,
        string $ip,
        string $reason
    ): void;
}
