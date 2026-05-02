<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

final class ArrayRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        return is_array($value) ? true : ':field must be an array';
    }
}
