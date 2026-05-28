<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Tests;

use Brunoggdev\LoggingExtended\Services\LogViewerService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class LogViewerServiceTest extends CIUnitTestCase
{
    private LogViewerService $service;
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . '/log_viewer_test_' . uniqid() . '/';
        mkdir($this->logDir, 0755, true);
        $this->service = new LogViewerService($this->logDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up temp log dir
        foreach (glob($this->logDir . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->logDir)) {
            rmdir($this->logDir);
        }
    }

    // -------------------------------------------------------------------------
    // parseLine()
    // -------------------------------------------------------------------------

    public function testParseLineValidMainLineParsesLevelDatetimeAndMessage(): void
    {
        $line   = 'ERROR - 2024-01-15 10:30:00 --> Something went wrong';
        $result = $this->service->parseLine($line);

        $this->assertSame('error', $result['level']);
        $this->assertSame('2024-01-15 10:30:00', $result['datetime']);
        $this->assertSame('Something went wrong', $result['message']);
        $this->assertSame([], $result['context']);
        $this->assertSame([], $result['stacktrace']);
    }

    public function testParseLineWithKeyValueContextParsesContextMap(): void
    {
        $line   = 'INFO - 2024-01-15 11:00:00 --> Job finished | job=SendEmail attempt=3';
        $result = $this->service->parseLine($line);

        $this->assertSame('info', $result['level']);
        $this->assertSame('Job finished', $result['message']);
        $this->assertSame('SendEmail', $result['context']['job']);
        $this->assertSame('3', $result['context']['attempt']);
    }

    public function testParseLineWithJsonContextDecodesAsArray(): void
    {
        $json   = json_encode(['method' => 'GET', 'url' => 'https://example.com/api']);
        $line   = 'ERROR - 2024-01-15 12:00:00 --> Request failed | request=' . $json;
        $result = $this->service->parseLine($line);

        $this->assertIsArray($result['context']['request']);
        $this->assertSame('GET', $result['context']['request']['method']);
        $this->assertSame('https://example.com/api', $result['context']['request']['url']);
    }

    public function testParseLineWithQuotedStringContextHandlesQuotes(): void
    {
        $line   = 'WARNING - 2024-01-15 13:00:00 --> Validation failed | error="some quoted message"';
        $result = $this->service->parseLine($line);

        $this->assertSame('some quoted message', $result['context']['error']);
    }

    public function testParseLineUnrecognisedLineReturnsEmptyLevelAndDatetimeWithRawMessage(): void
    {
        $line   = '#0 /var/www/app/Controllers/Home.php(42): someMethod()';
        $result = $this->service->parseLine($line);

        $this->assertSame('', $result['level']);
        $this->assertSame('', $result['datetime']);
        $this->assertSame($line, $result['message']);
        $this->assertSame([], $result['context']);
    }

    // -------------------------------------------------------------------------
    // groupLines()
    // -------------------------------------------------------------------------

    public function testGroupLinesMultipleMainLinesProduceMultipleEntries(): void
    {
        $lines = [
            'ERROR - 2024-01-15 10:00:00 --> First error',
            'INFO - 2024-01-15 10:01:00 --> Second info',
            'WARNING - 2024-01-15 10:02:00 --> Third warning',
        ];

        $entries = $this->service->groupLines($lines);

        $this->assertCount(3, $entries);
        $this->assertSame('error', $entries[0]['level']);
        $this->assertSame('info', $entries[1]['level']);
        $this->assertSame('warning', $entries[2]['level']);
    }

    public function testGroupLinesStacktraceLinesMergedIntoPrecedingEntry(): void
    {
        $lines = [
            'ERROR - 2024-01-15 10:00:00 --> Exception occurred',
            '#0 /app/Controllers/Home.php(10): doThing()',
            '#1 /app/index.php(20): dispatch()',
        ];

        $entries = $this->service->groupLines($lines);

        $this->assertCount(1, $entries);
        $this->assertCount(2, $entries[0]['stacktrace']);
        $this->assertSame('#0 /app/Controllers/Home.php(10): doThing()', $entries[0]['stacktrace'][0]);
        $this->assertSame('#1 /app/index.php(20): dispatch()', $entries[0]['stacktrace'][1]);
    }

    public function testGroupLinesSkipsPhpOpeningTagAndEmptyLines(): void
    {
        $lines = [
            '<?php',
            '',
            'ERROR - 2024-01-15 10:00:00 --> Only entry',
            '',
        ];

        $entries = $this->service->groupLines($lines);

        $this->assertCount(1, $entries);
        $this->assertSame('Only entry', $entries[0]['message']);
    }

    // -------------------------------------------------------------------------
    // assignOccurrences() — tested via groupLines()
    // -------------------------------------------------------------------------

    public function testDuplicateMessagesGetIncrementingOccurrenceAndCorrectTotal(): void
    {
        $lines = [
            'ERROR - 2024-01-15 10:00:00 --> Same message',
            'ERROR - 2024-01-15 10:01:00 --> Same message',
            'ERROR - 2024-01-15 10:02:00 --> Same message',
        ];

        $entries = $this->service->groupLines($lines);

        $this->assertSame(1, $entries[0]['occurrence']);
        $this->assertSame(2, $entries[1]['occurrence']);
        $this->assertSame(3, $entries[2]['occurrence']);
        $this->assertSame(3, $entries[0]['total']);
        $this->assertSame(3, $entries[1]['total']);
        $this->assertSame(3, $entries[2]['total']);
    }

    public function testUniqueMessagesGetOccurrenceOneAndTotalOne(): void
    {
        $lines = [
            'ERROR - 2024-01-15 10:00:00 --> Unique message A',
            'INFO - 2024-01-15 10:01:00 --> Unique message B',
        ];

        $entries = $this->service->groupLines($lines);

        $this->assertSame(1, $entries[0]['occurrence']);
        $this->assertSame(1, $entries[0]['total']);
        $this->assertSame(1, $entries[1]['occurrence']);
        $this->assertSame(1, $entries[1]['total']);
    }

    // -------------------------------------------------------------------------
    // filterEntries()
    // -------------------------------------------------------------------------

    private function makeEntries(): array
    {
        return [
            [
                'level'      => 'error',
                'datetime'   => '2024-01-15 10:00:00',
                'message'    => 'Database connection failed',
                'context'    => ['location' => '/app/Models/User.php:42'],
                'stacktrace' => [],
                'occurrence' => 1,
                'total'      => 1,
            ],
            [
                'level'      => 'info',
                'datetime'   => '2024-01-15 10:01:00',
                'message'    => 'User logged in',
                'context'    => [
                    'user'    => ['id' => 7, 'email' => 'alice@example.com'],
                    'request' => ['method' => 'POST', 'url' => 'https://example.com/login'],
                ],
                'stacktrace' => [],
                'occurrence' => 1,
                'total'      => 1,
            ],
            [
                'level'      => 'warning',
                'datetime'   => '2024-01-15 10:02:00',
                'message'    => 'Slow query detected',
                'context'    => [
                    'query' => ['duration' => '2.3s', 'table' => 'orders'],
                ],
                'stacktrace' => [],
                'occurrence' => 1,
                'total'      => 1,
            ],
        ];
    }

    public function testFilterEntriesEmptyLevelsArrayAppliesNoLevelFilter(): void
    {
        $entries  = $this->makeEntries();
        $filtered = $this->service->filterEntries($entries, [], '');

        $this->assertCount(3, $filtered);
    }

    public function testFilterEntriesSpecificLevelReturnsOnlyMatchingEntries(): void
    {
        $entries  = $this->makeEntries();
        $filtered = $this->service->filterEntries($entries, ['error'], '');

        $this->assertCount(1, $filtered);
        $this->assertSame('error', $filtered[0]['level']);
    }

    public function testFilterEntriesTextSearchMatchesMessageContent(): void
    {
        $entries  = $this->makeEntries();
        $filtered = $this->service->filterEntries($entries, [], 'logged in');

        $this->assertCount(1, $filtered);
        $this->assertSame('User logged in', $filtered[0]['message']);
    }

    public function testFilterEntriesTextSearchMatchesContextValues(): void
    {
        $entries  = $this->makeEntries();
        $filtered = $this->service->filterEntries($entries, [], 'alice@example.com');

        $this->assertCount(1, $filtered);
        $this->assertSame('User logged in', $filtered[0]['message']);
    }

    public function testFilterEntriesDotNotationPresenceCheckReturnsEntriesWithThatKey(): void
    {
        $entries  = $this->makeEntries();
        $filtered = $this->service->filterEntries($entries, [], 'user.email');

        $this->assertCount(1, $filtered);
        $this->assertSame('User logged in', $filtered[0]['message']);
    }

    public function testFilterEntriesDotNotationEqualityMatchesLiteralValue(): void
    {
        $entries  = $this->makeEntries();
        $filtered = $this->service->filterEntries($entries, [], 'request.method=POST');

        $this->assertCount(1, $filtered);
        $this->assertSame('User logged in', $filtered[0]['message']);
    }

    public function testFilterEntriesDotNotationRegexMatchesValue(): void
    {
        $entries  = $this->makeEntries();
        $filtered = $this->service->filterEntries($entries, [], 'user.email=.*@example\.com');

        $this->assertCount(1, $filtered);
        $this->assertSame('User logged in', $filtered[0]['message']);
    }

    public function testFilterEntriesMultiTermAndBothTermsMustMatch(): void
    {
        $entries  = $this->makeEntries();
        // Both terms must match: only the "User logged in" entry matches both
        $filtered = $this->service->filterEntries($entries, [], 'User POST');

        $this->assertCount(1, $filtered);
        $this->assertSame('User logged in', $filtered[0]['message']);
    }

    public function testFilterEntriesMultiTermAndExcludesPartialMatch(): void
    {
        $entries  = $this->makeEntries();
        // "logged" matches only the info entry; "Database" matches only the error entry — AND must fail
        $filtered = $this->service->filterEntries($entries, [], 'logged Database');

        $this->assertCount(0, $filtered);
    }

    public function testFilterEntriesTextSearchMatchesScalarContextKeyValuePair(): void
    {
        $entries  = $this->makeEntries();
        // 'location' is a scalar context key — searching key=value should match
        $filtered = $this->service->filterEntries($entries, [], 'location=/app/Models');

        $this->assertCount(1, $filtered);
        $this->assertSame('Database connection failed', $filtered[0]['message']);
    }

    public function testFilterEntriesNonMatchingQueryReturnsEmptyArray(): void
    {
        $entries  = $this->makeEntries();
        $filtered = $this->service->filterEntries($entries, [], 'xyzzy_no_match_here');

        $this->assertSame([], $filtered);
    }

    public function testFilterEntriesInvalidRegexFallsBackToLiteralMatch(): void
    {
        $entries  = $this->makeEntries();
        // "(" is an invalid regex but should fall back to literal search without crashing
        $filtered = $this->service->filterEntries($entries, [], 'Database connection failed(');

        $this->assertCount(0, $filtered);
    }

    // -------------------------------------------------------------------------
    // sanitizeFilename()
    // -------------------------------------------------------------------------

    public function testSanitizeFilenameValidFilenamePassesThroughUnchanged(): void
    {
        $result = $this->service->sanitizeFilename('log-2024-01-15.log');

        $this->assertSame('log-2024-01-15.log', $result);
    }

    /**
     * @dataProvider invalidFilenameProvider
     */
    public function testSanitizeFilenameInvalidFilenameThrowsPageNotFoundException(string $filename): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->sanitizeFilename($filename);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFilenameProvider(): array
    {
        return [
            'path traversal'    => ['../../etc/passwd'],
            'wrong format'      => ['access.log'],
            'null byte suffix'  => ["log-2024-01-15.log\x00.php"],
            'missing extension' => ['log-2024-01-15'],
        ];
    }
}
