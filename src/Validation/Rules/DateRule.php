<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * Field is parseable as a date. Uses `strtotime` for broad acceptance
 * (`'2026-05-02'`, `'tomorrow'`, `'2026-05-02T13:00:00Z'`). Apps that
 * need a specific format should use `regex:/^\d{4}-\d{2}-\d{2}$/`
 * instead.
 */
final class DateRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return ':field must be a valid date';
        }
        $ts = strtotime((string) $value);
        return $ts !== false ? true : ':field must be a valid date';
    }
}
