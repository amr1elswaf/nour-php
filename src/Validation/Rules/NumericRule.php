<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * Accepts ints, floats, and numeric strings (`"3.14"`, `"-1"`, `"1e3"`).
 * `is_numeric` for the broad case; rules that need stricter shapes
 * (no scientific notation, integer-only) should pick a more specific
 * rule.
 */
final class NumericRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        return is_numeric($value) ? true : ':field must be numeric';
    }
}
