<?php

namespace Nour\Database;

use Exception;
use Swoole\Database\MysqliProxy;
use Swoole\Database\MysqliStatementProxy;

// ─── Param type enum (static, created once — Swoole-safe) ────────────────────
enum ParamType: string
{
    case Integer = 'i';
    case Double  = 'd';
    case Str     = 's';
    case Blob    = 'b';
}

// ─── SQL operation enum ───────────────────────────────────────────────────────
enum SqlOp: string
{
    case Select   = 'SELECT';
    case Insert   = 'INSERT';
    case Replace  = 'REPLACE';
    case Update   = 'UPDATE';
    case Delete   = 'DELETE';
    case Truncate = 'TRUNCATE';
    case Call     = 'CALL';
    case Show     = 'SHOW';
    case With     = 'WITH';
    case Explain  = 'EXPLAIN';
    case Set      = 'SET';
    case Create   = 'CREATE';
    case Drop     = 'DROP';
    case Alter    = 'ALTER';
}

abstract class BaseDatabase
{
    /**
     * Prepared-statement handler — covers SELECT / INSERT / UPDATE / DELETE
     * / REPLACE / TRUNCATE / CALL / SHOW / WITH / EXPLAIN and more.
     *
     * Fixes the whitespace bug: "SELECT\n  FROM …" is now parsed correctly
     * via regex instead of strtok which only splits on spaces.
     */
    protected static function stmt_handle(
        MysqliProxy $mysql,
        string      $query,
        array       $params = [],
        ?string     $types  = null,
        bool        $error  = true
    ) {
        try {
            $stmt = $mysql->prepare($query);
            if (!$stmt) {
                throw new Exception("فشل تجهيز الاستعلام: " . $mysql->error);
            }

            if (!empty($params)) {
                if (!$types) {
                    throw new Exception("Parameter types string is required when passing parameters.");
                }
                $bindParams = array_merge([$types], $params);
                $bindResult = call_user_func_array(
                    [$stmt, 'bind_param'],
                    self::refValues($bindParams)
                );
                if (!$bindResult) {
                    throw new Exception("Error binding parameters: " . $stmt->error);
                }
            }

            if (!$stmt->execute()) {
                throw new Exception("Error executing statement: " . $stmt->error);
            }

            $op = self::extractOp($query);

            return match (true) {
                // ── reads ─────────────────────────────────────────────────
                in_array($op, [
                    SqlOp::Select, SqlOp::Show, SqlOp::With,
                    SqlOp::Call,   SqlOp::Explain,
                ], true) => self::getResult($stmt),

                // ── writes with insert_id ──────────────────────────────────
                in_array($op, [SqlOp::Insert, SqlOp::Replace], true) => (object)[
                    'insert_id'     => $stmt->insert_id ?? $mysql->insert_id,
                    'affected_rows' => $stmt->affected_rows,
                ],

                // ── writes without insert_id ───────────────────────────────
                in_array($op, [
                    SqlOp::Update, SqlOp::Delete, SqlOp::Truncate,
                ], true) => (object)[
                    'affected_rows' => $stmt->affected_rows,
                ],

                // ── DDL / SET / other ─────────────────────────────────────
                default => true,
            };

        } catch (\Throwable $e) {
            // ⚠ لا نمرّر $e->getMessage() مباشرة للعميل — بيسرّب schema details
            // (Duplicate entry / Unknown column / Table doesn't exist / file paths).
            // نسجّله داخليًا فقط، ونرجّع رسالة عامة (شوف H1 في الـ audit).
            error_log("[BaseDatabase::stmt_handle] " . $e->getMessage() . " | query=" . substr($query, 0, 200));
            if ($error) {
                error('database error', 500, 'database', 'database_error');
            } else {
                // Caller بيعمل catch ويحتاج يميّز ده عن exceptions تانية —
                // الرسالة الجوة generic بس يقدر يعمل retry logic لو حب.
                throw new Exception('database error', 500);
            }
        } finally {
            if (isset($stmt) && ($stmt instanceof \mysqli_stmt || $stmt instanceof MysqliStatementProxy)) {
                $stmt->reset();
                $stmt->close();
            }
        }
    }

    /**
     * Multi-query handler — uses ParamType enum instead of switch.
     * Handles whitespace in query keyword detection.
     *
     * @deprecated غير مستخدم في الـ codebase حاليًا، وفيه قلق أمني:
     * بيستخدم real_escape_string بدل prepared statements، وعرضة لـ
     * multi-byte charset attacks. لو احتجت multi-query استخدم stmt_handle
     * عدة مرات بدلًا منه. (شوف M11 في الـ audit.)
     */
    protected static function multi_stmt_handle(
        MysqliProxy $mysql,
        string      $query,
        array       $params = [],
        ?string     $types  = null
    ): array|false {
        try {
            if (!empty($params)) {
                if (!$types || strlen($types) !== count($params)) {
                    throw new Exception("Number of types must match number of parameters.");
                }

                $escapedParams = [];
                for ($i = 0; $i < count($params); $i++) {
                    $pt    = ParamType::tryFrom($types[$i])
                        ?? throw new Exception("Unsupported param type: '{$types[$i]}'");
                    $value = $params[$i];

                    $escapedParams[] = match ($pt) {
                        ParamType::Integer => (int)   $value,
                        ParamType::Double  => (float) $value,
                        ParamType::Str     => "'" . $mysql->real_escape_string((string)$value) . "'",
                        ParamType::Blob    => throw new Exception("Blob not supported in multi_stmt_handle."),
                    };
                }

                $segments = explode('?', $query);
                if (count($segments) - 1 !== count($escapedParams)) {
                    throw new Exception("Placeholder count does not match parameter count.");
                }

                $finalQuery = '';
                foreach ($escapedParams as $i => $escaped) {
                    $finalQuery .= $segments[$i] . $escaped;
                }
                $finalQuery .= end($segments);
            } else {
                $finalQuery = $query;
            }

            if (!$mysql->multi_query($finalQuery)) {
                throw new Exception("فشل تنفيذ multi_query: " . $mysql->error);
            }

            $results    = [];
            $queries    = explode(';', $finalQuery);
            $queryIndex = 0;

            do {
                $result       = $mysql->store_result();
                $op           = self::extractOp($queries[$queryIndex] ?? '');
                $queryStr     = trim($queries[$queryIndex] ?? '');

                if ($result instanceof \mysqli_result) {
                    $rows = [];
                    while ($row = $result->fetch_assoc()) {
                        $rows[] = $row;
                    }
                    $results[] = ['type' => 'result', 'query' => $queryStr, 'data' => $rows];
                    $result->free();
                } else {
                    $affected  = $mysql->affected_rows;
                    $type = match (true) {
                        $op === SqlOp::Insert || $op === SqlOp::Replace => 'insert',
                        in_array($op, [SqlOp::Update, SqlOp::Delete, SqlOp::Truncate, SqlOp::Set], true) => $op?->value ? strtolower($op->value) : 'other',
                        default => 'other',
                    };

                    $entry = ['type' => $type, 'query' => $queryStr, 'affected_rows' => $affected];
                    if ($type === 'insert') {
                        $entry['insert_id'] = $mysql->insert_id;
                    }
                    $results[] = $entry;
                }

                $queryIndex++;
            } while ($mysql->more_results() && $mysql->next_result());

            return $results;

        } catch (Exception $e) {
            error("خطأ في multi_stmt_handle: " . $e->getMessage());
            return false;
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Extracts the first SQL keyword from a query string.
     * Handles leading whitespace, newlines, and tabs correctly.
     * Returns null if the keyword doesn't match a known SqlOp.
     */
    private static function extractOp(string $query): ?SqlOp
    {
        preg_match('/^\s*(\w+)/i', $query, $m);
        return SqlOp::tryFrom(strtoupper($m[1] ?? ''));
    }

    private static function getResult(\mysqli_stmt|MysqliStatementProxy $stmt): \mysqli_result
    {
        $result = $stmt->get_result();
        if (!$result) {
            throw new Exception("Failed to get result: " . $stmt->error);
        }
        return $result;
    }

    protected static function refValues(array &$arr): array
    {
        $refs = [];
        foreach ($arr as $key => &$value) {
            $refs[$key] = &$value;
        }
        return $refs;
    }
}
