<?php

declare(strict_types=1);

namespace App\Framework\Auth;

use App\helpers\ApiHelper;
use Nour\Contracts\Auth\UserResolverInterface;
use Swoole\Database\MysqliProxy;
use Throwable;

/**
 * App-side implementation of {@see UserResolverInterface}.
 *
 * Wraps the existing {@see ApiHelper} (Redis cache) and the canonical
 * `api JOIN profile` SQL query so the framework never has to import
 * `App\helpers\ApiHelper` directly.
 *
 * Cache key shape stays compatible with the legacy code:
 *   - `ApiHelper::save()` stores under `expires_at` (note the spelling),
 *     while the DB column is `expired_at`. We normalise on read so
 *     callers always see `expired_at`.
 */
final class AppUserResolver implements UserResolverInterface
{
    public function resolveByApiKey(MysqliProxy $mysql, string $apiKey): array
    {
        $cached = $this->getCached($apiKey);
        if (!empty($cached) && !empty($cached['user_id'])) {
            ApiHelper::updateAccessTime($apiKey);
            return $this->normalizeRow($cached);
        }

        $row = $this->fetchFromDb($mysql, $apiKey);
        if ($row === null) {
            return ['id' => 0, 'role' => null];
        }

        // Warm the cache when the profile is set up — the DB row gets
        // hit on every miss otherwise.
        if (!empty($row['role'])) {
            try {
                ApiHelper::save(
                    $apiKey,
                    (int) $row['user_id'],
                    (string) $row['role'],
                    (string) ($row['expired_at'] ?? ''),
                    (string) ($row['ip'] ?? ''),
                    $row['fingerprint'] ?? null
                );
            } catch (Throwable) {
                // Cache-warming is optional — never let it block resolve.
            }
        }

        return $this->normalizeRow($row);
    }

    public function getCached(string $apiKey): array
    {
        try {
            $data = ApiHelper::get($apiKey);
        } catch (Throwable) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    public function cache(string $apiKey, array $userData, int $ttl): bool
    {
        try {
            return ApiHelper::save(
                $apiKey,
                (int) ($userData['id'] ?? $userData['user_id'] ?? 0),
                (string) ($userData['role'] ?? ''),
                (string) ($userData['expired_at'] ?? $userData['expires_at'] ?? ''),
                (string) ($userData['ip'] ?? ''),
                $userData['fingerprint'] ?? null
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function invalidate(string $apiKey): void
    {
        try {
            ApiHelper::delete($apiKey);
        } catch (Throwable) {
            // best-effort
        }
    }

    public function revoke(MysqliProxy $mysql, string $apiKey): void
    {
        try {
            $stmt = $mysql->prepare("UPDATE api SET is_active = 0 WHERE api = ?");
            if ($stmt !== false) {
                $stmt->bind_param('s', $apiKey);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable) {
            // best-effort — cache invalidation below still happens
        }
        $this->invalidate($apiKey);
    }

    public function touch(MysqliProxy $mysql, string $apiKey, string $ip): void
    {
        try {
            $stmt = $mysql->prepare("UPDATE api SET ip = ?, last_access = NOW() WHERE api = ?");
            if ($stmt !== false) {
                $stmt->bind_param('ss', $ip, $apiKey);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable) {
            // best-effort
        }
        // Cache may now be stale (we just changed the persisted IP);
        // drop it so the next read pulls the fresh row.
        $this->invalidate($apiKey);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFromDb(MysqliProxy $mysql, string $apiKey): ?array
    {
        $stmt = $mysql->prepare(
            "SELECT p.user_id, p.role, a.expired_at, a.ip, a.fingerprint
             FROM api a
             LEFT JOIN profile p ON a.user_id = p.gooabb_user_id
             WHERE a.api = ? AND a.is_active = 1
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $apiKey);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $res  = $stmt->get_result();
        $row  = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) && !empty($row['user_id']) ? $row : null;
    }

    /**
     * Normalise a row (from cache or DB) into the contract's UserData shape.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, role: string|null, ip?: string, expired_at?: string, fingerprint?: string|null}
     */
    private function normalizeRow(array $row): array
    {
        // Bridge cache↔DB key drift: ApiHelper saves `expires_at`, DB has
        // `expired_at`. Downstream callers prefer the DB spelling.
        $expired = $row['expired_at'] ?? $row['expires_at'] ?? null;

        $out = [
            'id'   => (int) ($row['user_id'] ?? $row['id'] ?? 0),
            'role' => isset($row['role']) ? ($row['role'] === '' ? null : $row['role']) : null,
        ];
        if ($expired !== null)            $out['expired_at']   = (string) $expired;
        if (isset($row['ip']))            $out['ip']           = (string) $row['ip'];
        if (array_key_exists('fingerprint', $row)) $out['fingerprint']  = $row['fingerprint'];

        return $out;
    }
}
