<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * LogTail — A Laravel Pail-inspired log watcher for CodeIgniter 4
 *
 * Usage:
 *   php spark log:tail
 *   php spark log:tail -level error
 *   php spark log:tail -filter SendEmail
 *   php spark log:tail -lines 50
 *   php spark log:tail -level error -filter payment -lines 100
 */
class LogTail extends BaseCommand
{
    protected $group       = 'Logs';
    protected $name        = 'log:tail';
    protected $description = 'Tails the CI4 log file in real time, with colorized output.';
    protected $usage       = 'log:tail [-level <level>] [-filter <text>] [-lines <n>]';

    protected $options = [
        '-level'  => 'Filter by log level (emergency, alert, critical, error, warning, notice, info, debug).',
        '-filter' => 'Filter lines containing this text (case-insensitive).',
        '-lines'  => 'Number of existing lines to show on startup. Default: 20. Use 0 to skip.',
    ];

    /**
     * Maps log levels to CLI colors.
     *
     * @var array<string, string>
     */
    private const LEVEL_COLORS = [
        'emergency' => 'red',
        'alert'     => 'red',
        'critical'  => 'red',
        'error'     => 'red',
        'warning'   => 'yellow',
        'notice'    => 'cyan',
        'info'      => 'green',
        'debug'     => 'dark_gray',
    ];

    /**
     * Longest level name is 'emergency' = 9 chars.
     */
    private const LEVEL_PAD = 9;

    public function run(array $params): void
    {
        $levelFilter  = strtolower((string) (CLI::getOption('level') ?? ''));
        $textFilter   = strtolower((string) (CLI::getOption('filter') ?? ''));
        $initialLines = (int) (CLI::getOption('lines') ?? 20);

        $logPath = $this->resolveLogPath();

        if ($logPath === null) {
            CLI::error('Log directory not found. Expected: ' . WRITEPATH . 'logs/');
            return;
        }

        $this->printHeader($levelFilter, $textFilter);

        $lastFile   = '';
        $fileHandle = null;
        $filePos    = 0;
        $booted     = false;

        // Register a signal handler so Ctrl+C exits cleanly (if PCNTL is available)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$fileHandle) {
                if ($fileHandle) {
                    fclose($fileHandle);
                }
                CLI::newLine();
                CLI::write(CLI::color('  Stopped watching.', 'dark_gray'));
                CLI::newLine();
                exit(0);
            });
        }

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $currentFile = $this->resolveLogPath();

            // If the log file rolled over (new day) or this is the first iteration
            if ($currentFile !== $lastFile) {
                if ($fileHandle) {
                    fclose($fileHandle);
                }

                $lastFile = $currentFile;

                if ($currentFile === null || ! file_exists($currentFile)) {
                    $lastFile = ''; // reset so we re-enter this block next iteration
                    usleep(500_000);
                    continue;
                }

                $fileHandle = fopen($currentFile, 'r');

                if (! $booted && $initialLines > 0) {
                    $tail = $this->tailFile($currentFile, $initialLines);

                    foreach ($tail as $line) {
                        $this->outputLine($line, $levelFilter, $textFilter);
                    }

                    $booted = true;
                }

                // Seek to end so we only watch new lines going forward
                fseek($fileHandle, 0, SEEK_END);
                $filePos = ftell($fileHandle);
            }

            if ($fileHandle === null) {
                usleep(500_000);
                continue;
            }

            // Guard against file disappearing mid-watch (e.g. log rotation)
            if (! file_exists($currentFile)) {
                $lastFile = '';
                fclose($fileHandle);
                $fileHandle = null;
                usleep(500_000);
                continue;
            }

            clearstatcache(true, $currentFile);
            $newSize = filesize($currentFile);

            if ($newSize > $filePos) {
                fseek($fileHandle, $filePos);
                $chunk   = fread($fileHandle, $newSize - $filePos);
                $filePos = ftell($fileHandle);

                $lines = explode("\n", $chunk);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line === '<?php') {
                        continue;
                    }
                    $this->outputLine($line, $levelFilter, $textFilter);
                }
            } elseif ($newSize < $filePos) {
                // File was truncated — reset
                $filePos = 0;
                fseek($fileHandle, 0);
            }

            usleep(500_000);
        }
    }

    /**
     * Resolves today's CI4 log file path.
     */
    private function resolveLogPath(): ?string
    {
        $config   = config('Logger');
        $handlers = $config->handlers ?? [];

        foreach ($handlers as $handler => $cfg) {
            if (str_contains($handler, 'FileHandler')) {
                $path = $cfg['path'] ?? '';
                $dir  = rtrim($path !== '' ? $path : WRITEPATH . 'logs', '/') . '/';
                return $dir . 'log-' . date('Y-m-d') . '.log';
            }
        }

        return rtrim(WRITEPATH, '/') . '/logs/log-' . date('Y-m-d') . '.log';
    }

    /**
     * Reads the last $n lines of a file efficiently (reverse chunk reading).
     *
     * @return string[]
     */
    private function tailFile(string $path, int $n): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $lines = array_filter($lines, fn($l) => trim($l) !== '<?php');

        return array_slice($lines, -$n);
    }

    /**
     * Parses and outputs a single log line with colorization and filtering.
     *
     * CI4 log format: "ERROR - YYYY-MM-DD HH:MM:SS --> Message"
     */
    private function outputLine(string $line, string $levelFilter, string $textFilter): void
    {
        if (! preg_match('/^(\w+)\s+-\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+-->\s+(.+)$/s', $line, $m)) {
            // Multiline continuation — print dimmed
            CLI::write(CLI::color('  ' . $line, 'dark_gray'));
            return;
        }

        [, $level, $datetime, $message] = $m;

        $levelLower = strtolower($level);

        if ($levelFilter !== '' && $levelLower !== $levelFilter) {
            return;
        }

        if ($textFilter !== '' && ! str_contains(strtolower($line), $textFilter)) {
            return;
        }

        $color      = self::LEVEL_COLORS[$levelLower] ?? 'white';
        $levelLabel = str_pad(strtoupper($level), self::LEVEL_PAD);

        // Split message into main text and context (after " | ")
        if (str_contains($message, ' | ')) {
            [$mainMsg, $context] = explode(' | ', $message, 2);
            $contextDisplay      = CLI::color(' | ' . $context, 'cyan');
        } else {
            $mainMsg        = $message;
            $contextDisplay = '';
        }

        CLI::write(sprintf(
            '  %s  %s  %s%s',
            CLI::color($levelLabel, $color),
            CLI::color($datetime, 'dark_gray'),
            $mainMsg,
            $contextDisplay
        ));
    }

    /**
     * Prints the startup banner.
     */
    private function printHeader(string $levelFilter, string $textFilter): void
    {
        CLI::newLine();
        CLI::write(CLI::color('  CI4 Log Tail', 'green') . CLI::color(" — watching today's log file", 'dark_gray'));

        if ($levelFilter !== '') {
            CLI::write(CLI::color('  Level filter : ', 'dark_gray') . CLI::color($levelFilter, 'yellow'));
        }

        if ($textFilter !== '') {
            CLI::write(CLI::color('  Text filter  : ', 'dark_gray') . CLI::color($textFilter, 'yellow'));
        }

        CLI::write(CLI::color('  Press Ctrl+C to stop.', 'dark_gray'));
        CLI::newLine();
        CLI::write(CLI::color(str_repeat('─', 72), 'dark_gray'));
        CLI::newLine();
    }
}
