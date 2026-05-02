<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

final class UrlRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        if (!is_string($value)) {
            return ':field must be a valid URL';
        }
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            ? true
            : ':field must be a valid URL';
    }
}
