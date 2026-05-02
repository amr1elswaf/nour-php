<?php

declare(strict_types=1);

namespace Nour\Contracts\Validation;

/**
 * One validation rule. Implementations are stateless and reused across
 * requests — the framework instantiates each rule class once per
 * worker and calls `check()` per field.
 *
 * ## Contract
 *
 * `check()` returns:
 *  - `true` — the field passes this rule.
 *  - `string` — failure message (already-rendered, ready to surface
 *    to the user). Apps that need i18n should resolve translations
 *    inside the rule's `check()`.
 *
 * Rules NEVER throw on validation failure — only on misuse (e.g. a
 * `min` rule called without parameters).
 *
 * ## Parameters
 *
 * Validator passes the raw string after the first colon as `$params`.
 * Rules parse it themselves:
 *   - `min:8`             → $params = "8"
 *   - `between:1,100`     → $params = "1,100"
 *   - `regex:/^[a-z]+$/i` → $params = "/^[a-z]+$/i"
 *   - `in:a,b,c`          → $params = "a,b,c"
 *   - `required`          → $params = ""
 *
 * Doing the parse inside each rule keeps the contract simple (no
 * fixed shape for params across all rules) and lets rules with
 * unusual delimiters (regex, urls) work naturally.
 *
 * ## Cross-field access
 *
 * `$allData` is the full input map being validated, for rules like
 * `same:other_field` that need to compare values. Don't mutate it —
 * it's shared across rules in the same `Validator::make()` call.
 */
interface RuleInterface
{
    /**
     * @param mixed                $value   The field's current value.
     * @param string               $params  Raw post-colon string ("" when no params).
     * @param array<string, mixed> $allData The full input map.
     * @return true|string  true = pass, string = error message.
     */
    public function check(mixed $value, string $params, array $allData): true|string;
}
