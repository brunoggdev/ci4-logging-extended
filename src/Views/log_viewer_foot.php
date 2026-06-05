<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        onerror="this.onerror=null;var s=document.createElement('script');s.src='https://unpkg.com/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js';document.head.appendChild(s)"></script>
<script>
(function () {
    var cfg = window.LV_CONFIG || {};

    // ── Deep link settings (per-browser) ──────────────────────────────────
    // serverPath is supplied by the server (auto-detected ROOTPATH or a config
    // override). ide / wslDistro / localPath are per-developer and live in this
    // browser's localStorage, seeded on first run from the app's defaults.
    var DL_KEY     = 'lv-deeplink';
    var PROMPT_KEY = 'lv-deeplink-prompted';
    var dlCfg      = cfg.deeplink || {};
    var serverPath = dlCfg.serverPath || '';
    var deeplink   = loadDeeplink();

    function loadDeeplink() {
        var stored = null;
        try { stored = JSON.parse(localStorage.getItem(DL_KEY)); } catch (e) {}
        var src = (stored && typeof stored === 'object') ? stored : (dlCfg.seed || {});
        return { ide: src.ide || '', wslDistro: src.wslDistro || '', localPath: src.localPath || '' };
    }

    function saveDeeplink(v) {
        deeplink = { ide: v.ide || '', wslDistro: v.wslDistro || '', localPath: normalizeLocalPath(v.localPath || '') };
        localStorage.setItem(DL_KEY, JSON.stringify(deeplink));
    }

    // serverPath (ROOTPATH) ends in a separator and the rewrite is a prefix
    // replace, so localPath must end in one too — otherwise the join concatenates
    // (e.g. /home/youapp/...). Append the matching separator if the user omitted it.
    function normalizeLocalPath(p) {
        p = (p || '').trim();
        if (p === '' || /[\/\\]$/.test(p)) return p;
        return p + (p.indexOf('\\') !== -1 && p.indexOf('/') === -1 ? '\\' : '/');
    }

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

    // ── Settings modal (deep link preferences) ────────────────────────────
    var settingsBtn     = document.getElementById('lv-settings-btn');
    var settingsModalEl = document.getElementById('lv-settings-modal');
    var settingsModal   = settingsModalEl ? new bootstrap.Modal(settingsModalEl) : null;
    var dlIdeInput      = document.getElementById('lv-dl-ide');
    var dlLocalInput    = document.getElementById('lv-dl-local');
    var dlWslInput      = document.getElementById('lv-dl-wsl');

    function openSettings() {
        if (! settingsModal) return;
        dlIdeInput.value   = deeplink.ide || '';
        dlLocalInput.value = deeplink.localPath || '';
        dlWslInput.value   = deeplink.wslDistro || '';
        settingsModal.show();
    }

    if (settingsBtn) settingsBtn.addEventListener('click', openSettings);

    var settingsSave = document.getElementById('lv-settings-save');
    if (settingsSave) {
        settingsSave.addEventListener('click', function () {
            saveDeeplink({
                ide:       dlIdeInput.value,
                localPath: dlLocalInput.value.trim(),
                wslDistro: dlWslInput.value.trim(),
            });
            applyDeeplinks(document);
            if (settingsModal) settingsModal.hide();
        });
    }

    // First click on an IDE link with no local path set opens the settings
    // panel once. We don't block again afterwards, so links that already resolve
    // locally (path matches the server) keep working without configuration.
    document.addEventListener('click', function (e) {
        if (! e.target.closest('.lv-ide-link')) return;
        if (! deeplink.localPath && ! localStorage.getItem(PROMPT_KEY)) {
            localStorage.setItem(PROMPT_KEY, '1');
            e.preventDefault();
            openSettings();
        }
    });

    // ── Sidebar file filter ───────────────────────────────────────────────
    var fileFilter = document.getElementById('lv-file-filter');
    if (fileFilter) {
        fileFilter.addEventListener('input', function () {
            var q = this.value.toLowerCase();
            document.querySelectorAll('.lv-file-item').forEach(function (item) {
                var show = item.dataset.name.toLowerCase().includes(q);
                item.classList.toggle('d-flex', show);
                item.classList.toggle('d-none', ! show);
                if (! show) {
                    var cb = item.querySelector('.lv-file-check input');
                    if (cb) cb.checked = false;
                }
            });
            updateBulkBar();
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
                applyDeeplinks(document.getElementById('log-tbody'));
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

        var selVisibleBtn = document.getElementById('lv-sel-visible-btn');
        if (selVisibleBtn) {
            selVisibleBtn.addEventListener('click', function () {
                var visible = Array.from(document.querySelectorAll('.lv-file-item:not(.d-none) .lv-file-check input'));
                var allChecked = visible.length > 0 && visible.every(function (cb) { return cb.checked; });
                visible.forEach(function (cb) { cb.checked = ! allChecked; });
                updateBulkBar();
            });
        }

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

    // ── Wire deep links for the server-rendered rows ──────────────────────
    applyDeeplinks(document);

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
                var row = buildRow(entry);
                tbody.insertBefore(row, tbody.firstChild);
                applyDeeplinks(row);
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
        if (! deeplink.ide) return null;
        if (serverPath && deeplink.localPath) {
            file = file.split(serverPath).join(deeplink.localPath);
        }
        if (deeplink.ide === 'vscode') {
            return deeplink.wslDistro
                ? 'vscode://vscode-remote/wsl+' + deeplink.wslDistro + file + ':' + line
                : 'vscode://file/' + file + ':' + line;
        }
        if (deeplink.ide === 'phpstorm') return 'phpstorm://open?file=' + encodeURIComponent(file) + '&line=' + line;
        return null;
    }

    // Wire data-file / data-line elements into clickable IDE links. Idempotent —
    // safe to re-run after partial navigation, SSE inserts, or a settings change.
    function applyDeeplinks(root) {
        root = root || document;

        root.querySelectorAll('.lv-loc[data-file]').forEach(function (el) {
            var file = el.dataset.file, line = el.dataset.line;
            var link = ideLink(file, line);
            if (link) {
                el.innerHTML = '<a href="' + esc(link) + '" class="link-primary text-decoration-none lv-ide-link" title="Open in IDE">'
                    + esc(basename(file)) + ':' + esc(line) + '</a>';
            } else {
                el.textContent = file + ':' + line;
            }
        });

        root.querySelectorAll('.stacktrace-line[data-file]').forEach(function (el) {
            var link     = ideLink(el.dataset.file, el.dataset.line);
            var existing = el.querySelector('.lv-frame-link');
            if (link) {
                if (existing) {
                    existing.setAttribute('href', link);
                } else {
                    var a = document.createElement('a');
                    a.href      = link;
                    a.className = 'text-body-secondary flex-shrink-0 lv-frame-link lv-ide-link';
                    a.title     = 'Open in IDE';
                    a.innerHTML = '<i class="bi bi-code-slash"></i>';
                    el.appendChild(a);
                }
            } else if (existing) {
                existing.remove();
            }
        });
    }

    function parseStackFrame(frame) {
        var m = frame.match(/#\d+ (\/[^(]+)\((\d+)\)/);
        return m ? { file: m[1], line: m[2] } : null;
    }

    function buildRow(entry) {
        var ctx      = entry.context || {};
        var location = ctx.location  || null;
        var req      = ctx.request   || null;
        var locFile = null, locLine = null, colonPos = -1;

        if (location) {
            colonPos = location.lastIndexOf(':');
            locFile  = location.substring(0, colonPos);
            locLine  = location.substring(colonPos + 1);
        }

        // ── Inline pills: location + request ─────────────────────────────
        // The href is wired by applyDeeplinks() once the row is in the DOM.
        var inlineHtml = '';
        if (location) {
            var locAttr = colonPos > -1
                ? ' data-file="' + esc(locFile) + '" data-line="' + esc(locLine) + '"'
                : '';
            inlineHtml += '<span class="ctx-pill"><span class="opacity-75">location=</span>'
                + '<span class="text-primary lv-loc"' + locAttr + '>' + esc(location) + '</span></span>';
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
                var fd   = parseStackFrame(f);
                var attr = fd ? ' data-file="' + esc(fd.file) + '" data-line="' + esc(fd.line) + '"' : '';
                return '<div class="stacktrace-line d-flex align-items-baseline gap-1"' + attr + '><span class="flex-grow-1">' + esc(f) + '</span></div>';
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
