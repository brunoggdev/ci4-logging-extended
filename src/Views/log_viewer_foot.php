<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        onerror="this.onerror=null;var s=document.createElement('script');s.src='https://unpkg.com/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js';document.head.appendChild(s)"></script>
<script>
(function () {
    var cfg = window.LV_CONFIG || {};
    var dl = cfg.deeplink || {};

    // ── Bootswatch / theme switcher ───────────────────────────────────────
    var BSW_KEY   = 'lv-bootswatch';
    var THEME_KEY = 'lv-theme';
    var bswLink   = document.getElementById('bs-css');
    var bswSelect = document.getElementById('lv-bsw');
    var themeBtn  = document.getElementById('lv-theme-btn');
    var themeIcon = document.getElementById('lv-theme-icon');

    bswSelect.value = localStorage.getItem(BSW_KEY) || 'flatly';

    function syncIcon() {
        var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        themeIcon.className = dark ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
    }
    syncIcon();

    function switchBsw(theme) {
        var href = 'https://cdn.jsdelivr.net/npm/bootswatch@5.3.8/dist/' + theme + '/bootstrap.min.css';
        var tmp  = document.createElement('link');
        tmp.rel  = 'stylesheet';
        tmp.href = href;
        tmp.onload = function () {
            bswLink.href = href;
            localStorage.setItem(BSW_KEY, theme);
            setTimeout(function () { tmp.remove(); }, 200);
        };
        document.head.appendChild(tmp);
    }

    bswSelect.addEventListener('change', function (e) { switchBsw(e.target.value); });
    themeBtn.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem(THEME_KEY, next);
        syncIcon();
    });

    // ── Sidebar file filter ───────────────────────────────────────────────
    var fileFilter = document.getElementById('lv-file-filter');
    if (fileFilter) {
        fileFilter.addEventListener('input', function () {
            var q = this.value.toLowerCase();
            document.querySelectorAll('.lv-file-item').forEach(function (item) {
                item.style.display = item.dataset.name.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }

    // ── Search ────────────────────────────────────────────────────────────
    // Debounced input → server-side search via lvNavigate (updates URL + all regions).
    // Enter fires immediately without waiting for the debounce.
    var searchInput = document.getElementById('lv-search');
    var searchTimer = null;

    if (searchInput) {
        function doSearch() {
            var url = new URL(location.href);
            var q   = searchInput.value.trim();
            if (q) { url.searchParams.set('q', q); } else { url.searchParams.delete('q'); }
            url.searchParams.delete('page');
            lvNavigate(url.toString());
        }

        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(doSearch, 700);
        });

        searchInput.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            clearTimeout(searchTimer);
            doSearch();
        });
    }

    // ── Partial navigation (swap regions without full reload) ─────────────
    // Swaps #log-tbody, #lv-levels, #lv-pagination-bar and syncs the search
    // input value, then pushes the URL so share links stay accurate.
    // Any in-flight request is aborted when a new navigation starts.
    var _navAbort = null;

    function lvNavigate(url, push) {
        if (_navAbort) { _navAbort.abort(); }
        _navAbort = new AbortController();

        var tbody = document.getElementById('log-tbody');
        if (tbody) tbody.style.opacity = '.4';

        return fetch(url, { signal: _navAbort.signal })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['log-tbody', 'lv-levels', 'lv-pagination-bar'].forEach(function (id) {
                    var fresh = doc.getElementById(id);
                    var cur   = document.getElementById(id);
                    if (fresh && cur) cur.innerHTML = fresh.innerHTML;
                });
                if (push !== false) history.pushState(null, '', url);
            })
            .catch(function (err) {
                if (err.name !== 'AbortError') throw err;
            })
            .finally(function () {
                var t = document.getElementById('log-tbody');
                if (t) t.style.opacity = '';
            });
    }

    // Navigate back/forward without full reload
    window.addEventListener('popstate', function () { lvNavigate(location.href, false); });

    // ── Level filter & pagination buttons (event delegation) ──────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.lv-level-btn, .lv-page-btn');
        if (! btn || btn.classList.contains('disabled')) return;
        e.preventDefault();
        lvNavigate(btn.href);
    });

    // ── Refresh button ────────────────────────────────────────────────────
    var btnRefresh  = document.getElementById('btn-refresh');
    var refreshIcon = document.getElementById('refresh-icon');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function () {
            refreshIcon.className = 'bi bi-arrow-clockwise lv-spin';
            btnRefresh.disabled = true;
            lvNavigate(location.href, false).finally(function () {
                refreshIcon.className = 'bi bi-arrow-clockwise';
                btnRefresh.disabled = false;
            });
        });
    }

    // ── Delete confirmation modal ─────────────────────────────────────────
    var confirmModalEl = document.getElementById('lv-confirm-modal');
    if (confirmModalEl) {
        var confirmModal = new bootstrap.Modal(confirmModalEl);
        var pendingForm  = null;

        document.querySelectorAll('.lv-delete-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                pendingForm = this;
                document.getElementById('lv-confirm-filename').textContent = this.dataset.filename || '';
                confirmModal.show();
            });
        });

        document.getElementById('lv-confirm-delete').addEventListener('click', function () {
            confirmModal.hide();
            if (pendingForm) {
                pendingForm.submit();
                pendingForm = null;
            }
        });
    }

    // ── Bulk file select mode ─────────────────────────────────────────────
    var sidebar        = document.querySelector('.lv-sidebar');
    var selectBtn      = document.getElementById('lv-select-btn');
    var bulkBar        = document.getElementById('lv-bulk-bar');
    var bulkDeleteBtn  = document.getElementById('lv-bulk-delete-btn');
    var bulkCountLabel = document.getElementById('lv-bulk-count-label');
    var bulkFileList   = document.getElementById('lv-bulk-file-list');
    var bulkForm       = document.getElementById('lv-bulk-form');
    var bulkConfirmBtn = document.getElementById('lv-bulk-confirm-btn');
    var bulkModalEl    = document.getElementById('lv-bulk-modal');
    var bulkModal      = bulkModalEl ? new bootstrap.Modal(bulkModalEl) : null;

    function getChecked() {
        return Array.from(document.querySelectorAll('.lv-file-check input:checked'));
    }

    function updateBulkBar() {
        var checked = getChecked();
        var n = checked.length;
        document.getElementById('lv-sel-count').textContent = n;
        bulkDeleteBtn.disabled = n === 0;
    }

    if (selectBtn && sidebar) {
        selectBtn.addEventListener('click', function () {
            var entering = ! sidebar.classList.contains('select-mode');
            sidebar.classList.toggle('select-mode', entering);
            selectBtn.classList.toggle('active', entering);
            bulkBar.classList.toggle('d-none', ! entering);
            if (! entering) {
                document.querySelectorAll('.lv-file-check input').forEach(function (cb) { cb.checked = false; });
                updateBulkBar();
            }
        });

        document.querySelectorAll('.lv-file-check input').forEach(function (cb) {
            cb.addEventListener('change', updateBulkBar);
        });

        if (bulkDeleteBtn && bulkModal) {
            bulkDeleteBtn.addEventListener('click', function () {
                var checked = getChecked();
                var n = checked.length;
                var plural = n === 1 ? '1 log file' : n + ' log files';
                bulkCountLabel.textContent = plural;
                bulkFileList.innerHTML = checked
                    .map(function (cb) {
                        return '<li class="py-1 border-bottom last:border-0 text-body-secondary">'
                            + '<i class="bi bi-file-earmark-text me-1"></i>' + esc(cb.value) + '</li>';
                    })
                    .join('');
                bulkModal.show();
            });
        }

        if (bulkConfirmBtn && bulkForm) {
            bulkConfirmBtn.addEventListener('click', function () {
                var checked = getChecked();
                // Clear any previously injected inputs
                bulkForm.querySelectorAll('input[name="filenames[]"]').forEach(function (el) { el.remove(); });
                checked.forEach(function (cb) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'filenames[]';
                    input.value = cb.value;
                    bulkForm.appendChild(input);
                });
                bulkModal.hide();
                bulkForm.submit();
            });
        }
    }

    // ── Share button (event delegation) ──────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.lv-share');
        if (! btn) return;
        var url  = location.origin + location.pathname + location.search + '#' + btn.dataset.anchor;
        var icon = btn.querySelector('.bi');
        navigator.clipboard.writeText(url).then(function () {
            icon.className = 'bi bi-check2 fs-6';
            setTimeout(function () { icon.className = 'bi bi-link-45deg fs-6'; }, 1500);
        });
    });

    // ── Hash-based entry highlight on load ────────────────────────────────
    if (location.hash) {
        var target = document.querySelector(location.hash);
        if (target) {
            target.scrollIntoView({ block: 'start' });
            target.classList.add('lv-highlight');
            target.addEventListener('animationend', function () {
                target.classList.remove('lv-highlight');
            }, { once: true });
        }
    }

    // ── Live SSE ──────────────────────────────────────────────────────────
    var btnLive = document.getElementById('btn-live');
    if (btnLive && cfg.streamUrl) {
        var liveDot    = document.getElementById('live-dot');
        var tbody      = document.getElementById('log-tbody');
        var source     = null;
        // Sidebar dot for the active file (today's file indicator)
        var sidebarDot = document.querySelector('.lv-file-item.bg-primary-subtle .dot');

        function setLiveState(active) {
            btnLive.classList.toggle('active', active);
            liveDot.classList.toggle('pulse', active);
            if (sidebarDot) sidebarDot.classList.toggle('pulse', active);
        }

        btnLive.addEventListener('click', function () {
            if (source) {
                source.close(); source = null;
                setLiveState(false);
                return;
            }
            source = new EventSource(cfg.streamUrl);
            setLiveState(true);

            source.onmessage = function (e) {
                var entry = JSON.parse(e.data);
                if (! entry.level) return;
                tbody.insertBefore(buildRow(entry), tbody.firstChild);
                searchIndex = null;
            };
            source.onerror = function () {
                source.close(); source = null;
                setLiveState(false);
            };
        });
    }

    // ── Shared helpers ────────────────────────────────────────────────────
    var LEVEL_ICONS = {
        emergency: 'bi-exclamation-octagon-fill', alert: 'bi-exclamation-octagon-fill',
        critical:  'bi-exclamation-diamond-fill',
        error:     'bi-exclamation-circle-fill',
        warning:   'bi-exclamation-triangle-fill',
        notice:    'bi-info-square-fill', info: 'bi-info-circle-fill',
    };

    function levelBadge(level) {
        if (['emergency','alert','critical','error'].indexOf(level) !== -1) return 'text-bg-danger';
        if (level === 'warning') return 'text-bg-warning';
        if (level === 'notice' || level === 'info') return 'text-bg-info';
        return 'text-bg-secondary';
    }

    function levelTextClass(level) {
        if (['emergency','alert','critical','error'].indexOf(level) !== -1) return 'text-danger';
        if (level === 'warning') return 'text-warning';
        if (level === 'notice' || level === 'info') return 'text-info';
        return 'text-secondary';
    }

    function levelIcon(level) {
        return LEVEL_ICONS[level] || 'bi-bug-fill';
    }

    function ideLink(file, line) {
        if (! dl.ide) return null;
        if (dl.serverPath && dl.localPath) {
            file = file.split(dl.serverPath).join(dl.localPath);
        }
        if (dl.ide === 'vscode') {
            return dl.wslDistro
                ? 'vscode://vscode-remote/wsl+' + dl.wslDistro + file + ':' + line
                : 'vscode://file/' + file + ':' + line;
        }
        if (dl.ide === 'phpstorm') return 'phpstorm://open?file=' + encodeURIComponent(file) + '&line=' + line;
        return null;
    }

    function parseStackFrame(frame) {
        var m = frame.match(/#\d+ (\/[^(]+)\((\d+)\)/);
        return m ? { file: m[1], line: m[2] } : null;
    }

    function buildRow(entry) {
        var ctx      = entry.context || {};
        var location = ctx.location  || null;
        var req      = ctx.request   || null;
        var link = null, locFile = null, locLine = null;

        if (location) {
            var colonPos = location.lastIndexOf(':');
            locFile = location.substring(0, colonPos);
            locLine = location.substring(colonPos + 1);
            link    = ideLink(locFile, locLine);
        }

        // ── Inline pills: location + request ─────────────────────────────
        var inlineHtml = '';
        if (location) {
            inlineHtml += link
                ? '<span class="ctx-pill"><span class="opacity-75">location=</span>'
                    + '<span class="text-primary"><a href="' + esc(link) + '" class="link-primary text-decoration-none">'
                    + esc(basename(locFile)) + ':' + esc(locLine) + '</a></span></span>'
                : '<span class="ctx-pill"><span class="opacity-75">location=</span>'
                    + '<span class="text-primary">' + esc(location) + '</span></span>';
        }
        if (req) {
            var method = req.method || '';
            var url    = req.url    || '';
            var path   = url;
            try { path = new URL(url).pathname; } catch (ignore) {}
            inlineHtml += '<span class="ctx-pill" title="' + esc(method + ' ' + url) + '">'
                + '<span class="text-primary fw-semibold">' + esc(method) + '</span>'
                + ' <span class="opacity-75">' + esc(path) + '</span></span>';
        }

        // ── Collapsible context: everything else ──────────────────────────
        var PRIORITY     = ['user', 'session'];
        var ctxOtherKeys = Object.keys(ctx).filter(function (k) { return k !== 'location' && k !== 'request'; });
        var ctxSorted    = PRIORITY.filter(function (k) { return ctxOtherKeys.indexOf(k) !== -1; })
                                   .concat(ctxOtherKeys.filter(function (k) { return PRIORITY.indexOf(k) === -1; }));

        var ctxRowsHtml = '';
        for (var ki = 0; ki < ctxSorted.length; ki++) {
            var key = ctxSorted[ki];
            var val = ctx[key];
            var raw = (val !== null && typeof val === 'object') ? JSON.stringify(val) : val;
            ctxRowsHtml += '<tr><td class="ctx-row-key">' + esc(key) + '</td>'
                + '<td class="ctx-row-val">' + esc(raw) + '</td></tr>';
        }

        var detailsHtml = '';
        if (ctxRowsHtml) {
            var n   = ctxSorted.length;
            var sum = n + ' context key' + (n !== 1 ? 's' : '');
            detailsHtml = '<details class="stacktrace"><summary>' + sum + '</summary>'
                + '<div class="ctx-expand-body"><table>' + ctxRowsHtml + '</table></div></details>';
        }

        // ── Stack trace ───────────────────────────────────────────────────
        var frames = entry.stacktrace || [], traceHtml = '';
        if (frames.length) {
            var tsum       = frames.length + ' stack frame' + (frames.length !== 1 ? 's' : '');
            var frameLines = frames.map(function (f) {
                var fd  = parseStackFrame(f);
                var fl  = fd ? ideLink(fd.file, fd.line) : null;
                var btn = fl
                    ? ' <a href="' + esc(fl) + '" class="text-body-secondary lv-frame-link flex-shrink-0" title="Open in IDE"><i class="bi bi-code-slash"></i></a>'
                    : '';
                return '<div class="stacktrace-line d-flex align-items-baseline gap-1"><span class="flex-grow-1">' + esc(f) + '</span>' + btn + '</div>';
            }).join('');
            traceHtml = '<details class="stacktrace"><summary>' + tsum + '</summary><div class="stacktrace-lines">' + frameLines + '</div></details>';
        }

        var tr           = document.createElement('tr');
        tr.className     = 'lv-row';
        tr.dataset.level = entry.level;
        tr.style.animation = 'lv-fadeIn .3s ease';
        tr.innerHTML =
            '<td class="' + levelTextClass(entry.level) + ' text-center ps-3 pe-0"><i class="bi ' + levelIcon(entry.level) + '"></i></td>' +
            '<td class="text-center"><span class="badge ' + levelBadge(entry.level) + '">' + esc(entry.level) + '</span></td>' +
            '<td class="font-monospace text-nowrap small">' + esc(entry.datetime) + '</td>' +
            '<td><div class="d-flex align-items-start gap-2"><div class="font-monospace text-break lv-msg flex-grow-1">' + esc(entry.message) + '</div></div>' +
            (inlineHtml ? '<div class="d-flex flex-wrap gap-1 mt-1">' + inlineHtml + '</div>' : '') +
            detailsHtml +
            traceHtml + '</td>' +
            '<td class="lv-row-actions"><div class="d-flex gap-1 justify-content-end pe-2"></div></td>';
        return tr;
    }

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function basename(path) { return String(path).split('/').pop(); }

}());
</script>
<style>
@keyframes lv-fadeIn        { from { opacity: 0; background: rgba(var(--bs-success-rgb), .12); } to { opacity: 1; } }
@keyframes lv-spin          { to { transform: rotate(360deg); } }
@keyframes lv-row-highlight { from { background-color: rgba(var(--bs-warning-rgb), .35) !important; } to { background-color: transparent !important; } }
.lv-spin { display: inline-block; animation: lv-spin .6s linear infinite; }
.lv-highlight > td { animation: lv-row-highlight 1.8s ease forwards; }
</style>
</body>
</html>
