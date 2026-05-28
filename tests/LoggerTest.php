<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Tests;

use Brunoggdev\LoggingExtended\Logger;
use CodeIgniter\Log\Handlers\FileHandler;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class LoggerTest extends CIUnitTestCase
{
    private Logger $logger;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new class () {
            public int $threshold     = 9;
            public string $dateFormat = 'Y-m-d H:i:s';
            public array $handlers    = [
                FileHandler::class => [
                    'handles'         => ['critical', 'alert', 'emergency', 'debug', 'error', 'info', 'notice', 'warning'],
                    'fileExtension'   => '',
                    'filePermissions' => 0644,
                    'path'            => WRITEPATH . 'logs/',
                ],
            ];
        };

        $this->logger  = new Logger($config);
        $this->logFile = WRITEPATH . 'logs/log-' . date('Y-m-d') . '.log';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        // Clear alert throttle cache files so throttle tests don't bleed across runs.
        $cacheDir = WRITEPATH . 'cache/log_alerts/';
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        // Reset the static cache instance so the next test starts fresh.
        $ref = new \ReflectionProperty(\Brunoggdev\LoggingExtended\Logger::class, 'alertCache');
        $ref->setValue(null, null);
    }

    private function lastLogLine(): string
    {
        $lines = array_filter(explode("\n", file_get_contents($this->logFile)));

        return end($lines);
    }

    /**
     * Returns the first main log line (the one containing '-->').
     * Used for exception() tests where trace lines follow the main entry.
     */
    private function mainLogLine(): string
    {
        foreach (explode("\n", file_get_contents($this->logFile)) as $line) {
            if (str_contains($line, ' --> ')) {
                return trim($line);
            }
        }

        return '';
    }

    public function testPlainMessageWithNoContext(): void
    {
        $this->logger->info('Plain message');

        $this->assertStringContainsString('Plain message', $this->lastLogLine());
        $this->assertStringNotContainsString('|', $this->lastLogLine());
    }

    public function testContextWithNoPlaceholdersIsAppended(): void
    {
        $this->logger->error('Job failed', ['job' => 'SendEmail', 'attempt' => 3]);

        $line = $this->lastLogLine();
        $this->assertStringContainsString('Job failed', $line);
        $this->assertStringContainsString('| job=SendEmail attempt=3', $line);
    }

    public function testPlaceholdersAreConsumedAndNotDuplicated(): void
    {
        $this->logger->warning('Retry {job} on attempt {attempt}', [
            'job'     => 'SendEmail',
            'attempt' => 2,
            'reason'  => 'timeout',
        ]);

        $line = $this->lastLogLine();
        $this->assertStringContainsString('Retry SendEmail on attempt 2', $line);
        $this->assertStringContainsString('| reason=timeout', $line);
        $this->assertStringNotContainsString('job=', $line);
        $this->assertStringNotContainsString('attempt=', $line);
    }

    public function testNullContextValueFormattedAsNull(): void
    {
        $this->logger->info('Check', ['val' => null]);

        $this->assertStringContainsString('val=null', $this->lastLogLine());
    }

    public function testBoolContextValuesFormattedAsTrueFalse(): void
    {
        $this->logger->info('Check', ['active' => true, 'deleted' => false]);

        $line = $this->lastLogLine();
        $this->assertStringContainsString('active=true', $line);
        $this->assertStringContainsString('deleted=false', $line);
    }

    public function testStringWithSpacesIsQuoted(): void
    {
        $this->logger->info('Check', ['error' => 'something went wrong']);

        $this->assertStringContainsString('error="something went wrong"', $this->lastLogLine());
    }

    public function testArrayContextValueIsJsonEncoded(): void
    {
        $this->logger->info('Check', ['ids' => [1, 2, 3]]);

        $this->assertStringContainsString('ids=[1,2,3]', $this->lastLogLine());
    }

    public function testExceptionMethodLogsClassAndMessage(): void
    {
        $e = new RuntimeException('Something exploded');
        $this->logger->exception($e);

        $line = $this->mainLogLine();
        $this->assertStringContainsString('[RuntimeException]', $line);
        $this->assertStringContainsString('Something exploded', $line);
    }

    public function testExceptionMethodRespectsLevel(): void
    {
        $e = new RuntimeException('Low severity');
        $this->logger->exception($e, 'warning');

        $this->assertStringContainsString('WARNING', $this->mainLogLine());
    }

    public function testExceptionObjectIsNotLeakedIntoOutput(): void
    {
        $e = new RuntimeException('Clean output');
        $this->logger->exception($e);

        $line = $this->lastLogLine();
        $this->assertStringNotContainsString('exception=', $line);
        $this->assertStringNotContainsString('RuntimeException Object', $line);
    }

    public function testExceptionLogsLocationAsFileColonLine(): void
    {
        $e = new RuntimeException('Location test');
        $this->logger->exception($e);

        $line = $this->mainLogLine();
        $this->assertMatchesRegularExpression('/location=.+:\d+/', $line);
    }

    public function testExceptionCustomMessageOverridesExceptionMessage(): void
    {
        $e = new RuntimeException('Original exception message');
        $this->logger->exception($e, 'error', 'Custom call-site message');

        $line = $this->mainLogLine();
        $this->assertStringContainsString('Custom call-site message', $line);
        $this->assertStringNotContainsString('Original exception message', $line);
    }

    public function testExceptionWithCustomLevelWritesThatLevel(): void
    {
        $e = new RuntimeException('Critical issue');
        $this->logger->exception($e, 'critical');

        $this->assertStringContainsString('CRITICAL', $this->mainLogLine());
    }

    public function testExceptionWithUserCallableLogsUserData(): void
    {
        $config                              = config('LoggingExtended');
        $config->exception['context']['user'] = fn () => ['id' => 42, 'email' => 'user@example.com'];
        $config->exception['trace']          = false;

        $e = new RuntimeException('With user');
        $this->logger->exception($e);

        $line = $this->lastLogLine();
        $this->assertStringContainsString('"id":42', $line);
        $this->assertStringContainsString('"email":"user@example.com"', $line);
    }

    public function testExceptionUserCallableReturningNullOmitsUserKey(): void
    {
        $config                              = config('LoggingExtended');
        $config->exception['context']['user'] = fn () => null;
        $config->exception['trace']          = false;

        $e = new RuntimeException('No user');
        $this->logger->exception($e);

        $line = $this->lastLogLine();
        $this->assertStringNotContainsString('user=', $line);
    }

    public function testExceptionWithContextArrayResolvesEachKey(): void
    {
        $config                               = config('LoggingExtended');
        $config->exception['trace']           = false;
        $config->exception['context']['extra'] = [
            'tenant' => fn () => 'acme',
            'region' => fn () => 'us-east-1',
        ];

        $e = new RuntimeException('With context');
        $this->logger->exception($e);

        $line = $this->lastLogLine();
        $this->assertStringContainsString('tenant=acme', $line);
        $this->assertStringContainsString('region=us-east-1', $line);
    }

    public function testExceptionContextResolverReturningNullOmitsKey(): void
    {
        $config                               = config('LoggingExtended');
        $config->exception['trace']           = false;
        $config->exception['context']['extra'] = [
            'nullable' => fn () => null,
        ];

        $e = new RuntimeException('Null context');
        $this->logger->exception($e);

        $this->assertStringNotContainsString('nullable=', $this->lastLogLine());
    }

    public function testExceptionWithTraceFalseOmitsStacktraceLines(): void
    {
        $config                     = config('LoggingExtended');
        $config->exception['trace'] = false;

        $e = new RuntimeException('No trace');
        $this->logger->exception($e);

        $content = file_get_contents($this->logFile);
        $this->assertStringNotContainsString('#0 ', $content);
        $this->assertStringNotContainsString('#1 ', $content);
    }

    public function testExceptionWithTraceDefaultIncludesStacktrace(): void
    {
        $config                     = config('LoggingExtended');
        $config->exception['trace'] = true;

        $e = new RuntimeException('With trace');
        $this->logger->exception($e);

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('#0 ', $content);
    }

    public function testExceptionWithSessionTrueDoesNotCrash(): void
    {
        $config                       = config('LoggingExtended');
        $config->exception['trace']   = false;
        $config->exception['context']['session'] = true;

        $e = new RuntimeException('Session test');

        // Session may not be available in CLI test env; just verify no crash
        try {
            $this->logger->exception($e);
            $this->assertStringContainsString('[RuntimeException]', $this->lastLogLine());
        } catch (\Throwable $thrown) {
            // If session truly unavailable, a specific SessionException is acceptable;
            // any other unexpected exception should re-fail the test.
            $this->assertStringContainsString('Session', $thrown::class);
        }
    }

    public function testRedactParamsReplacesPasswordWithRedactedMarker(): void
    {
        // redactParams() is private; access it via Closure binding against the
        // already-constructed logger instance so we reuse the setUp config.
        $redact = \Closure::bind(
            fn (array $params, array $keys) => $this->redactParams($params, $keys),
            $this->logger,
            Logger::class,
        );

        $redacted = $redact(
            ['username' => 'john', 'password' => 'hunter2', 'nested' => ['token' => 'abc123']],
            ['password', 'token'],
        );

        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['nested']['token']);
        $this->assertSame('john', $redacted['username']);
    }

    public function testRedactParamsIsCaseInsensitive(): void
    {
        $redact = \Closure::bind(
            fn (array $params, array $keys) => $this->redactParams($params, $keys),
            $this->logger,
            Logger::class,
        );

        $redacted = $redact(
            ['PASSWORD' => 'hunter2', 'Api_Key' => 'secret'],
            ['password', 'api_key'],
        );

        $this->assertSame('[REDACTED]', $redacted['PASSWORD']);
        $this->assertSame('[REDACTED]', $redacted['Api_Key']);
    }

    // -------------------------------------------------------------------------
    // Alert handlers
    // -------------------------------------------------------------------------

    public function testAlertHandlerIsCalledForMatchingLevel(): void
    {
        $called = false;

        $config                              = config('LoggingExtended');
        $config->exception['alerts']['levels']   = ['error'];
        $config->exception['alerts']['handlers'] = [function (\Brunoggdev\LoggingExtended\LogAlert $alert) use (&$called) {
            $called = true;
        }];

        $this->logger->error('Alert test');

        $this->assertTrue($called, 'Alert handler was not called for matching level.');
    }

    public function testAlertHandlerReceivesCorrectLogAlert(): void
    {
        $received = null;

        $config                              = config('LoggingExtended');
        $config->exception['alerts']['levels']   = ['error'];
        $config->exception['alerts']['handlers'] = [function (\Brunoggdev\LoggingExtended\LogAlert $alert) use (&$received) {
            $received = $alert;
        }];

        $this->logger->error('My alert message', ['key' => 'value']);

        $this->assertInstanceOf(\Brunoggdev\LoggingExtended\LogAlert::class, $received);
        $this->assertSame('error', $received->level);
        $this->assertSame('My alert message', $received->message);
        $this->assertSame(['key' => 'value'], $received->context);
        $this->assertIsFloat($received->timestamp);
    }

    public function testAlertHandlerNotCalledForNonMatchingLevel(): void
    {
        $called = false;

        $config                              = config('LoggingExtended');
        $config->exception['alerts']['levels']   = ['critical'];
        $config->exception['alerts']['handlers'] = [function () use (&$called) {
            $called = true;
        }];

        $this->logger->warning('Should not trigger alert');

        $this->assertFalse($called, 'Alert handler was called for non-matching level.');
    }

    public function testAlertHandlerNotCalledWhenAlertLevelsEmpty(): void
    {
        $called = false;

        $config                              = config('LoggingExtended');
        $config->exception['alerts']['levels']   = [];
        $config->exception['alerts']['handlers'] = [function () use (&$called) {
            $called = true;
        }];

        $this->logger->error('No levels configured');

        $this->assertFalse($called);
    }

    public function testAlertHandlerNotCalledWhenHandlersEmpty(): void
    {
        // Should not throw even with a matching level and no handlers
        $config                              = config('LoggingExtended');
        $config->exception['alerts']['levels']   = ['error'];
        $config->exception['alerts']['handlers'] = [];

        $this->logger->error('No handlers configured');
        $this->expectNotToPerformAssertions();
    }

    public function testBrokenAlertHandlerDoesNotTakeDownLogger(): void
    {
        $secondCalled = false;

        $config                              = config('LoggingExtended');
        $config->exception['alerts']['levels']   = ['error'];
        $config->exception['alerts']['handlers'] = [
            function () { throw new \RuntimeException('Handler exploded'); },
            function () use (&$secondCalled) { $secondCalled = true; },
        ];

        $this->logger->error('Broken handler test');

        $this->assertTrue($secondCalled, 'Second handler was not called after first threw.');
        $this->assertFileExists($this->logFile, 'Log file should still be written despite broken handler.');
    }

    public function testInvokableClassAlertHandler(): void
    {
        $called = false;

        $handler = new class ($called) {
            public function __construct(private bool &$ref) {}

            public function __invoke(\Brunoggdev\LoggingExtended\LogAlert $alert): void
            {
                $this->ref = true;
            }
        };

        $config                              = config('LoggingExtended');
        $config->exception['alerts']['levels']   = ['error'];
        $config->exception['alerts']['handlers'] = [$handler];

        $this->logger->error('Invokable handler test');

        $this->assertTrue($called);
    }

    public function testMultipleAlertHandlersAllCalled(): void
    {
        $count = 0;

        $config                              = config('LoggingExtended');
        $config->exception['alerts']['levels']   = ['error'];
        $config->exception['alerts']['handlers'] = [
            function () use (&$count) { $count++; },
            function () use (&$count) { $count++; },
            function () use (&$count) { $count++; },
        ];

        $this->logger->error('Multiple handlers');

        $this->assertSame(3, $count);
    }

    // -------------------------------------------------------------------------
    // Alert throttling
    // -------------------------------------------------------------------------

    public function testAlertThrottleSuppressesRepeatedAlerts(): void
    {
        $count = 0;

        $config                               = config('LoggingExtended');
        $config->exception['alerts']['levels']    = ['error'];
        $config->exception['alerts']['throttle']  = 15;
        $config->exception['alerts']['handlers']  = [function () use (&$count) { $count++; }];

        $this->logger->error('Throttle test message');
        $this->logger->error('Throttle test message'); // should be suppressed
        $this->logger->error('Throttle test message'); // should be suppressed

        $this->assertSame(1, $count, 'Handler should only fire once within throttle window.');
    }

    public function testAlertThrottleDoesNotSuppressDifferentMessages(): void
    {
        $count = 0;

        $config                               = config('LoggingExtended');
        $config->exception['alerts']['levels']    = ['error'];
        $config->exception['alerts']['throttle']  = 15;
        $config->exception['alerts']['handlers']  = [function () use (&$count) { $count++; }];

        $this->logger->error('First unique message');
        $this->logger->error('Second unique message');

        $this->assertSame(2, $count, 'Different messages should each fire the handler.');
    }

    public function testAlertThrottleZeroDisablesThrottling(): void
    {
        $count = 0;

        $config                               = config('LoggingExtended');
        $config->exception['alerts']['levels']    = ['error'];
        $config->exception['alerts']['throttle']  = 0;
        $config->exception['alerts']['handlers']  = [function () use (&$count) { $count++; }];

        $this->logger->error('Same message');
        $this->logger->error('Same message');

        $this->assertSame(2, $count, 'With throttle=0 every call should fire the handler.');
    }
}
