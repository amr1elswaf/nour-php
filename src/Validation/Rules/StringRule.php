<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

final class StringRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        return is_string($value) ? true : ':field must be a string';
    }
}
