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
            foreach (['routesPath', 'gate', 'deeplink', 'perPage'] as $key) {
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
        unset($config->exception['trace'], $config->exception['user']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/trace/');
        $this->expectExceptionMessageMatches('/user/');

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
            foreach (['request', 'params', 'headers', 'redact', 'user', 'session', 'context'] as $key) {
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

    public function testSubclassGateSetBeforeParentConstructorWinsOverDefault(): void
    {
        $customGateCalled = false;

        $config = new class ($customGateCalled) extends LoggingExtended {
            public function __construct(private bool &$calledRef)
            {
                // Set gate BEFORE parent::__construct() — ??= should keep this value
                $this->viewer['gate'] = function () use (&$calledRef): bool {
                    $calledRef = true;

                    return true;
                };

                parent::__construct();
            }
        };

        $gate   = $config->viewer['gate'];
        $result = $gate();

        $this->assertTrue($customGateCalled, 'Custom gate callable was not invoked.');
        $this->assertTrue($result);
    }

    public function testSubclassGateAlwaysReturnsFalseIsPreservedByNullCoalesce(): void
    {
        $config = new class () extends LoggingExtended {
            public function __construct()
            {
                $this->viewer['gate'] = fn () => false;
                parent::__construct();
            }
        };

        $gate = $config->viewer['gate'];

        $this->assertFalse($gate());
    }
}
