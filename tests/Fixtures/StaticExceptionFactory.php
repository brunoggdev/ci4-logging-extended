<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Tests\Fixtures;

use RuntimeException;

/**
 * Simulates a static-constructor pattern where the exception is instantiated
 * inside this class but called from the test file.
 */
class StaticExceptionFactory
{
    public static function make(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }
}
