<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($filename) ? esc($filename) . ' — ' : '' ?>Log Viewer</title>
    <script>
    (function () {
        var BSW_KEY   = 'lv-bootswatch';
        var THEME_KEY = 'lv-theme';
        var bsw   = localStorage.getItem(BSW_KEY) || 'flatly';
        var theme = localStorage.getItem(THEME_KEY) ||
                    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
        document.write('<link id="bs-css" rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.8/dist/' + bsw + '/bootstrap.min.css">');
    }());
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ── Context pills ─────────────────────────────────────────────────── */
        .ctx-pill {
            display: inline-flex;
            align-items: center;
            gap: .2rem;
            background: rgba(var(--bs-primary-rgb), .07);
            border: 1px solid rgba(var(--bs-primary-rgb), .2);
            border-radius: 4px;
            padding: .1rem .4rem;
            font-size: .75rem;
            font-family: 'SFMono-Regular', Consolas, monospace;
            white-space: nowrap;
        }

        /* ── Stacktrace ────────────────────────────────────────────────────── */
        .stacktrace { margin-top: .375rem; }
        .stacktrace > summary {
            font-size: .75rem;
            color: var(--bs-secondary-color);
            cursor: pointer;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
        }
        .stacktrace > summary:hover { color: var(--bs-body-color); }
        .stacktrace-lines {
            margin-top: .375rem;
            padding: .5rem .75rem;
            background: rgba(var(--bs-secondary-rgb), .07);
            border-left: 2px solid var(--bs-border-color);
            border-radius: 0 .25rem .25rem 0;
        }
        .stacktrace-line {
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-size: .75rem;
            color: var(--bs-secondary-color);
            line-height: 1.7;
            word-break: break-all;
        }

        /* ── Pulse dot ─────────────────────────────────────────────────────── */
        .dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--bs-success);
            display: inline-block;
            vertical-align: middle;
            flex-shrink: 0;
        }
        .dot.pulse { animation: lv-pulse 1.2s infinite; }
        /* White dot when sitting on a coloured button background (active state) */
        .btn.active .dot { background: #fff; }
        @keyframes lv-pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: .3; }
        }

        /* ── Sidebar file items ────────────────────────────────────────────── */
        .lv-file-item .lv-delete { opacity: 0; transition: opacity .15s; }
        .lv-file-item:hover .lv-delete { opacity: 1; }
        .lv-file-item .lv-delete button {
            width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 4px;
            transition: background .15s;
        }
        .lv-file-item .lv-delete button:hover {
            background: rgba(var(--bs-danger-rgb), .12);
        }

        /* ── Row hover actions ─────────────────────────────────────────────── */
        .lv-row .lv-row-actions { opacity: 0; transition: opacity .15s; }
        .lv-row:hover .lv-row-actions { opacity: 1; }

        /* ── Scroll anchor offset (accounts for sticky thead) ──────────────── */
        #log-tbody > tr { scroll-margin-top: 48px; }

        /* ── Stacktrace frame IDE link ──────────────────────────────────────── */
        .lv-frame-link { opacity: 0; font-size: .7rem; transition: opacity .15s; }
        .stacktrace-line:hover .lv-frame-link { opacity: 1; }

        /* ── Sidebar select mode ───────────────────────────────────────────── */
        .lv-file-check { display: none; }
        .lv-sidebar.select-mode .lv-file-check { display: flex !important; }
        .lv-sidebar.select-mode .lv-delete     { display: none !important; }
        #lv-select-btn.active { color: var(--bs-primary); border-color: var(--bs-primary); }
    </style>
</head>
<body>
