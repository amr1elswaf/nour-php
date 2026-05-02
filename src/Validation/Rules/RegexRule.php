<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use InvalidArgumentException;
use Nour\Contracts\Validation\RuleInterface;

/**
 * PCRE pattern match. `$params` is the full pattern including delimiters
 * (`'/^[a-z]+$/i'`). Wrap rule strings carefully — pipe characters in
 * the pattern would conflict with Validator's rule separator. Use
 * a class-name reference (`Validator::extend`) when the pattern is
 * complex.
 */
final class RegexRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        if ($params === '') {
            throw new InvalidArgumentException('regex rule requires a pattern: regex:/.../');
        }
        if (!is_string($value) && !is_numeric($value)) {
            return ':field must be a string';
        }
        $matched = @preg_match($params, (string) $value);
        if ($matched === false) {
            throw new InvalidArgumentException("regex rule: invalid pattern: {$params}");
        }
        return $matched === 1 ? true : ':field format is invalid';
    }
}
