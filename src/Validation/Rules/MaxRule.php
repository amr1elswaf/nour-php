<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * Mirror of {@see MinRule} — upper bound by type.
 */
final class MaxRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        $max = (int) $params;
        if (is_numeric($value)) {
            return $value + 0 <= $max ? true : ":field must not exceed {$max}";
        }
        if (is_string($value)) {
            return mb_strlen($value) <= $max ? true : ":field must not exceed {$max} characters";
        }
        if (is_array($value)) {
            return count($value) <= $max ? true : ":field must not have more than {$max} items";
        }
        return ':field must be a number, string, or array';
    }
}
