<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * RFC 5321-ish via `filter_var(..., FILTER_VALIDATE_EMAIL)`. Apps that
 * need stricter rules (only specific TLDs, MX-record check, etc.)
 * should layer their own check on top.
 */
final class EmailRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        if (!is_string($value)) {
            return ':field must be a valid email';
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? true
            : ':field must be a valid email';
    }
}
