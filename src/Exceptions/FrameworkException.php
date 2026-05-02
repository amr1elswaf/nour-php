<?php

declare(strict_types=1);

namespace Nour\Exceptions;

use RuntimeException;

/**
 * Base class for every exception thrown by the Nour framework layer.
 *
 * Catching this lets app code distinguish "the framework had a problem"
 * from app-domain `Exception` instances that bubble through Router /
 * Security and carry HTTP-shaped codes (400/401/403/404).
 */
abstract class FrameworkException extends RuntimeException
{
}
