<?php
/**
 * @var list<array{name:string,size:string,isToday:bool}>                                                  $logFiles
 * @var string|null                                                                                         $filename
 * @var list<array{level:string,datetime:string,message:string,context:array,stacktrace:list<string>,occurrence:int,total:int}>|null $entries
 * @var array<string,int>                                                                                   $levelCounts
 * @var list<string>                                                                                        $activeLevels
 * @var string                                                                                              $searchQuery
 * @var int                                                                                                 $page
 * @var int                                                                                                 $totalPages
 * @var int                                                                                                 $total
 * @var int                                                                                                 $perPage
 * @var bool                                                                                                $isToday
 * @var \Brunoggdev\LoggingExtended\Config\LoggingExtended                                                  $viewerConfig
 */

// Guard all view helper functions against fatal "Cannot redeclare" errors if the
// view is ever included more than once (e.g. in tests or SSE partial renders).
if (! function_exists('ideLink')) {
    function ideLink(string $ide, string $file, string $line, ?string $wslDistro = null, ?string $serverPath = null, ?string $localPath = null): ?string
    {
        if ($serverPath !== null && $localPath !== null) {
            $file = str_replace($serverPath, $localPath, $file);
        }

        return match ($ide) {
            'vscode' => $wslDistro
                ? 'vscode://vscode-remote/wsl+' . $wslDistro . $file . ':' . $line
                : 'vscode://file/' . $file . ':' . $line,
            'phpstorm' => 'phpstorm://open?file=' . urlencode($file) . '&line=' . $line,
            default    => null,
        };
    }
}

if (! function_exists('parseStackFrame')) {
    function parseStackFrame(string $frame): ?array
    {
        if (preg_match('/#\d+ (\/[^(]+)\((\d+)\)/', $frame, $m)) {
            return ['file' => $m[1], 'line' => $m[2]];
        }
        return null;
    }
}

if (! function_exists('levelBadgeClass')) {
    function levelBadgeClass(string $level): string
    {
        return match (true) {
            in_array($level, ['emergency', 'alert', 'critical', 'error'], true) => 'text-bg-danger',
            $level === 'warning'                                                 => 'text-bg-warning',
            in_array($level, ['notice', 'info'], true)                          => 'text-bg-info',
            default                                                              => 'text-bg-secondary',
        };
    }
}

if (! function_exists('levelColor')) {
    function levelColor(string $level): string
    {
        return match (true) {
            in_array($level, ['emergency', 'alert', 'critical', 'error'], true) => 'danger',
            $level === 'warning'                                                 => 'warning',
            in_array($level, ['notice', 'info'], true)                          => 'info',
            default                                                              => 'secondary',
        };
    }
}

if (! function_exists('levelTextClass')) {
    function levelTextClass(string $level): string
    {
        return match (true) {
            in_array($level, ['emergency', 'alert', 'critical', 'error'], true) => 'text-danger',
            $level === 'warning'                                                 => 'text-warning',
            in_array($level, ['notice', 'info'], true)                          => 'text-info',
            default                                                              => 'text-secondary',
        };
    }
}

if (! function_exists('levelIcon')) {
    function levelIcon(string $level): string
    {
        return match (true) {
            in_array($level, ['emergency', 'alert'], true) => 'bi-exclamation-octagon-fill',
            $level === 'critical'                           => 'bi-exclamation-diamond-fill',
            $level === 'error'                              => 'bi-exclamation-circle-fill',
            $level === 'warning'                            => 'bi-exclamation-triangle-fill',
            $level === 'notice'                             => 'bi-info-square-fill',
            $level === 'info'                               => 'bi-info-circle-fill',
            default                                         => 'bi-bug-fill',
        };
    }
}

if (! function_exists('lvUrl')) {
    function lvUrl(string $basePath, string $filename, int $page, array $levels, string $query): string
    {
        $params = array_filter([
            'page'   => $page > 1 ? $page : null,
            'levels' => $levels !== [] ? implode(',', $levels) : null,
            'q'      => $query !== '' ? $query : null,
        ], fn ($v) => $v !== null);

        return site_url($basePath . '/' . $filename) . ($params ? '?' . http_build_query($params) : '');
    }
}

if (! function_exists('lvToggleLevelUrl')) {
    function lvToggleLevelUrl(string $basePath, string $filename, string $level, array $activeLevels, string $query): string
    {
        if ($level === 'all') {
            $new = [];
        } elseif (in_array($level, $activeLevels, true)) {
            $new = array_values(array_filter($activeLevels, fn ($l) => $l !== $level));
        } else {
            $new = [...$activeLevels, $level];
        }

        return lvUrl($basePath, $filename, 1, $new, $query);
    }
}

$basePath      = trim($viewerConfig->viewer['routesPath'], '/');
$deeplink      = $viewerConfig->viewer['deeplink'];
$levelOrder    = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
$presentLevels = array_values(array_intersect($levelOrder, array_keys($levelCounts)));
$allActive     = $activeLevels === [];

include __DIR__ . '/log_viewer_head.php';
?>

<div class="d-flex" style="height:100dvh;overflow:hidden">

    <!-- ── Sidebar ──────────────────────────────────────────────────────── -->
    <aside class="lv-sidebar d-flex flex-column border-end bg-body-tertiary flex-shrink-0" style="width:260px">

        <div class="p-3 border-bottom d-flex align-items-center gap-2">
            <span class="fw-semibold me-auto"><i class="bi bi-journal-text me-1"></i>Log Viewer</span>
            <select id="lv-bsw" class="form-select form-select-sm" style="width:auto" aria-label="Bootswatch theme">
                <?php foreach (['brite','cerulean','cosmo','cyborg','darkly','flatly','litera','lumen','materia','minty','pulse','sandstone','simplex','sketchy','slate','solar','spacelab','superhero','united','vapor','yeti','zephyr'] as $t): ?>
                    <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                <?php endforeach ?>
            </select>
            <button id="lv-theme-btn" class="btn btn-sm btn-outline-secondary flex-shrink-0" aria-label="Toggle dark/light">
                <i id="lv-theme-icon" class="bi bi-sun-fill"></i>
            </button>
        </div>

        <div class="px-2 py-2 border-bottom d-flex gap-2">
            <input type="search" class="form-control form-control-sm" id="lv-file-filter" placeholder="Filter files...">
            <button id="lv-select-btn" class="btn btn-sm btn-outline-secondary flex-shrink-0" aria-label="Select files" title="Select files">
                <i class="bi bi-ui-checks"></i>
            </button>
        </div>

        <div class="flex-grow-1 overflow-y-auto">
            <?php if (empty($logFiles)): ?>
                <p class="text-center text-body-secondary small p-4">
                    No log files found in <code><?= esc(WRITEPATH . 'logs/') ?></code>
                </p>
            <?php else: ?>
                <?php foreach ($logFiles as $file):
                    $isActive = $file['name'] === ($filename ?? '');
                ?>
                    <div class="lv-file-item d-flex align-items-center border-bottom <?= $isActive ? 'bg-primary-subtle' : '' ?>"
                         data-name="<?= esc($file['name']) ?>">
                        <label class="lv-file-check align-items-center ps-2 mb-0" style="cursor:pointer">
                            <input type="checkbox" class="form-check-input mt-0" value="<?= esc($file['name']) ?>" aria-label="Select <?= esc($file['name']) ?>">
                        </label>
                        <a href="<?= site_url($basePath . '/' . $file['name']) ?>"
                           class="flex-grow-1 p-2 text-decoration-none overflow-hidden <?= $isActive ? 'text-primary fw-semibold' : 'text-body' ?>">
                            <div class="d-flex align-items-center gap-1">
                                <?php if ($file['isToday']): ?>
                                    <span class="dot" title="Today's log"></span>
                                <?php endif ?>
                                <span class="text-truncate small"><?= esc($file['name']) ?></span>
                            </div>
                            <div class="text-body-secondary" style="font-size:.7rem"><?= esc($file['size']) ?></div>
                        </a>
                        <form method="POST"
                              action="<?= site_url($basePath . '/' . $file['name'] . '/delete') ?>"
                              class="lv-delete lv-delete-form d-flex align-items-center ps-1 pe-2 border-start"
                              data-filename="<?= esc($file['name']) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Delete">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </div>
                <?php endforeach ?>
            <?php endif ?>
        </div>

        <div id="lv-bulk-bar" class="border-top p-2 flex-shrink-0 d-none">
            <form id="lv-bulk-form" method="POST" action="<?= site_url($basePath . '/delete-multiple') ?>">
                <?= csrf_field() ?>
                <!-- filenames[] inputs injected by JS before submit -->
            </form>
            <div class="d-flex align-items-center gap-2">
                <small class="text-body-secondary"><span id="lv-sel-count">0</span> selected</small>
                <button type="button" id="lv-bulk-delete-btn" class="btn btn-sm btn-danger ms-auto" disabled>
                    <i class="bi bi-trash3 me-1"></i>Delete
                </button>
            </div>
        </div>

    </aside>

    <!-- ── Main content ─────────────────────────────────────────────────── -->
    <div class="d-flex flex-column flex-grow-1 overflow-hidden">

        <?php if (isset($filename, $entries)): ?>

            <!-- File header -->
            <div class="border-bottom px-3 py-2 d-flex align-items-center gap-2 flex-wrap bg-body-tertiary">
                <span class="fw-semibold text-truncate"><?= esc($filename) ?></span>
                <div class="ms-auto d-flex gap-2 flex-shrink-0">
                    <button class="btn btn-sm btn-outline-secondary" id="btn-refresh" title="Refresh entries">
                        <i class="bi bi-arrow-clockwise" id="refresh-icon"></i>
                    </button>
                    <?php if ($isToday): ?>
                        <button class="btn btn-sm btn-outline-success" id="btn-live">
                            <span class="dot" id="live-dot"></span> Live
                        </button>
                    <?php endif ?>
                    <form method="POST"
                          action="<?= site_url($basePath . '/' . $filename . '/delete') ?>"
                          class="lv-delete-form"
                          data-filename="<?= esc($filename) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash3 me-1"></i>Delete
                        </button>
                    </form>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="border-bottom px-3 py-3 d-flex align-items-center gap-3 flex-wrap">
                <div class="d-flex flex-wrap gap-1" id="lv-levels">
                    <a href="<?= esc(lvUrl($basePath, $filename, 1, [], $searchQuery)) ?>"
                       class="btn btn-sm btn-outline-secondary <?= $allActive ? 'active' : '' ?> lv-level-btn">
                        <i class="bi <?= $allActive ? 'bi-check2-square' : 'bi-square' ?> me-1"></i>All
                        <small class="ms-1 opacity-75"><?= array_sum($levelCounts) ?></small>
                    </a>
                    <?php foreach ($presentLevels as $level):
                        $levelActive = $allActive || in_array($level, $activeLevels, true);
                    ?>
                        <a href="<?= esc(lvToggleLevelUrl($basePath, $filename, $level, $activeLevels, $searchQuery)) ?>"
                           class="btn btn-sm btn-outline-<?= levelColor($level) ?> <?= $levelActive ? 'active' : '' ?> lv-level-btn">
                            <i class="bi <?= $levelActive ? 'bi-check2-square' : 'bi-square' ?> me-1"></i><?= ucfirst($level) ?>
                            <small class="ms-1 opacity-75"><?= $levelCounts[$level] ?></small>
                        </a>
                    <?php endforeach ?>
                </div>
                <input type="search" class="form-control form-control-sm ms-auto" id="lv-search"
                       placeholder="Search (regex supported!)" style="max-width:300px"
                       value="<?= esc($searchQuery) ?>">
            </div>

            <!-- Entries -->
            <div class="flex-grow-1 overflow-y-auto">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="sticky-top bg-body border-bottom">
                        <tr>
                            <th style="width:28px"></th>
                            <th style="width:90px" class="text-center">Level</th>
                            <th style="width:155px">Timestamp</th>
                            <th>Message</th>
                            <th style="width:60px"></th>
                        </tr>
                    </thead>
                    <tbody id="log-tbody">
                        <?php if (empty($entries)): ?>
                            <tr><td colspan="5" class="text-center text-body-secondary py-5">No entries found.</td></tr>
                        <?php endif ?>
                            <?php foreach ($entries as $index => $entry):
                                $rowId      = 'e-' . (($page - 1) * $perPage + $index);
                                $location   = $entry['context']['location'] ?? null;
                                $hasIdeLink = $deeplink['ide'] !== null && $location !== null;
                                $link       = null;
                                $locFile    = null;
                                $locLine    = null;

                                if ($hasIdeLink) {
                                    $colonPos = strrpos($location, ':');
                                    $locFile  = substr($location, 0, $colonPos);
                                    $locLine  = substr($location, $colonPos + 1);
                                    $link     = ideLink($deeplink['ide'], $locFile, $locLine, $deeplink['wslDistro'], $deeplink['serverPath'] ?? null, $deeplink['localPath'] ?? null);
                                }
                            ?>
                                <tr class="lv-row" id="<?= $rowId ?>" data-level="<?= esc($entry['level']) ?>">
                                    <td class="<?= levelTextClass($entry['level']) ?> text-center ps-3 pe-0">
                                        <i class="bi <?= levelIcon($entry['level']) ?>"></i>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= levelBadgeClass($entry['level']) ?>">
                                            <?= esc($entry['level']) ?>
                                        </span>
                                    </td>
                                    <td class="font-monospace text-nowrap small"><?= esc($entry['datetime']) ?></td>
                                    <td>
                                        <div class="d-flex align-items-start gap-2">
                                            <div class="font-monospace text-break lv-msg flex-grow-1"><?= esc($entry['message']) ?></div>
                                            <?php if ($entry['total'] > 1): ?>
                                                <span class="badge text-bg-secondary flex-shrink-0 lv-occurrence"
                                                      title="Occurrence <?= $entry['occurrence'] ?> of <?= $entry['total'] ?> in this file">
                                                    #<?= $entry['occurrence'] ?>
                                                </span>
                                            <?php endif ?>
                                        </div>
                                        <?php if (! empty($entry['context'])): ?>
                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                <?php foreach ($entry['context'] as $key => $val): ?>
                                                    <?php if ($key === 'location'): ?>
                                                        <span class="ctx-pill">
                                                            <span class="opacity-75">location=</span>
                                                            <span class="text-primary">
                                                                <?php if ($link !== null): ?>
                                                                    <a href="<?= esc($link) ?>" class="link-primary text-decoration-none" title="Open in IDE">
                                                                        <?= esc(basename($locFile)) ?>:<?= esc($locLine) ?>
                                                                    </a>
                                                                <?php else: ?>
                                                                    <?= esc($val) ?>
                                                                <?php endif ?>
                                                            </span>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="ctx-pill">
                                                            <span class="opacity-75"><?= esc($key) ?>=</span>
                                                            <span class="text-primary"><?= esc(is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $val) ?></span>
                                                        </span>
                                                    <?php endif ?>
                                                <?php endforeach ?>
                                            </div>
                                        <?php endif ?>
                                        <?php if (! empty($entry['stacktrace'])): ?>
                                            <details class="stacktrace">
                                                <summary><?= count($entry['stacktrace']) ?> stack frame<?= count($entry['stacktrace']) !== 1 ? 's' : '' ?></summary>
                                                <div class="stacktrace-lines">
                                                    <?php foreach ($entry['stacktrace'] as $frame):
                                                        $frameData = parseStackFrame($frame);
                                                        $frameLink = ($deeplink['ide'] && $frameData)
                                                            ? ideLink($deeplink['ide'], $frameData['file'], $frameData['line'], $deeplink['wslDistro'], $deeplink['serverPath'] ?? null, $deeplink['localPath'] ?? null)
                                                            : null;
                                                    ?>
                                                        <div class="stacktrace-line d-flex align-items-baseline gap-1">
                                                            <span class="flex-grow-1"><?= esc($frame) ?></span>
                                                            <?php if ($frameLink): ?>
                                                                <a href="<?= esc($frameLink) ?>" class="text-body-secondary flex-shrink-0 lv-frame-link" title="Open in IDE">
                                                                    <i class="bi bi-code-slash"></i>
                                                                </a>
                                                            <?php endif ?>
                                                        </div>
                                                    <?php endforeach ?>
                                                </div>
                                            </details>
                                        <?php endif ?>
                                    </td>
                                    <td class="lv-row-actions">
                                        <div class="d-flex gap-1 justify-content-end pe-2">
                                            <button class="btn btn-sm btn-link p-0 text-body-secondary lv-share"
                                                    data-anchor="<?= $rowId ?>" title="Copy link to entry">
                                                <i class="bi bi-link-45deg fs-6"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination bar -->
            <div id="lv-pagination-bar">
            <?php if ($totalPages > 1 || $activeLevels !== [] || $searchQuery !== ''): ?>
                <div class="border-top px-3 py-2 d-flex align-items-center gap-2 bg-body-tertiary flex-shrink-0">
                    <small class="text-body-secondary">
                        <?php if ($total === 0): ?>
                            No entries found
                        <?php else: ?>
                            <?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $total)) ?>
                            of <?= number_format($total) ?>
                            <?php if ($activeLevels !== [] || $searchQuery !== ''): ?>
                                <span class="opacity-50">(filtered)</span>
                            <?php endif ?>
                        <?php endif ?>
                    </small>
                    <?php if ($totalPages > 1): ?>
                        <div class="ms-auto d-flex align-items-center gap-1">
                            <a href="<?= esc(lvUrl($basePath, $filename, $page - 1, $activeLevels, $searchQuery)) ?>"
                               class="btn btn-sm btn-outline-secondary lv-page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <small class="text-body-secondary px-1"><?= $page ?> / <?= $totalPages ?></small>
                            <a href="<?= esc(lvUrl($basePath, $filename, $page + 1, $activeLevels, $searchQuery)) ?>"
                               class="btn btn-sm btn-outline-secondary lv-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </div>
                    <?php endif ?>
                </div>
            <?php endif ?>
            </div>

        <?php else: ?>

            <div class="flex-grow-1 d-flex align-items-center justify-content-center text-body-secondary">
                <div class="text-center">
                    <i class="bi bi-arrow-left-circle fs-1 d-block mb-2 opacity-25"></i>
                    <span class="small">Select a log file</span>
                </div>
            </div>

        <?php endif ?>

    </div>

</div>

<!-- ── Delete confirmation modal ────────────────────────────────────────── -->
<div class="modal fade" id="lv-confirm-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body py-3 px-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <i class="bi bi-trash3-fill text-danger fs-4 flex-shrink-0"></i>
                    <div>
                        <div class="fw-semibold">Delete log file?</div>
                        <div class="text-body-secondary small font-monospace" id="lv-confirm-filename"></div>
                    </div>
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" id="lv-confirm-delete">Delete</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Bulk delete confirmation modal ───────────────────────────────────── -->
<div class="modal fade" id="lv-bulk-modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger border-2">
            <div class="modal-header bg-danger text-white py-2">
                <span class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Permanent deletion</span>
            </div>
            <div class="modal-body px-4 pt-4 pb-3">
                <p class="fs-5 fw-semibold mb-1">
                    You are about to permanently delete
                    <span class="text-danger" id="lv-bulk-count-label"></span>.
                </p>
                <p class="text-body-secondary small mb-3">This cannot be undone. The following files will be removed from the server:</p>
                <ul id="lv-bulk-file-list" class="list-unstyled font-monospace small border rounded p-2 mb-0"
                    style="max-height:200px;overflow-y:auto;background:rgba(var(--bs-danger-rgb),.05)"></ul>
            </div>
            <div class="modal-footer pt-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="lv-bulk-confirm-btn" class="btn btn-danger">
                    <i class="bi bi-trash3-fill me-1"></i>Yes, delete all
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.LV_CONFIG = <?= json_encode([
    'streamUrl' => $isToday ? site_url($basePath . '/stream') : null,
    'deeplink'  => $viewerConfig->viewer['deeplink'],
]) ?>;
</script>

<?php include __DIR__ . '/log_viewer_foot.php'; ?>
