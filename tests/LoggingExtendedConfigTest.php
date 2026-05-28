<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Tests;

use Brunoggdev\LoggingExtended\Config\LoggingExtended;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class LoggingExtendedConfigTest extends CIUnitTestCase
{
    // -------------------------------------------------------------------------
    // validateViewer()
    // -------------------------------------------------------------------------

    public function testValidateViewerPassesWithAllRequiredKeys(): void
    {
        $config = new LoggingExtended();

        // No exception means validation passed
        $this->expectNotToPerformAssertions();
        $config->validateViewer();
    }

    public function testValidateViewerThrowsRuntimeExceptionListingMissingKeys(): void
    {
        $config = new LoggingExtended();
        unset($config->viewer['gate'], $config->viewer['perPage']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/gate/');
        $this->expectExceptionMessageMatches('/perPage/');

        $config->validateViewer();
    }

    public function testValidateViewerExceptionMessageContainsAllMissingKeys(): void
    {
        $config          = new LoggingExtended();
        $config->viewer  = ['enabled' => true]; // only one key present

        try {
            $config->validateViewer();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            foreach (['routes', 'gate', 'deeplink', 'perPage'] as $key) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // validateException()
    // -------------------------------------------------------------------------

    public function testValidateExceptionPassesWithAllRequiredKeys(): void
    {
        $config = new LoggingExtended();

        $this->expectNotToPerformAssertions();
        $config->validateException();
    }

    public function testValidateExceptionThrowsRuntimeExceptionListingMissingKeys(): void
    {
        $config = new LoggingExtended();
        unset($config->exception['trace'], $config->exception['request']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/trace/');
        $this->expectExceptionMessageMatches('/request/');

        $config->validateException();
    }

    public function testValidateExceptionExceptionMessageContainsAllMissingKeys(): void
    {
        $config            = new LoggingExtended();
        $config->exception = ['trace' => true]; // only one key present

        try {
            $config->validateException();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            foreach (['request', 'context', 'alerts'] as $key) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Default gate behaviour
    // -------------------------------------------------------------------------

    public function testDefaultGateIsCallable(): void
    {
        $config = new LoggingExtended();

        $this->assertIsCallable($config->viewer['gate']);
    }

    public function testDefaultGateReturnsTrueInDevelopmentEnvironment(): void
    {
        // The test bootstrap sets ENVIRONMENT to 'testing' or 'development'.
        // If it is 'development' the default gate must return true.
        // If it is 'testing', this assertion is still valid because the test suite
        // should be run from a development machine — but we guard just in case.
        if (ENVIRONMENT !== 'development') {
            $this->markTestSkipped('Default gate only returns true in development environment.');
        }

        $config = new LoggingExtended();
        $gate   = $config->viewer['gate'];

        $this->assertTrue($gate());
    }

    public function testDefaultGateReturnsFalseOutsideDevelopment(): void
    {
        if (ENVIRONMENT === 'development') {
            $this->markTestSkipped('This test is only meaningful outside of development environment.');
        }

        $config = new LoggingExtended();
        $gate   = $config->viewer['gate'];

        $this->assertFalse($gate());
    }

    // -------------------------------------------------------------------------
    // Custom gate (??= behaviour)
    // -------------------------------------------------------------------------

    public function testSubclassViewerMethodOverridesGate(): void
    {
        $config = new class () extends LoggingExtended {
            protected function viewer(): array
            {
                return array_replace(parent::viewer(), ['gate' => fn () => true]);
            }
        };

        $gate = $config->viewer['gate'];

        $this->assertTrue($gate(), 'Overridden gate should return true.');
    }

    public function testSubclassViewerMethodCanReturnFalseGate(): void
    {
        $config = new class () extends LoggingExtended {
            protected function viewer(): array
            {
                return array_replace(parent::viewer(), ['gate' => fn () => false]);
            }
        };

        $gate = $config->viewer['gate'];

        $this->assertFalse($gate());
    }

    // -------------------------------------------------------------------------
    // GATE_LOGIN constant
    // -------------------------------------------------------------------------

    public function testGateLoginConstantIsString(): void
    {
        $this->assertIsString(LoggingExtended::GATE_LOGIN);
    }

    public function testGateLoginSentinelCanBeSetAsGate(): void
    {
        $config = new class () extends LoggingExtended {
            protected function viewer(): array
            {
                return array_replace(parent::viewer(), ['gate' => LoggingExtended::GATE_LOGIN]);
            }
        };

        $this->assertSame(LoggingExtended::GATE_LOGIN, $config->viewer['gate']);
    }

    // -------------------------------------------------------------------------
    // filters key
    // -------------------------------------------------------------------------

    public function testDefaultFiltersIsEmptyArray(): void
    {
        $config = new LoggingExtended();

        $this->assertSame([], $config->viewer['routes']['filters']);
    }

    public function testValidateViewerRequiresRoutesKey(): void
    {
        $config = new LoggingExtended();
        unset($config->viewer['routes']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/routes/');

        $config->validateViewer();
    }

    // -------------------------------------------------------------------------
    // alertHandlers / alertLevels keys
    // -------------------------------------------------------------------------

    public function testDefaultAlertsHandlersIsEmptyArray(): void
    {
        $config = new LoggingExtended();

        $this->assertSame([], $config->exception['alerts']['handlers']);
    }

    public function testDefaultAlertsLevelsIsEmptyArray(): void
    {
        $config = new LoggingExtended();

        $this->assertSame([], $config->exception['alerts']['levels']);
    }

    public function testDefaultAlertsThrottleIsFifteenMinutes(): void
    {
        $config = new LoggingExtended();

        $this->assertSame(15 * MINUTE, $config->exception['alerts']['throttle']);
    }

    public function testValidateExceptionRequiresAlertsKey(): void
    {
        $config = new LoggingExtended();
        unset($config->exception['alerts']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/alerts/');

        $config->validateException();
    }
}
