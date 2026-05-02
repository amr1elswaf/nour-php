<?php

declare(strict_types=1);

namespace App\Framework\Security;

use Nour\Contracts\Security\SecurityEventsInterface;
use Nour\Database\BaseDatabase;
use Swoole\Database\MysqliProxy;
use Throwable;

/**
 * App-side audit log for framework-emitted security events.
 *
 * Persists into the gooabb-specific `security_logs` table and bumps the
 * per-key `api.failed_attempts` counter. The framework's `Security`
 * module fires these events without ever knowing the schema details.
 */
final class AppSecurityEvents extends BaseDatabase implements SecurityEventsInterface
{
    public function recordFailedFingerprint(
        MysqliProxy $mysql,
        int $userId,
        string $ip,
        string $reason
    ): void {
        try {
            self::stmt_handle(
                $mysql,
                "INSERT INTO security_logs
                    (user_id, ip, attempt_type, reason, created_at)
                 VALUES (?, ?, 'fingerprint_failed', ?, NOW())",
                [$userId, $ip, $reason],
                'iss',
                false
            );

            self::stmt_handle(
                $mysql,
                "UPDATE api
                    SET failed_attempts = COALESCE(failed_attempts, 0) + 1,
                        last_failed_attempt = NOW()
                  WHERE user_id = ?",
                [$userId],
                'i',
                false
            );
        } catch (Throwable $e) {
            // Auditing must never break the request flow. The fingerprint
            // verdict is what gates access; failure to persist the audit
            // entry is a degraded operating mode but not fatal.
            error_log("[AppSecurityEvents] audit write failed: " . $e->getMessage());
        }
    }
}
