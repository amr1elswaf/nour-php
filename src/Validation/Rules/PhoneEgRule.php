<?php

declare(strict_types=1);

namespace Nour\Validation\Rules;

use Nour\Contracts\Validation\RuleInterface;

/**
 * Egyptian mobile phone. Mirror of the regex used by the legacy
 * {@see \Nour\helpers\IsValidSomething::isValidEgyptPhone()}: accepts
 * `01012345678`, `1012345678`, `2010xxxxxxxx`, `+201012345678`,
 * `0020xxxxxxxxxxx`. Rejects landlines.
 *
 * Use as `phone:eg` (param ignored — only Egypt is supported today).
 * A future generic `phone:<country>` could grow into a country-rule
 * registry; for now this rule is region-locked.
 */
final class PhoneEgRule implements RuleInterface
{
    public function check(mixed $value, string $params, array $allData): true|string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return ':field must be a valid Egyptian phone';
        }
        $ok = preg_match(
            '/^(?:\+?20|0020)?0?(10|11|12|15)[0-9]{8}$/',
            (string) $value,
        ) === 1;
        return $ok ? true : ':field must be a valid Egyptian phone';
    }
}
