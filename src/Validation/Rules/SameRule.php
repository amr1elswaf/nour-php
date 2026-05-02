<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use InvalidArgumentException;
use Nour\Contracts\Validation\RuleInterface;

/**
 * Cross-field equality: `same:other` passes when this field's value
 * strictly equals `$allData[$other]`. Typically used for password
 * confirmation:
 *
 * ```php
 * 'password'              => 'required|string|min:8',
 * 'password_confirmation' => 'required|string|same:password',
 * ```
 */
final class SameRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        if ($params === '') {
            throw new InvalidArgumentException('same rule requires a field name: same:other_field');
        }
        $other = $allData[$params] ?? null;
        return $value === $other ? true : ":field must match {$params}";
    }
}
