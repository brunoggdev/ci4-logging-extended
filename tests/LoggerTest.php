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
        $config                     = config('LoggingExtended');
        $config->exception['user']  = fn () => ['id' => 42, 'email' => 'user@example.com'];
        $config->exception['trace'] = false;

        $e = new RuntimeException('With user');
        $this->logger->exception($e);

        $line = $this->lastLogLine();
        $this->assertStringContainsString('"id":42', $line);
        $this->assertStringContainsString('"email":"user@example.com"', $line);
    }

    public function testExceptionUserCallableReturningNullOmitsUserKey(): void
    {
        $config                     = config('LoggingExtended');
        $config->exception['user']  = fn () => null;
        $config->exception['trace'] = false;

        $e = new RuntimeException('No user');
        $this->logger->exception($e);

        $line = $this->lastLogLine();
        $this->assertStringNotContainsString('user=', $line);
    }

    public function testExceptionWithContextArrayResolvesEachKey(): void
    {
        $config                      = config('LoggingExtended');
        $config->exception['trace']  = false;
        $config->exception['context'] = [
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
        $config                       = config('LoggingExtended');
        $config->exception['trace']   = false;
        $config->exception['context'] = [
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
        $config->exception['session'] = true;

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
}
