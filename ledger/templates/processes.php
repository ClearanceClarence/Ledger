<?php
try {
    $processes = $dbInstance->getProcessList();
    $currentConnId = $dbInstance->getCurrentConnectionId();
} catch (Exception $e) {
    echo '<div class="error-box"><strong>ERROR:</strong> ' . h($e->getMessage()) . '</div>';
    return;
}

// Summary stats
$totalCount  = count($processes);
$activeCount = 0;
$sleepCount  = 0;
$longestRun  = 0;
foreach ($processes as $p) {
    if (strcasecmp($p['command'], 'Sleep') === 0) {
        $sleepCount++;
    } else {
        $activeCount++;
        if ($p['time'] > $longestRun) $longestRun = $p['time'];
    }
}

// Helper: format seconds compactly for the Time column
if (!function_exists('format_process_time')) {
    function format_process_time(int $s): string {
        if ($s < 60)   return $s . 's';
        if ($s < 3600) return floor($s / 60) . 'm ' . ($s % 60) . 's';
        return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
    }
}

// Helper: color for Command badge
if (!function_exists('process_command_color')) {
    function process_command_color(string $cmd): string {
        $c = strtolower($cmd);
        if ($c === 'sleep')      return 'muted';
        if ($c === 'query')      return 'accent';
        if ($c === 'connect')    return 'info';
        if ($c === 'killed')     return 'danger';
        if ($c === 'binlog dump') return 'purple';
        return 'gold';
    }
}
?>

<!-- Header -->
<div class="info-header info-header-red">
    <div class="info-header-left">
        <div class="info-header-icon"><?= icon('activity', 24) ?></div>
        <div>
            <h3 class="info-header-title">Processes</h3>
            <span class="info-header-sub">
                Live MySQL connections &amp; running queries
                <span id="proc-refresh-indicator" style="display:none;margin-left:10px;font-family:var(--font-mono);color:var(--accent);font-size:var(--font-size-xs);">● refreshing</span>
            </span>
        </div>
    </div>
    <div class="info-header-stats">
        <div class="info-stat">
            <span class="info-stat-value accent" id="proc-stat-total"><?= $totalCount ?></span>
            <span class="info-stat-label">Total</span>
        </div>
        <div class="info-stat">
            <span class="info-stat-value gold" id="proc-stat-active"><?= $activeCount ?></span>
            <span class="info-stat-label">Active</span>
        </div>
        <div class="info-stat">
            <span class="info-stat-value info" id="proc-stat-sleep"><?= $sleepCount ?></span>
            <span class="info-stat-label">Sleeping</span>
        </div>
        <div class="info-stat">
            <span class="info-stat-value purple" id="proc-stat-longest"><?= $longestRun > 0 ? format_process_time($longestRun) : '—' ?></span>
            <span class="info-stat-label">Longest run</span>
        </div>
    </div>
</div>

<!-- Toolbar -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
    <div style="position:relative;flex:1;min-width:220px;max-width:400px;">
        <input type="text" id="proc-filter" placeholder="Filter by user, host, database, query…"
               style="width:100%;padding:7px 12px 7px 32px;font-size:var(--font-size-sm);background:var(--bg-input);color:var(--text-primary);border:1px solid var(--border);border-radius:var(--radius-sm);font-family:var(--font-body);">
        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('search', 13) ?></span>
    </div>
    <label style="display:inline-flex;align-items:center;gap:6px;font-size:var(--font-size-sm);color:var(--text-secondary);cursor:pointer;">
        <input type="checkbox" id="proc-hide-sleep" style="margin:0;">
        Hide sleeping
    </label>
    <div style="flex:1;"></div>
    <div style="display:flex;align-items:center;gap:6px;font-size:var(--font-size-xs);color:var(--text-muted);">
        <span>Auto-refresh:</span>
        <select id="proc-refresh-rate" style="padding:4px 8px;background:var(--bg-input);color:var(--text-primary);border:1px solid var(--border);border-radius:var(--radius-sm);font-size:var(--font-size-xs);">
            <option value="0">Off</option>
            <option value="2">2s</option>
            <option value="5">5s</option>
            <option value="10">10s</option>
        </select>
    </div>
    <button type="button" id="proc-refresh-now" class="btn btn-ghost btn-sm" title="Refresh now">
        <?= icon('refresh', 12) ?> Refresh
    </button>
</div>

<!-- Process Table -->
<div class="table-wrapper">
    <table class="data-table" id="proc-table">
        <thead>
            <tr>
                <th style="width:70px;">ID</th>
                <th style="width:160px;">User</th>
                <th style="width:180px;">Host</th>
                <th style="width:120px;">Database</th>
                <th style="width:90px;">Command</th>
                <th style="width:90px;">Time</th>
                <th style="width:140px;">State</th>
                <th>Query</th>
                <th style="width:90px;">Actions</th>
            </tr>
        </thead>
        <tbody id="proc-tbody">
            <!-- Rendered by JS for consistency with auto-refresh -->
        </tbody>
    </table>
    <div id="proc-empty" style="display:none;padding:40px;text-align:center;color:var(--text-muted);font-size:var(--font-size-sm);">
        No matching processes.
    </div>
</div>

<script>
(function() {
    'use strict';

    var initialProcesses = <?= json_encode($processes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var currentConnId   = <?= (int)$currentConnId ?>;
    var isReadOnly      = <?= (isset($auth) && $auth->isReadOnly()) ? 'true' : 'false' ?>;

    var state = {
        processes: initialProcesses,
        filter:    '',
        hideSleep: false,
        refreshMs: 0,
        timerId:   null,
    };

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatTime(seconds) {
        var s = parseInt(seconds, 10) || 0;
        if (s < 60) return s + 's';
        if (s < 3600) return Math.floor(s / 60) + 'm ' + (s % 60) + 's';
        return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
    }

    function commandColor(cmd) {
        var c = (cmd || '').toLowerCase();
        if (c === 'sleep')       return { color: 'var(--text-muted)',     bg: 'rgba(148,163,184,0.08)' };
        if (c === 'query')       return { color: 'var(--accent)',         bg: 'rgba(74,222,128,0.1)' };
        if (c === 'connect')     return { color: 'var(--info)',           bg: 'rgba(96,165,250,0.1)' };
        if (c === 'killed')      return { color: 'var(--danger,#ef4444)', bg: 'rgba(239,68,68,0.1)' };
        if (c === 'binlog dump') return { color: 'var(--purple,#c084fc)', bg: 'rgba(192,132,252,0.1)' };
        return { color: 'var(--warning,#f59e0b)', bg: 'rgba(245,158,11,0.1)' };
    }

    function timeColor(seconds, command) {
        // Only highlight on non-Sleep commands — Sleep connections can be idle for hours and that's fine
        if ((command || '').toLowerCase() === 'sleep') return 'var(--text-muted)';
        if (seconds >= 60)  return 'var(--danger,#ef4444)';
        if (seconds >= 10)  return 'var(--warning,#f59e0b)';
        if (seconds >= 3)   return 'var(--gold,#e8c34a)';
        return 'var(--text-secondary)';
    }

    function renderRows() {
        var q = state.filter.toLowerCase();
        var filtered = state.processes.filter(function(p) {
            if (state.hideSleep && (p.command || '').toLowerCase() === 'sleep') return false;
            if (!q) return true;
            var haystack = [p.user, p.host, p.db, p.command, p.state, p.info, String(p.id)].join(' ').toLowerCase();
            return haystack.indexOf(q) !== -1;
        });

        var tbody = document.getElementById('proc-tbody');
        var empty = document.getElementById('proc-empty');

        if (filtered.length === 0) {
            tbody.innerHTML = '';
            empty.style.display = '';
            return;
        }
        empty.style.display = 'none';

        var html = '';
        filtered.forEach(function(p) {
            var isMe = (p.id === currentConnId);
            var cmdStyle = commandColor(p.command);
            var timeClr = timeColor(p.time, p.command);
            var host = p.host || '—';
            var db   = p.db   || '<span style="color:var(--text-muted);">—</span>';
            var stateTxt = p.state ? escapeHtml(p.state) : '<span style="color:var(--text-muted);">—</span>';
            var info = p.info ? escapeHtml(p.info) : '<span style="color:var(--text-muted);">—</span>';

            html +=
                '<tr data-id="' + p.id + '"' + (isMe ? ' style="background:rgba(74,222,128,0.04);"' : '') + '>' +
                    '<td style="font-family:var(--font-mono);font-weight:600;">' +
                        (isMe ? '<span title="This is your Ledger session" style="color:var(--accent);margin-right:4px;">★</span>' : '') +
                        p.id +
                    '</td>' +
                    '<td style="font-family:var(--font-mono);font-size:var(--font-size-xs);">' + escapeHtml(p.user) + '</td>' +
                    '<td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-secondary);">' + escapeHtml(host) + '</td>' +
                    '<td style="font-family:var(--font-mono);font-size:var(--font-size-xs);">' + db + '</td>' +
                    '<td>' +
                        '<span style="font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700;background:' + cmdStyle.bg + ';color:' + cmdStyle.color + ';font-family:var(--font-mono);">' +
                            escapeHtml((p.command || '').toUpperCase()) +
                        '</span>' +
                    '</td>' +
                    '<td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:' + timeClr + ';font-weight:600;">' + formatTime(p.time) + '</td>' +
                    '<td style="font-size:var(--font-size-xs);color:var(--text-secondary);">' + stateTxt + '</td>' +
                    '<td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-secondary);max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + info + '">' + info + '</td>' +
                    '<td>' +
                        (isReadOnly || isMe
                            ? '<button type="button" class="btn btn-ghost btn-sm" disabled title="' + (isMe ? 'Cannot kill your own session' : 'Read-only mode') + '" style="opacity:0.4;cursor:not-allowed;">' + ' Kill</button>'
                            : '<button type="button" class="btn btn-danger btn-sm proc-kill" data-id="' + p.id + '" data-query="' + escapeHtml((p.info || '').substring(0, 120)) + '">Kill</button>'
                        ) +
                    '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;

        // Wire kill buttons
        tbody.querySelectorAll('.proc-kill').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.dataset.id;
                var query = this.dataset.query;
                var msg = 'Kill process ' + id + '? This will terminate the connection immediately.';
                if (query) msg += '\n\nCurrent query: ' + query;

                Ledger.confirm({
                    title: 'Kill process ' + id,
                    message: msg,
                    confirmText: 'Kill',
                    danger: true,
                }).then(function(ok) {
                    if (!ok) return;
                    var fd = new FormData();
                    fd.append('action', 'kill_process');
                    fd.append('id', id);
                    fd.append('_csrf_token', csrf());
                    fetch('ajax.php', { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.error) {
                                Ledger.alert({ title: 'Could not kill process', message: data.error });
                            } else {
                                Ledger.setStatus('Process ' + id + ' killed.');
                                refreshNow();
                            }
                        });
                });
            });
        });
    }

    function renderStats() {
        var total = state.processes.length;
        var active = 0, sleep = 0, longest = 0;
        state.processes.forEach(function(p) {
            if ((p.command || '').toLowerCase() === 'sleep') {
                sleep++;
            } else {
                active++;
                if (p.time > longest) longest = p.time;
            }
        });
        document.getElementById('proc-stat-total').textContent   = total;
        document.getElementById('proc-stat-active').textContent  = active;
        document.getElementById('proc-stat-sleep').textContent   = sleep;
        document.getElementById('proc-stat-longest').textContent = longest > 0 ? formatTime(longest) : '—';
    }

    function refreshNow() {
        var indicator = document.getElementById('proc-refresh-indicator');
        if (indicator) indicator.style.display = '';
        fetch('ajax.php?action=get_processlist')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (indicator) indicator.style.display = 'none';
                if (data.error) {
                    Ledger.setStatus('Refresh failed: ' + data.error);
                    return;
                }
                state.processes = data.processes || [];
                currentConnId = data.current_id || currentConnId;
                renderStats();
                renderRows();
            })
            .catch(function(err) {
                if (indicator) indicator.style.display = 'none';
                Ledger.setStatus('Refresh failed: ' + err.message);
            });
    }

    function setAutoRefresh(seconds) {
        if (state.timerId) {
            clearInterval(state.timerId);
            state.timerId = null;
        }
        state.refreshMs = seconds * 1000;
        if (state.refreshMs > 0) {
            state.timerId = setInterval(refreshNow, state.refreshMs);
        }
    }

    // Wire toolbar
    document.getElementById('proc-filter').addEventListener('input', function(e) {
        state.filter = e.target.value;
        renderRows();
    });
    document.getElementById('proc-hide-sleep').addEventListener('change', function(e) {
        state.hideSleep = e.target.checked;
        renderRows();
    });
    document.getElementById('proc-refresh-rate').addEventListener('change', function(e) {
        setAutoRefresh(parseInt(e.target.value, 10) || 0);
    });
    document.getElementById('proc-refresh-now').addEventListener('click', refreshNow);

    // Stop timer when navigating away
    window.addEventListener('beforeunload', function() {
        if (state.timerId) clearInterval(state.timerId);
    });

    // Initial render
    renderRows();
})();
</script>
