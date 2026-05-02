<?php

declare(strict_types=1);

namespace Nour\Exceptions;

use Throwable;

/**
 * Thrown when a binding exists but resolving it failed — usually because
 * a factory closure threw, or returned a non-object value.
 */
final class BindingResolutionException extends FrameworkException
{
    public static function factoryFailed(string $interface, Throwable $previous): self
    {
        return new self(
            "Factory for [{$interface}] threw: " . $previous->getMessage(),
            0,
            $previous
        );
    }

    public static function nonObjectReturn(string $interface, string $actualType): self
    {
        return new self(
            "Factory for [{$interface}] returned [{$actualType}]; expected an object."
        );
    }
}
