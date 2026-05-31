<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Tests\Fixtures;

use RuntimeException;

/**
 * Simulates a named-constructor pattern where the exception creates itself
 * via a static method defined in its own class file.
 */
class FactoryException extends RuntimeException
{
    public static function make(string $message): static
    {
        return new static($message);
    }
}
