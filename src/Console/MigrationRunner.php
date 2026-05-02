<?php

declare(strict_types=1);

namespace Nour\Console;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;

/**
 * Schema migration helper.
 *
 * Discovers `*.up.sql` files in `database/migrations/` (relative to
 * the host's main folder), tracks applied migrations in a
 * `nour_migrations` table, and applies / rolls back idempotently.
 *
 * ## File naming
 *
 *   database/migrations/{YYYY-MM-DD_HHMMSS}_{name}.up.sql       (required)
 *   database/migrations/{YYYY-MM-DD_HHMMSS}_{name}.down.sql     (optional)
 *
 * The leading timestamp is what determines order. Files without a
 * timestamp prefix are sorted lexicographically — predictable but
 * less convenient.
 *
 * ## Why vanilla mysqli (not Swoole's pool)
 *
 * The CLI runs outside a Swoole worker — there's no coroutine context,
 * no pool to acquire from. Vanilla mysqli is one synchronous connection
 * for the lifetime of the command, which is exactly the right shape
 * for a one-shot operation.
 *
 * ## Tracking table
 *
 * ```sql
 * CREATE TABLE nour_migrations (
 *   id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 *   name VARCHAR(255) NOT NULL UNIQUE,
 *   checksum CHAR(64) NOT NULL,
 *   applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ```
 *
 * Auto-created on first run.
 */
final class MigrationRunner
{
    private mysqli $db;

    public function __construct(
        private readonly string $mainFolder,
    ) {
        $this->db = self::connect();
        $this->ensureTrackingTable();
    }

    /**
     * Apply every pending migration in timestamp order. Each migration
     * runs in its own transaction; a partial failure leaves earlier
     * migrations applied and the failing one not.
     *
     * @return array{applied: list<string>, errors: list<string>}
     */
    public function migrate(): array
    {
        $files = $this->discoverUp();
        if ($files === []) {
            return ['applied' => [], 'errors' => []];
        }

        $applied = $this->appliedNames();
        $out     = ['applied' => [], 'errors' => []];

        foreach ($files as $name => $path) {
            if (isset($applied[$name])) {
                // Drift check — warn if file has changed since apply.
                $current = self::checksum($path);
                if ($current !== $applied[$name]) {
                    $out['errors'][] = "{$name}: checksum changed since apply (was {$applied[$name]}, now {$current})";
                }
                continue;
            }
            try {
                $this->applyOne($name, $path);
                $out['applied'][] = $name;
            } catch (\Throwable $e) {
                $out['errors'][] = "{$name}: " . $e->getMessage();
                break; // stop on first failure to avoid cascading errors
            }
        }
        return $out;
    }

    /**
     * Roll back the most recently applied migration. Looks for a
     * sibling `.down.sql` file; if missing, the migration is removed
     * from the tracking table without running anything (caller's
     * choice via `--allow-empty`).
     *
     * @return array{name: ?string, ran: bool, error: ?string}
     */
    public function rollback(bool $allowEmpty = false): array
    {
        $row = $this->db->query(
            "SELECT name FROM nour_migrations ORDER BY id DESC LIMIT 1"
        )->fetch_assoc();
        if (!$row) {
            return ['name' => null, 'ran' => false, 'error' => null];
        }
        $name = (string) $row['name'];
        $down = $this->mainFolder . '/database/migrations/' . $name . '.down.sql';

        if (!file_exists($down)) {
            if (!$allowEmpty) {
                return [
                    'name'  => $name,
                    'ran'   => false,
                    'error' => 'no .down.sql sibling; pass --allow-empty to forget without running',
                ];
            }
            $this->forgetTracking($name);
            return ['name' => $name, 'ran' => false, 'error' => null];
        }

        try {
            $this->db->begin_transaction();
            $this->execScript((string) file_get_contents($down));
            $this->forgetTracking($name);
            $this->db->commit();
            return ['name' => $name, 'ran' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ['name' => $name, 'ran' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Snapshot of applied + pending migrations, suitable for `migrate:status`.
     *
     * @return list<array{name: string, status: string, applied_at: ?string, checksum_drift: bool}>
     */
    public function status(): array
    {
        $files   = $this->discoverUp();
        $applied = $this->appliedRows();

        $rows = [];
        foreach ($files as $name => $path) {
            if (!isset($applied[$name])) {
                $rows[] = ['name' => $name, 'status' => 'pending', 'applied_at' => null, 'checksum_drift' => false];
                continue;
            }
            $row = $applied[$name];
            $drift = $row['checksum'] !== self::checksum($path);
            $rows[] = [
                'name'           => $name,
                'status'         => 'applied',
                'applied_at'     => (string) $row['applied_at'],
                'checksum_drift' => $drift,
            ];
        }

        // Migrations recorded in the table but with no file → "missing".
        foreach ($applied as $name => $row) {
            if (!isset($files[$name])) {
                $rows[] = [
                    'name'           => $name,
                    'status'         => 'missing-file',
                    'applied_at'     => (string) $row['applied_at'],
                    'checksum_drift' => false,
                ];
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
        return $rows;
    }

    // ── Internals ────────────────────────────────────────────────────

    private function applyOne(string $name, string $path): void
    {
        $sql = (string) file_get_contents($path);
        $this->db->begin_transaction();
        try {
            $this->execScript($sql);
            $stmt = $this->db->prepare(
                "INSERT INTO nour_migrations (name, checksum) VALUES (?, ?)"
            );
            $checksum = self::checksum($path);
            $stmt->bind_param('ss', $name, $checksum);
            $stmt->execute();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function execScript(string $sql): void
    {
        // mysqli::multi_query lets us send several statements in one
        // round-trip — necessary for migrations that contain CREATE
        // TABLE + INSERT + ALTER together. Drain results to avoid
        // "commands out of sync" on the next call.
        if ($sql === '' || trim($sql) === '') return;
        if (!$this->db->multi_query($sql)) {
            throw new RuntimeException($this->db->error ?: 'multi_query failed');
        }
        do {
            if ($result = $this->db->store_result()) {
                $result->free();
            }
            if ($this->db->errno) {
                throw new RuntimeException($this->db->error);
            }
        } while ($this->db->more_results() && $this->db->next_result());
    }

    private function forgetTracking(string $name): void
    {
        $stmt = $this->db->prepare("DELETE FROM nour_migrations WHERE name = ?");
        $stmt->bind_param('s', $name);
        $stmt->execute();
    }

    /**
     * @return array<string, string>  filename → absolute path, sorted by name.
     */
    private function discoverUp(): array
    {
        $dir = $this->mainFolder . '/database/migrations';
        if (!is_dir($dir)) return [];
        $out  = [];
        foreach ((array) glob("{$dir}/*.up.sql") as $path) {
            $base       = basename((string) $path, '.up.sql');
            $out[$base] = (string) $path;
        }
        ksort($out);
        return $out;
    }

    /**
     * @return array<string, string>  name → checksum (already applied)
     */
    private function appliedNames(): array
    {
        $rows = $this->db->query("SELECT name, checksum FROM nour_migrations");
        $out  = [];
        if ($rows) {
            while ($r = $rows->fetch_assoc()) {
                $out[(string) $r['name']] = (string) $r['checksum'];
            }
        }
        return $out;
    }

    /**
     * @return array<string, array{checksum: string, applied_at: string}>
     */
    private function appliedRows(): array
    {
        $rows = $this->db->query("SELECT name, checksum, applied_at FROM nour_migrations");
        $out  = [];
        if ($rows) {
            while ($r = $rows->fetch_assoc()) {
                $out[(string) $r['name']] = [
                    'checksum'   => (string) $r['checksum'],
                    'applied_at' => (string) $r['applied_at'],
                ];
            }
        }
        return $out;
    }

    private function ensureTrackingTable(): void
    {
        $this->db->query(<<<SQL
            CREATE TABLE IF NOT EXISTS nour_migrations (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    private static function checksum(string $path): string
    {
        return hash_file('sha256', $path) ?: '';
    }

    /**
     * Connection config priority:
     *   1. `sitting.json:db_migrations` — separate user with schema-
     *      modification privileges (CREATE/ALTER/DROP).
     *   2. `sitting.json:db_api_user`   — fallback to the regular app
     *      user (works only if its grants include schema modification,
     *      which they typically don't in production).
     *
     * The framework recommends configuring `db_migrations` distinctly
     * — the principle of least privilege says the runtime app user
     * should not be able to drop tables.
     */
    private static function connect(): mysqli
    {
        $cfg = (array) ($GLOBALS['setting']['db_migrations'] ?? []);
        $usingFallback = false;
        if ($cfg === [] || ($cfg['enabled'] ?? true) === false) {
            $cfg = (array) ($GLOBALS['setting']['db_api_user'] ?? []);
            $usingFallback = true;
        }
        if ($cfg === [] || ($cfg['enabled'] ?? true) === false) {
            throw new RuntimeException(
                'MigrationRunner: neither db_migrations nor db_api_user is configured.'
            );
        }
        $host = (string) ($cfg['host']     ?? '127.0.0.1');
        $port = (int)    ($cfg['port']     ?? 3306);
        $user = (string) ($cfg['user']     ?? '');
        $pass = (string) ($cfg['password'] ?? '');
        $name = (string) ($cfg['db']       ?? '');
        if ($user === '' || $name === '') {
            throw new RuntimeException(
                'MigrationRunner: user/db missing from sitting.json'
                . ($usingFallback ? ' (db_api_user)' : ' (db_migrations)') . '.'
            );
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $db = new mysqli($host, $user, $pass, $name, $port);
        } catch (mysqli_sql_exception $e) {
            throw new RuntimeException("MigrationRunner: cannot connect: " . $e->getMessage());
        }
        $db->set_charset('utf8mb4');
        return $db;
    }
}
