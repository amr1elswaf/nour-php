<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * Field's stringified value must appear in the comma-separated
 * allowlist. Useful for enum-shaped fields (`role`, `status`).
 *
 * Comparison is loose by default (`in_array(.., .., false)`) so
 * `'1'` matches `1` — convenient for HTML form data which is always
 * stringly-typed.
 */
final class InRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        $allowed = array_map('trim', explode(',', $params));
        if (in_array((string) $value, $allowed, true)) {
            return true;
        }
        $list = implode(', ', $allowed);
        return ":field must be one of: {$list}";
    }
}
