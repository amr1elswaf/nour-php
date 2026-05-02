<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * Field must be present, non-null, and non-empty. Empty means: empty
 * string `''`, empty array `[]`. Zero (`0`, `'0'`, `0.0`) is treated
 * as a valid value — only "no value" fails.
 */
final class RequiredRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        if ($value === null)                 return ':field is required';
        if (is_string($value) && $value === '') return ':field is required';
        if (is_array($value) && $value === [])  return ':field is required';
        return true;
    }
}
