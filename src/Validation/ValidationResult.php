<?php

declare(strict_types=1);

namespace Nour\Validation;

/**
 * Output of a {@see Validator::make()} call. Read-only by design — the
 * Validator builds it; consumers query it.
 *
 * ## Shape
 *
 * Errors are keyed by field name and listed in rule order:
 *
 * ```php
 * [
 *   'email'    => ['email is required'],
 *   'password' => ['password must be at least 8 characters'],
 * ]
 * ```
 *
 * `firstError()` walks the errors map in insertion order (so the
 * field declared first in the rules array tends to surface first)
 * and returns the first message — handy for `error()` calls that
 * only want one line:
 *
 * ```php
 * if ($result->failed()) {
 *     error($result->firstError(), 400, 'validation', 'invalid_input');
 * }
 * ```
 *
 * Frontends that render per-field errors use `messages()` directly.
 */
final class ValidationResult
{
    /**
     * @param array<string, list<string>> $errors keyed by field name.
     */
    public function __construct(
        private readonly array $errors,
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function messages(): array
    {
        return $this->errors;
    }

    /**
     * First error message across all fields, in insertion order, or
     * `null` if validation passed.
     */
    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }
        return null;
    }

    /**
     * Errors for a single field, or empty list if there are none.
     *
     * @return list<string>
     */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }
}
