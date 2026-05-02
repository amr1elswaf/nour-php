<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * Accepts bool values, common stringly-typed booleans (`"true"`,
 * `"false"`, `"1"`, `"0"`), and integers `1`/`0`. Form-submitted
 * checkboxes typically arrive as `"on"` or absent — apps that need
 * that pattern should default to `false` and use `boolean` only on
 * inputs that explicitly carry a boolean-shaped value.
 */
final class BooleanRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        if (is_bool($value)) return true;
        if ($value === 1 || $value === 0) return true;
        if ($value === '1' || $value === '0') return true;
        if ($value === 'true' || $value === 'false') return true;
        return ':field must be boolean';
    }
}
