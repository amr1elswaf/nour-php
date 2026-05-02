<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * Type-aware lower bound:
 *   - numeric value → must be `>= $min`
 *   - string        → strlen must be `>= $min` (multibyte-aware via mb_strlen)
 *   - array         → count must be `>= $min`
 *
 * For mixed-shape inputs, pair with a type rule first
 * (`'integer|min:1'`) so the comparison is unambiguous.
 */
final class MinRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        $min = (int) $params;
        if (is_numeric($value)) {
            return $value + 0 >= $min ? true : ":field must be at least {$min}";
        }
        if (is_string($value)) {
            return mb_strlen($value) >= $min ? true : ":field must be at least {$min} characters";
        }
        if (is_array($value)) {
            return count($value) >= $min ? true : ":field must have at least {$min} items";
        }
        return ':field must be a number, string, or array';
    }
}
