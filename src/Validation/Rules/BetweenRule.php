<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use InvalidArgumentException;
use Nour\Contracts\Validation\RuleInterface;

/**
 * Combines `min` and `max` semantics — inclusive on both bounds.
 * Params: `between:lo,hi`.
 */
final class BetweenRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        $parts = explode(',', $params, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("between rule requires two parameters: between:lo,hi");
        }
        $lo = (int) trim($parts[0]);
        $hi = (int) trim($parts[1]);

        if (is_numeric($value)) {
            $n = $value + 0;
            return ($n >= $lo && $n <= $hi) ? true : ":field must be between {$lo} and {$hi}";
        }
        if (is_string($value)) {
            $len = mb_strlen($value);
            return ($len >= $lo && $len <= $hi) ? true : ":field must be {$lo}–{$hi} characters";
        }
        if (is_array($value)) {
            $c = count($value);
            return ($c >= $lo && $c <= $hi) ? true : ":field must have {$lo}–{$hi} items";
        }
        return ':field must be a number, string, or array';
    }
}
