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

        $line = $this->lastLogLine();
        $this->assertStringContainsString('[RuntimeException]', $line);
        $this->assertStringContainsString('Something exploded', $line);
    }

    public function testExceptionMethodRespectsLevel(): void
    {
        $e = new RuntimeException('Low severity');
        $this->logger->exception($e, 'warning');

        $this->assertStringContainsString('WARNING', $this->lastLogLine());
    }

    public function testExceptionObjectIsNotLeakedIntoOutput(): void
    {
        $e = new RuntimeException('Clean output');
        $this->logger->exception($e);

        $line = $this->lastLogLine();
        $this->assertStringNotContainsString('exception=', $line);
        $this->assertStringNotContainsString('RuntimeException Object', $line);
    }
}
