<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Services;

use CodeIgniter\Cache\CacheFactory;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Cache as CacheConfig;

class LogViewerService
{
    private CacheInterface $cache;

    public function __construct(private string $logDir)
    {
        $storePath = WRITEPATH . 'cache/log_viewer/';
        if (! is_dir($storePath)) {
            mkdir($storePath, 0755, true);
        }

        $config          = new CacheConfig();
        $config->handler = 'file';
        $config->file    = ['storePath' => $storePath, 'mode' => 0640];
        $this->cache     = CacheFactory::getHandler($config);
    }

    /**
     * Returns metadata for all log files, sorted newest first.
     *
     * @return list<array{name:string,size:string,isToday:bool}>
     */
    public function listFiles(): array
    {
        $files = glob($this->logDir . '*.log') ?: [];
        rsort($files);

        $today = 'log-' . date('Y-m-d') . '.log';

        return array_map(fn (string $path) => [
            'name'    => basename($path),
            'size'    => $this->formatSize((int) filesize($path)),
            'isToday' => basename($path) === $today,
        ], $files);
    }

    /**
     * Parses a log file into grouped entries (full read, no truncation).
     *
     * @return list<array{level:string,datetime:string,message:string,context:array<string,string>,stacktrace:list<string>}>
     */
    public function parseLogFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return $this->groupLines($lines);
    }

    /**
     * Returns parsed entries from cache when available, re-parsing only when the file changes.
     * Cache key is based on the file path; stored alongside mtime for staleness detection.
     *
     * @return list<array{level:string,datetime:string,message:string,context:array<string,string>,stacktrace:list<string>}>
     */
    public function getEntries(string $path): array
    {
        $key    = 'lv_' . md5($path);
        $mtime  = (int) filemtime($path);
        $cached = $this->cache->get($key);

        if (is_array($cached) && ($cached['mtime'] ?? 0) === $mtime) {
            return $cached['entries'];
        }

        $entries = $this->parseLogFile($path);
        $this->cache->save($key, ['mtime' => $mtime, 'entries' => $entries], 3_600);

        return $entries;
    }

    /**
     * Removes the cache entry for a given log file path.
     * Call this when deleting a log file so stale data doesn't linger.
     */
    public function clearCache(string $path): void
    {
        $this->cache->delete('lv_' . md5($path));
    }

    /**
     * Filters entries by level list and/or search query.
     *
     * Query supports space-separated terms combined with AND logic.
     * Each term can be one of:
     *   - "key.sub=value"  dot-notation context lookup (value supports regex)
     *   - "key.sub"        dot-notation presence check
     *   - anything else    regex / literal matched against message and all context values
     *
     * Examples:
     *   user.email=foo@bar.com tenant=acme
     *   request.method=POST 500
     *   user.email=.*@company\.com session.role=admin
     *
     * @param  list<string>  $levels
     * @return list<array>
     */
    public function filterEntries(array $entries, array $levels, string $query): array
    {
        if ($levels !== []) {
            $entries = array_values(array_filter(
                $entries,
                fn ($e) => in_array($e['level'], $levels, true),
            ));
        }

        if ($query !== '') {
            $terms = preg_split('/\s+/', trim($query));

            // Load the array helper only when a dot-notation term is present.
            // CI4 autoloads it in web context, but not reliably in CLI or test
            // environments, so we load it explicitly rather than assuming it's there.
            foreach ($terms as $term) {
                if (preg_match('/^[\w]+\.[\w.]+/i', $term)) {
                    helper('array');
                    break;
                }
            }

            $entries = array_values(array_filter(
                $entries,
                fn ($e) => $this->matchesAllTerms($e, $terms),
            ));
        }

        return $entries;
    }

    /**
     * Returns true only if the entry matches every term (AND logic).
     *
     * @param list<string> $terms
     */
    private function matchesAllTerms(array $entry, array $terms): bool
    {
        foreach ($terms as $term) {
            if (! $this->matchesTerm($entry, $term)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tests a single search term against an entry.
     * Dot-notation terms search structured context; others search message + raw context.
     */
    private function matchesTerm(array $entry, string $term): bool
    {
        // Dot-notation: "key.subkey" or "key.subkey=value"
        if (preg_match('/^([\w]+\.[\w.]+)(?:=(.+))?$/i', $term, $m)) {
            $topKey  = strstr($m[1], '.', true);
            $subPath = substr(strstr($m[1], '.'), 1);
            $topVal  = $entry['context'][$topKey] ?? null;

            if (! is_array($topVal)) {
                return false;
            }

            $found = dot_array_search($subPath, $topVal);

            if ($found === null) {
                return false;
            }

            if (! isset($m[2])) {
                return true;
            }

            // Try as regex, fall back to literal equality
            $result = @preg_match('/' . $m[2] . '/i', (string) $found);

            return $result === false
                ? (string) $found === $m[2]
                : (bool) $result;
        }

        // Text / regex across message + all context key=value pairs
        $haystack = $entry['message'];

        foreach ($entry['context'] as $key => $val) {
            $haystack .= is_array($val)
                ? ' ' . json_encode($val)
                : ' ' . $key . '=' . $val;
        }

        $result = @preg_match('/' . $term . '/i', $haystack);

        return $result === false
            ? stripos($haystack, $term) !== false
            : (bool) $result;
    }

    /**
     * Groups raw log lines into structured entries, merging stacktrace continuation
     * lines into the preceding entry rather than emitting them as separate rows.
     * Each entry gets an `occurrence` (Nth time this message appears) and `total`
     * (how many times it appears across the whole file).
     *
     * @param  list<string>                                                                                                                           $rawLines
     * @return list<array{level:string,datetime:string,message:string,context:array<string,mixed>,stacktrace:list<string>,occurrence:int,total:int}>
     */
    public function groupLines(array $rawLines): array
    {
        $entries = [];
        $current = null;

        foreach ($rawLines as $line) {
            $line = trim($line);

            if ($line === '' || $line === '<?php') {
                continue;
            }

            if ($this->isMainLine($line)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = $this->parseLine($line);
            } elseif ($current !== null) {
                $current['stacktrace'][] = $line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $this->assignOccurrences($entries);
    }

    /**
     * Counts how many times each unique message appears across the entry list,
     * then assigns each entry its occurrence index and the total count.
     *
     * @param  list<array>  $entries
     * @return list<array>
     */
    private function assignOccurrences(array $entries): array
    {
        $totals = [];
        foreach ($entries as $entry) {
            $totals[$entry['message']] = ($totals[$entry['message']] ?? 0) + 1;
        }

        $seen = [];
        foreach ($entries as &$entry) {
            $msg                = $entry['message'];
            $seen[$msg]         = ($seen[$msg] ?? 0) + 1;
            $entry['occurrence'] = $seen[$msg];
            $entry['total']      = $totals[$msg];
        }
        unset($entry);

        return $entries;
    }

    /**
     * Parses a main log line into a structured entry.
     *
     * @return array{level:string,datetime:string,message:string,context:array<string,string>,stacktrace:list<string>}
     */
    public function parseLine(string $line): array
    {
        if (! preg_match('/^(\w+)\s+-\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+-->\s+(.+)$/s', $line, $m)) {
            return ['level' => '', 'datetime' => '', 'message' => $line, 'context' => [], 'stacktrace' => []];
        }

        [, $level, $datetime, $message] = $m;
        $context                        = [];

        if (str_contains($message, ' | ')) {
            [$message, $contextStr] = explode(' | ', $message, 2);
            $context                = $this->parseContext(trim($contextStr));
        }

        return [
            'level'      => strtolower($level),
            'datetime'   => $datetime,
            'message'    => trim($message),
            'context'    => $context,
            'stacktrace' => [],
        ];
    }

    /**
     * Validates and sanitizes a log filename.
     *
     * @throws PageNotFoundException
     */
    public function sanitizeFilename(string $filename): string
    {
        $filename = basename(str_replace("\0", '', $filename));

        if (! preg_match('/^log-\d{4}-\d{2}-\d{2}\.log$/', $filename)) {
            throw new PageNotFoundException('Log file not found.');
        }

        return $filename;
    }

    private function isMainLine(string $line): bool
    {
        return (bool) preg_match('/^\w+\s+-\s+\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+-->/', $line);
    }

    /**
     * Parses key=value pairs from a context string into an associative array.
     * Handles quoted strings, JSON objects/arrays (with depth tracking), and plain scalars.
     * JSON values are decoded into arrays for dot-notation search support.
     *
     * @return array<string,mixed>
     */
    private function parseContext(string $str): array
    {
        $context = [];
        $i       = 0;
        $len     = strlen($str);

        while ($i < $len) {
            while ($i < $len && ctype_space($str[$i])) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            $key = '';

            while ($i < $len && (ctype_alnum($str[$i]) || $str[$i] === '_')) {
                $key .= $str[$i++];
            }

            if ($key === '' || $i >= $len || $str[$i] !== '=') {
                break;
            }

            $i++;

            if ($i >= $len) {
                break;
            }

            $val = match ($str[$i]) {
                '"'      => $this->readQuoted($str, $i, $len),
                '{', '[' => $this->readJson($str, $i, $len),
                default  => $this->readPlain($str, $i, $len),
            };

            $decoded       = json_decode($val, true);
            $context[$key] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                ? $decoded
                : trim($val, '"');
        }

        return $context;
    }

    private function readQuoted(string $str, int &$i, int $len): string
    {
        $val = '"';
        $i++;

        while ($i < $len) {
            if ($str[$i] === '\\' && $i + 1 < $len) {
                $val .= $str[$i] . $str[$i + 1];
                $i   += 2;
                continue;
            }

            $val .= $str[$i];

            if ($str[$i++] === '"') {
                break;
            }
        }

        return $val;
    }

    private function readJson(string $str, int &$i, int $len): string
    {
        $open  = $str[$i] === '{' ? '{' : '[';
        $close = $str[$i] === '{' ? '}' : ']';
        $depth = 0;
        $val   = '';
        $inStr = false;

        while ($i < $len) {
            $ch = $str[$i];

            if ($inStr) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $val .= $ch . $str[$i + 1];
                    $i   += 2;
                    continue;
                }

                if ($ch === '"') {
                    $inStr = false;
                }
            } else {
                if ($ch === '"') {
                    $inStr = true;
                } elseif ($ch === $open) {
                    $depth++;
                } elseif ($ch === $close && --$depth === 0) {
                    $val .= $ch;
                    $i++;
                    break;
                }
            }

            $val .= $ch;
            $i++;
        }

        return $val;
    }

    private function readPlain(string $str, int &$i, int $len): string
    {
        $val = '';

        while ($i < $len && ! ctype_space($str[$i])) {
            $val .= $str[$i++];
        }

        return $val;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1_048_576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1_048_576, 1) . ' MB';
    }
}
