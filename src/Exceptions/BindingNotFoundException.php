<?php

declare(strict_types=1);

namespace Nour\Exceptions;

/**
 * Thrown by {@see \Nour\Container\Container::get()} when no binding is
 * registered for the requested interface. The app forgot to wire it
 * up at boot, or the framework was extracted without its companion
 * adapters.
 */
final class BindingNotFoundException extends FrameworkException
{
    public static function for(string $interface): self
    {
        return new self(
            "No binding registered for [{$interface}]. "
            . "Call Container::bind({$interface}::class, ...) at worker startup."
        );
    }
}
