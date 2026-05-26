<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Controllers;

use Brunoggdev\LoggingExtended\Config\LoggingExtended;
use Brunoggdev\LoggingExtended\Services\LogViewerService;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;

class LogViewerController extends Controller
{
    private LoggingExtended $config;
    private string $logDir;
    private LogViewerService $service;

    public function __construct()
    {
        $this->config  = config('LoggingExtended');
        $this->logDir  = rtrim(WRITEPATH, '/') . '/logs/';
        $this->service = new LogViewerService($this->logDir);

        if ($this->config->viewer['gate'] === null || ! ($this->config->viewer['gate'])()) {
            throw new PageNotFoundException();
        }
    }

    public function index()
    {
        return view('\Brunoggdev\LoggingExtended\Views\log_viewer', [
            'logFiles'     => $this->service->listFiles(),
            'viewerConfig' => $this->config,
            'filename'     => null,
            'entries'      => null,
            'levelCounts'  => [],
            'activeLevels' => [],
            'searchQuery'  => '',
            'page'         => 1,
            'totalPages'   => 1,
            'total'        => 0,
            'isToday'      => false,
        ]);
    }

    public function show(string $filename)
    {
        $filename = $this->service->sanitizeFilename($filename);
        $path     = $this->logDir . $filename;

        if (! file_exists($path)) {
            throw new PageNotFoundException('Log file not found.');
        }

        $allEntries     = array_reverse($this->service->getEntries($path));
        $allLevelCounts = array_count_values(array_column($allEntries, 'level'));

        $activeLevels = array_values(array_filter(
            explode(',', $this->request->getGet('levels') ?? ''),
            fn ($l) => $l !== '',
        ));
        $query   = trim($this->request->getGet('q') ?? '');
        $perPage = $this->config->viewer['perPage'];

        $filtered   = $this->service->filterEntries($allEntries, $activeLevels, $query);
        $total      = count($filtered);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min(max(1, (int) ($this->request->getGet('page') ?? 1)), $totalPages);
        $entries    = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return view('\Brunoggdev\LoggingExtended\Views\log_viewer', [
            'logFiles'     => $this->service->listFiles(),
            'viewerConfig' => $this->config,
            'filename'     => $filename,
            'entries'      => $entries,
            'levelCounts'  => $allLevelCounts,
            'activeLevels' => $activeLevels,
            'searchQuery'  => $query,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'total'        => $total,
            'perPage'      => $perPage,
            'isToday'      => $filename === 'log-' . date('Y-m-d') . '.log',
        ]);
    }

    public function delete(string $filename)
    {
        $filename = $this->service->sanitizeFilename($filename);
        $path     = $this->logDir . $filename;

        if (file_exists($path)) {
            unlink($path);
            $this->service->clearCache($path);
        }

        return redirect()->to(site_url(trim($this->config->viewer['routesPath'], '/')));
    }

    public function deleteMultiple()
    {
        $filenames = (array) ($this->request->getPost('filenames') ?? []);

        foreach ($filenames as $filename) {
            try {
                $filename = $this->service->sanitizeFilename((string) $filename);
                $path     = $this->logDir . $filename;
                if (file_exists($path)) {
                    unlink($path);
                    $this->service->clearCache($path);
                }
            } catch (\Throwable) {
                // Skip invalid or non-existent filenames silently
            }
        }

        return redirect()->to(site_url(trim($this->config->viewer['routesPath'], '/')));
    }

    public function stream(): void
    {
        // Release the session lock so other requests aren't blocked while we stream.
        // Note: PHP's built-in single-threaded server will still block; use a proper
        // web server (Apache, Nginx, FrankenPHP) for concurrent SSE + normal requests.
        session_write_close();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        set_time_limit(0);
        ignore_user_abort(true);

        $logFile = $this->logDir . 'log-' . date('Y-m-d') . '.log';
        $pos     = file_exists($logFile) ? (int) filesize($logFile) : 0;

        while (true) {
            if (connection_aborted()) {
                break;
            }

            if (file_exists($logFile)) {
                clearstatcache(true, $logFile);
                $newSize = (int) filesize($logFile);

                if ($newSize > $pos) {
                    $handle = fopen($logFile, 'rb');
                    fseek($handle, $pos);
                    $chunk = fread($handle, $newSize - $pos);
                    fclose($handle);
                    $pos = $newSize;

                    // Group the chunk's raw lines into complete entries so that
                    // stacktrace continuations are merged and sent as one SSE event.
                    $entries = $this->service->groupLines(explode("\n", trim((string) $chunk)));

                    foreach ($entries as $entry) {
                        echo 'data: ' . json_encode($entry) . "\n\n";
                        flush();
                    }
                }
            }

            usleep(500_000);
        }

        exit;
    }

}
