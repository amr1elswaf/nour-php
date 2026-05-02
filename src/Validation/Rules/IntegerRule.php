<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * Accepts int values. String inputs that look like integers (e.g.
 * `"42"`) are also accepted because HTML form data arrives as strings;
 * apps that need a strict-typed int cast can do so after validation.
 * Rejects floats, hex strings, scientific notation, leading-zero
 * padding (`"007"`).
 */
final class IntegerRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        if (is_int($value)) return true;
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) return true;
        return ':field must be an integer';
    }
}
