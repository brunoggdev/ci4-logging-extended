<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Tests\Fixtures;

/**
 * Simulates the case where the named constructor is inherited from a parent.
 * PHP's trace records the declaring class (FactoryException), not this subclass,
 * so a naive $frame['class'] === $e::class check would fail to find the call site.
 */
class ChildFactoryException extends FactoryException {}
