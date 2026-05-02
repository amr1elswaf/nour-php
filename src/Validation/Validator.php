<?php

declare(strict_types=1);

namespace Nour\Validation;

use InvalidArgumentException;
use Nour\Contracts\Validation\RuleInterface;
use Nour\Validation\Rules\ArrayRule;
use Nour\Validation\Rules\BetweenRule;
use Nour\Validation\Rules\BooleanRule;
use Nour\Validation\Rules\DateRule;
use Nour\Validation\Rules\EmailRule;
use Nour\Validation\Rules\InRule;
use Nour\Validation\Rules\IntegerRule;
use Nour\Validation\Rules\MaxRule;
use Nour\Validation\Rules\MinRule;
use Nour\Validation\Rules\NumericRule;
use Nour\Validation\Rules\PhoneEgRule;
use Nour\Validation\Rules\RegexRule;
use Nour\Validation\Rules\RequiredRule;
use Nour\Validation\Rules\SameRule;
use Nour\Validation\Rules\StringRule;
use Nour\Validation\Rules\UrlRule;

/**
 * Laravel-style declarative validator.
 *
 * Replaces the framework's older `IsValidSomething::isValidDataInput`
 * pattern (one method call per field, each mixing concerns) with a
 * single declarative call that returns a {@see ValidationResult}:
 *
 * ```php
 * $result = Validator::make($data, [
 *     'email'    => 'required|string|email',
 *     'password' => 'required|string|min:8|max:255',
 *     'age'      => 'nullable|integer|between:18,120',
 *     'role'     => 'required|in:user,admin,teacher',
 * ]);
 *
 * if ($result->failed()) {
 *     error($result->firstError(), 400, 'validation', 'invalid_input');
 * }
 * ```
 *
 * ## Bail-on-first behaviour
 *
 * Each field bails on its first failure (matches Laravel's "bail" mode
 * for individual fields). The next field is then validated independently.
 * The result is one error per failed field — enough to surface to a UI
 * without overwhelming the user.
 *
 * ## `nullable` is a flow-control rule
 *
 * When a rule list contains `nullable` and the field's value is `null`,
 * the validator skips the remaining rules for that field. Order
 * matters: `'nullable|integer'` accepts `null` OR a valid int;
 * `'integer|nullable'` validates `integer` first (which fails on
 * `null`), then never reaches `nullable`. Put `nullable` first.
 *
 * ## Adding custom rules
 *
 * ```php
 * Validator::extend('uppercase', new class implements RuleInterface {
 *     public function check(mixed $v, string $p, array $a): true|string {
 *         if (is_string($v) && strtoupper($v) === $v) return true;
 *         return 'must be uppercase';
 *     }
 * });
 * ```
 *
 * Custom rules are global (per-worker) — register once at boot in
 * the host's Bootstrap.
 */
final class Validator
{
    /** @var array<string, RuleInterface> */
    private static array $rules = [];

    private function __construct() {}

    /**
     * Validate `$data` against `$ruleMap`.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $ruleMap field → pipe-delimited rule list
     */
    public static function make(array $data, array $ruleMap): ValidationResult
    {
        self::ensureBuiltinsLoaded();

        $errors = [];
        foreach ($ruleMap as $field => $ruleString) {
            $rules = self::parseRuleList((string) $ruleString);
            $value = $data[$field] ?? null;

            foreach ($rules as [$name, $params]) {
                if ($name === 'nullable') {
                    if ($value === null) {
                        break; // skip remaining rules for this field
                    }
                    continue;
                }

                $rule = self::$rules[$name] ?? null;
                if ($rule === null) {
                    throw new InvalidArgumentException(
                        "Validator: unknown rule '{$name}' on field '{$field}'."
                    );
                }

                $result = $rule->check($value, $params, $data);
                if ($result !== true) {
                    $errors[$field][] = self::renderMessage($field, $result);
                    break; // bail on first failure for this field
                }
            }
        }
        return new ValidationResult($errors);
    }

    /**
     * Register a custom rule (or override a built-in). Persists for
     * the lifetime of the worker.
     */
    public static function extend(string $name, RuleInterface $rule): void
    {
        self::ensureBuiltinsLoaded();
        self::$rules[$name] = $rule;
    }

    /**
     * Reset the rule registry. Tests use this to verify isolated rule
     * registration; production code should never call it.
     */
    public static function reset(): void
    {
        self::$rules = [];
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * @return list<array{0: string, 1: string}>  list of [name, params].
     */
    private static function parseRuleList(string $ruleString): array
    {
        $out  = [];
        $parts = explode('|', $ruleString);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $colonAt = strpos($part, ':');
            if ($colonAt === false) {
                $out[] = [$part, ''];
            } else {
                $name   = substr($part, 0, $colonAt);
                $params = substr($part, $colonAt + 1);
                $out[]  = [$name, $params];
            }
        }
        return $out;
    }

    /**
     * Replace `:field` placeholder in the rule's message with the
     * actual field name, so messages read naturally regardless of
     * which field a rule is applied to.
     */
    private static function renderMessage(string $field, string $message): string
    {
        return str_replace(':field', $field, $message);
    }

    private static function ensureBuiltinsLoaded(): void
    {
        if (self::$rules !== []) {
            return;
        }
        self::$rules = [
            'required' => new RequiredRule(),
            'string'   => new StringRule(),
            'integer'  => new IntegerRule(),
            'numeric'  => new NumericRule(),
            'boolean'  => new BooleanRule(),
            'array'    => new ArrayRule(),
            'min'      => new MinRule(),
            'max'      => new MaxRule(),
            'between'  => new BetweenRule(),
            'in'       => new InRule(),
            'regex'    => new RegexRule(),
            'email'    => new EmailRule(),
            'url'      => new UrlRule(),
            'date'     => new DateRule(),
            'phone'    => new PhoneEgRule(),
            'same'     => new SameRule(),
            // 'nullable' is handled inline as flow-control; not a RuleInterface entry.
        ];
    }
}
