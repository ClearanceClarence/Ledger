<?php if (!$currentDb): ?>
<div class="error-box">Select a database to view its ER diagram.</div>
<?php return; endif; ?>

<div class="info-header info-header-gold">
    <div class="info-header-left">
        <div class="info-header-icon"><?= icon('share', 24) ?></div>
        <div>
            <h3 class="info-header-title">ER Diagram</h3>
            <span class="info-header-sub"><?= h($currentDb) ?> — entity relationships</span>
        </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <button type="button" class="btn btn-primary btn-sm" id="er-auto-layout"><?= icon('share', 13) ?> Auto Layout</button>
        <button type="button" class="btn btn-ghost btn-sm" id="er-save" title="Save layout"><?= icon('check', 13) ?> Save</button>
        <button type="button" class="btn btn-ghost btn-sm" id="er-reset" title="Delete saved layout and start fresh" style="color:var(--danger);"><?= icon('x', 13) ?> Reset</button>
        <select id="er-line-style" style="padding:4px 8px;font-size:11px;cursor:pointer;background:var(--bg-input);border:1px solid var(--border);color:var(--text-secondary);border-radius:var(--radius-sm);font-family:var(--font-mono);">
            <option value="elbow">Elbow</option>
            <option value="curve">Curved</option>
            <option value="straight">Straight</option>
        </select>
        <span style="width:1px;height:20px;background:var(--border);"></span>
        <button type="button" class="btn btn-ghost btn-sm" id="er-fit"><?= icon('layers', 13) ?> Fit</button>
        <button type="button" class="btn btn-ghost btn-sm" id="er-zoom-in">+</button>
        <button type="button" class="btn btn-ghost btn-sm" id="er-zoom-out">−</button>
        <span class="er-zoom-label" id="er-zoom-label">100%</span>
    </div>
</div>

<div class="er-canvas-wrap" id="er-canvas-wrap">
    <div class="er-loading" id="er-loading"><?= icon('share', 24) ?><div>Loading schema…</div></div>
    <svg id="er-canvas" class="er-canvas"></svg>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var db = '<?= addslashes($currentDb) ?>';
    var wrap = document.getElementById('er-canvas-wrap');
    var svg = document.getElementById('er-canvas');
    var loading = document.getElementById('er-loading');
    var tables = [], relations = [], TB = {};
    var scale = 1, panX = 0, panY = 0;
    var dragging = null, panning = false, panStart = {};
    var lineStyle = 'elbow';

    // Theme colors
    var cs = getComputedStyle(document.documentElement);
    function cv(n, f) { return cs.getPropertyValue(n).trim() || f; }
    var C = {
        bg:      cv('--bg-panel', '#101018'),
        bgAlt:   cv('--bg-panel-alt', '#13131c'),
        border:  cv('--border', '#1c1c28'),
        text:    cv('--text-primary', '#d8d8e4'),
        textDim: cv('--text-muted', '#4e4e5e'),
        textSec: cv('--text-secondary', '#8b8b9a'),
        accent:  cv('--accent', '#4ade80'),
        warning: cv('--warning', '#f0a030'),
        info:    cv('--info', '#6b9fc4'),
        gold:    cv('--gold', '#e8c34a'),
    };
    var LC = C.textSec;

    var COL_H = 24;
    var HDR_H = 36;
    var TBL_W = 240;

    // DATA FETCH
    fetch('ajax.php?action=er_data&db=' + encodeURIComponent(db) + '&_=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { loading.innerHTML = '<div class="error-box">' + data.error + '</div>'; return; }
            tables = data.tables || [];
            relations = data.relations || [];
            loading.style.display = 'none';

            // Try to load saved layout
            loadLayout(function(loaded) {
                if (!loaded) {
                    doLayout();
                }
                render();
                fitToView();
            });
        });

    // SAVE LAYOUT
    var saveTimer = null;
    function saveLayout() {
        // Debounce: wait 500ms after last change before saving
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(doSave, 500);
    }

    function doSave() {
        var positions = {};
        Object.keys(TB).forEach(function(n) {
            positions[n] = { x: TB[n].x, y: TB[n].y };
        });
        var payload = JSON.stringify({
            positions: positions,
            lineStyle: lineStyle,
            scale: scale,
            panX: panX,
            panY: panY,
        });
        var fd = new FormData();
        fd.append('action', 'er_save_layout');
        fd.append('db', db);
        fd.append('layout', payload);
        fd.append('_csrf_token', document.querySelector('meta[name="csrf-token"]').content);
        fetch('ajax.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var btn = document.getElementById('er-save');
                if (data.success) {
                    btn.textContent = '✓ Saved';
                    btn.style.color = 'var(--accent)';
                    setTimeout(function() { btn.innerHTML = '<?= icon('check', 13) ?> Save'; btn.style.color = ''; }, 2000);
                }
            });
    }

    // LOAD LAYOUT
    function loadLayout(callback) {
        fetch('ajax.php?action=er_load_layout&db=' + encodeURIComponent(db) + '&_=' + Date.now())
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.empty || !data.positions) { callback(false); return; }

                // Build table boxes first (need dimensions)
                tables.forEach(function(t) {
                    var h = HDR_H + t.columns.length * COL_H + 8;
                    TB[t.name] = { x: 0, y: 0, w: TBL_W, h: h, table: t };
                });

                // Apply saved positions
                var anyMissing = false;
                Object.keys(TB).forEach(function(n) {
                    if (data.positions[n]) {
                        TB[n].x = data.positions[n].x;
                        TB[n].y = data.positions[n].y;
                    } else {
                        anyMissing = true;
                    }
                });

                // If new tables were added since last save, layout those
                if (anyMissing) {
                    // Position missing tables below existing ones
                    var maxY = 0;
                    Object.keys(TB).forEach(function(n) { if (data.positions[n]) maxY = Math.max(maxY, TB[n].y + TB[n].h); });
                    var col = 0;
                    Object.keys(TB).forEach(function(n) {
                        if (!data.positions[n]) {
                            TB[n].x = col * (TBL_W + 80);
                            TB[n].y = maxY + 60;
                            col++;
                        }
                    });
                }

                // Restore line style
                if (data.lineStyle) {
                    lineStyle = data.lineStyle;
                    document.getElementById('er-line-style').value = lineStyle;
                }

                // Restore view
                if (data.scale) scale = data.scale;
                if (data.panX !== undefined) panX = data.panX;
                if (data.panY !== undefined) panY = data.panY;

                callback(true);
            })
            .catch(function() { callback(false); });
    }

    document.getElementById('er-save').addEventListener('click', saveLayout);

    document.getElementById('er-reset').addEventListener('click', function() {
        Ledger.confirm({
            title: 'Reset ER diagram?',
            message: 'Delete the saved ER diagram layout for "' + db + '" and rebuild from scratch? This will reset all table positions and the line style. The schema itself is not affected.',
            confirmText: 'Reset',
            cancelText: 'Cancel',
            danger: true,
        }).then(function(ok) {
            if (!ok) return;
            var fd = new FormData();
            fd.append('action', 'er_reset_layout');
            fd.append('db', db);
            fd.append('_csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            fetch('ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) { Ledger.alert({ title: 'Reset failed', message: data.error }); return; }
                    // Rebuild from fresh schema with auto-layout
                    TB = {};
                    lineStyle = 'elbow';
                    scale = 1; panX = 0; panY = 0;
                    document.getElementById('er-line-style').value = 'elbow';
                    doLayout();
                    render();
                    fitToView();
                    var btn = document.getElementById('er-reset');
                    var original = btn.innerHTML;
                    btn.textContent = '✓ Reset';
                    setTimeout(function() { btn.innerHTML = original; }, 2000);
                })
                .catch(function() { Ledger.alert({ title: 'Reset failed', message: 'Network error.' }); });
        });
    });

    // LAYOUT
    function doLayout() {
        if (!tables.length) return;
        TB = {};

        // Sort: most connected first
        var adj = {};
        tables.forEach(function(t) { adj[t.name] = new Set(); });
        relations.forEach(function(r) {
            if (adj[r.from_table]) adj[r.from_table].add(r.to_table);
            if (adj[r.to_table]) adj[r.to_table].add(r.from_table);
        });
        var sorted = tables.slice().sort(function(a, b) {
            return (adj[b.name] || new Set()).size - (adj[a.name] || new Set()).size;
        });

        // Grid placement
        var cols = Math.max(3, Math.round(Math.sqrt(tables.length * 1.3)));
        var GX = TBL_W + 120;
        var GY = 320;
        sorted.forEach(function(t, i) {
            var h = HDR_H + t.columns.length * COL_H + 8;
            TB[t.name] = {
                x: (i % cols) * GX,
                y: Math.floor(i / cols) * GY,
                w: TBL_W, h: h, table: t
            };
        });

        // Force-directed refinement
        if (relations.length > 0) {
            var names = Object.keys(TB);
            var vel = {};
            names.forEach(function(n) { vel[n] = { x: 0, y: 0 }; });

            for (var iter = 0; iter < 400; iter++) {
                var temp = Math.max(0.02, 1 - iter / 400);
                var cx = 0, cy = 0;
                names.forEach(function(n) { cx += TB[n].x + TB[n].w/2; cy += TB[n].y + TB[n].h/2; });
                cx /= names.length; cy /= names.length;

                var fx = {}, fy = {};
                names.forEach(function(n) { fx[n] = 0; fy[n] = 0; });

                // Repulsion
                for (var i = 0; i < names.length; i++) {
                    for (var j = i + 1; j < names.length; j++) {
                        var a = TB[names[i]], b = TB[names[j]];
                        var dx = (a.x + a.w/2) - (b.x + b.w/2);
                        var dy = (a.y + a.h/2) - (b.y + b.h/2);
                        var dist = Math.sqrt(dx*dx + dy*dy);
                        if (dist < 80) dist = 80;
                        var f = 200000 / (dist * dist);
                        fx[names[i]] += dx/dist * f; fy[names[i]] += dy/dist * f;
                        fx[names[j]] -= dx/dist * f; fy[names[j]] -= dy/dist * f;
                    }
                }

                // Attraction toward ideal distance
                relations.forEach(function(r) {
                    var a = TB[r.from_table], b = TB[r.to_table];
                    if (!a || !b) return;
                    var dx = (b.x + b.w/2) - (a.x + a.w/2);
                    var dy = (b.y + b.h/2) - (a.y + a.h/2);
                    var dist = Math.sqrt(dx*dx + dy*dy);
                    if (dist < 1) return;
                    var f = (dist - 400) * 0.002;
                    fx[r.from_table] += dx/dist * f; fy[r.from_table] += dy/dist * f;
                    fx[r.to_table]  -= dx/dist * f; fy[r.to_table]  -= dy/dist * f;
                });

                // Gravity
                names.forEach(function(n) {
                    fx[n] += (cx - TB[n].x - TB[n].w/2) * 0.003;
                    fy[n] += (cy - TB[n].y - TB[n].h/2) * 0.003;
                    vel[n].x = (vel[n].x + fx[n]) * 0.78 * temp;
                    vel[n].y = (vel[n].y + fy[n]) * 0.78 * temp;
                    var mv = 40 * temp;
                    vel[n].x = Math.max(-mv, Math.min(mv, vel[n].x));
                    vel[n].y = Math.max(-mv, Math.min(mv, vel[n].y));
                    TB[n].x += vel[n].x;
                    TB[n].y += vel[n].y;
                });
            }
        }

        // Overlap resolution
        var names = Object.keys(TB);
        for (var p = 0; p < 30; p++) {
            var moved = false;
            for (var i = 0; i < names.length; i++) {
                for (var j = i + 1; j < names.length; j++) {
                    var a = TB[names[i]], b = TB[names[j]];
                    var ox = (a.w/2 + b.w/2 + 60) - Math.abs((a.x + a.w/2) - (b.x + b.w/2));
                    var oy = (a.h/2 + b.h/2 + 50) - Math.abs((a.y + a.h/2) - (b.y + b.h/2));
                    if (ox > 0 && oy > 0) {
                        if (ox < oy) {
                            var px = ox/2 + 12;
                            if (a.x < b.x) { a.x -= px; b.x += px; } else { a.x += px; b.x -= px; }
                        } else {
                            var py = oy/2 + 12;
                            if (a.y < b.y) { a.y -= py; b.y += py; } else { a.y += py; b.y -= py; }
                        }
                        moved = true;
                    }
                }
            }
            if (!moved) break;
        }
        names.forEach(function(n) { TB[n].x = Math.round(TB[n].x); TB[n].y = Math.round(TB[n].y); });
    }

    // SVG HELPER
    function svgEl(tag, attrs) {
        var el = document.createElementNS('http://www.w3.org/2000/svg', tag);
        for (var k in attrs) el.setAttribute(k, attrs[k]);
        return el;
    }

    // PICK SIDES
    // Given two table boxes and column Y positions, pick which edges to connect
    function pickSides(from, to, fy, ty) {
        var fCx = from.x + from.w / 2;
        var tCx = to.x + to.w / 2;
        var fR = from.x + from.w;
        var tR = to.x + to.w;

        // Simple rule: connect from nearest horizontal edges
        // If from is left of to → from.right → to.left
        // If from is right of to → from.left → to.right
        // If overlapping horizontally → use the side with more clearance
        var rightToLeft = tCx - fCx;  // positive = to is right of from

        if (rightToLeft > 0) {
            return { x1: fR, y1: fy, x2: to.x, y2: ty, s1: 'right', s2: 'left' };
        } else {
            return { x1: from.x, y1: fy, x2: tR, y2: ty, s1: 'left', s2: 'right' };
        }
    }

    // BUILD PATH
    function buildPath(x1, y1, x2, y2, s1, s2) {
        if (lineStyle === 'straight') {
            return 'M' + x1 + ',' + y1 + ' L' + x2 + ',' + y2;
        }

        var d1 = (s1 === 'right') ? 1 : -1;
        var d2 = (s2 === 'right') ? 1 : -1;

        if (lineStyle === 'elbow') {
            var midX = (x1 + x2) / 2;
            return 'M' + x1 + ',' + y1
                + ' H' + midX
                + ' V' + y2
                + ' H' + x2;
        }

        // Curve (default)
        var dx = Math.abs(x2 - x1);
        var tension = Math.max(40, Math.min(160, dx * 0.4));
        return 'M' + x1 + ',' + y1
            + ' C' + (x1 + tension * d1) + ',' + y1
            + ' ' + (x2 + tension * d2) + ',' + y2
            + ' ' + x2 + ',' + y2;
    }

    // CROW'S FOOT (many side)
    function drawMany(g, x, y, side) {
        var d = (side === 'right') ? -1 : 1;  // points away from table
        g.appendChild(svgEl('line', { x1: x, y1: y, x2: x + 10*d, y2: y - 5, stroke: LC, 'stroke-width': '1.2', class: 'er-ep' }));
        g.appendChild(svgEl('line', { x1: x, y1: y, x2: x + 10*d, y2: y + 5, stroke: LC, 'stroke-width': '1.2', class: 'er-ep' }));
        g.appendChild(svgEl('line', { x1: x + 10*d, y1: y - 6, x2: x + 10*d, y2: y + 6, stroke: LC, 'stroke-width': '1.2', class: 'er-ep' }));
    }

    // ONE BAR (one side)
    function drawOne(g, x, y, side) {
        var d = (side === 'right') ? -1 : 1;
        g.appendChild(svgEl('line', { x1: x + 3*d, y1: y - 6, x2: x + 3*d, y2: y + 6, stroke: LC, 'stroke-width': '1.2', class: 'er-ep' }));
        g.appendChild(svgEl('line', { x1: x + 7*d, y1: y - 6, x2: x + 7*d, y2: y + 6, stroke: LC, 'stroke-width': '1.2', class: 'er-ep' }));
    }

    // RENDER
    function render() {
        svg.innerHTML = '';

        var defs = svgEl('defs', {});
        var fl = svgEl('filter', { id: 'ts', x: '-2%', y: '-1%', width: '106%', height: '108%' });
        fl.appendChild(svgEl('feDropShadow', { dx: '0', dy: '1', stdDeviation: '2', 'flood-color': 'rgba(0,0,0,0.18)', 'flood-opacity': '1' }));
        defs.appendChild(fl);
        svg.appendChild(defs);

        var g = svgEl('g', { id: 'er-group', transform: 'translate(' + panX + ',' + panY + ') scale(' + scale + ')' });

        // PASS 1: Lines (drawn first = behind tables)
        var relG = svgEl('g', { class: 'er-relations' });

        relations.forEach(function(rel) {
            var from = TB[rel.from_table], to = TB[rel.to_table];
            if (!from || !to) return;

            // Find column indices
            var fi = 0, ti = 0;
            from.table.columns.forEach(function(c, i) { if (c.name === rel.from_col) fi = i; });
            to.table.columns.forEach(function(c, i) { if (c.name === rel.to_col) ti = i; });

            var fy = from.y + HDR_H + fi * COL_H + COL_H / 2;
            var ty = to.y + HDR_H + ti * COL_H + COL_H / 2;

            var rg = svgEl('g', { class: 'er-rel-line', 'data-from': rel.from_table, 'data-to': rel.to_table });

            if (rel.from_table === rel.to_table) {
                // Self-reference
                var rx = from.x + from.w;
                var lx = rx + 60;
                rg.appendChild(svgEl('path', {
                    d: 'M' + rx + ',' + fy + ' C' + lx + ',' + fy + ' ' + lx + ',' + ty + ' ' + rx + ',' + ty,
                    stroke: LC, 'stroke-width': '1.2', fill: 'none', opacity: '0.6', 'stroke-linecap': 'round'
                }));
                drawMany(rg, rx, fy, 'right');
                drawOne(rg, rx, ty, 'right');
            } else {
                var conn = pickSides(from, to, fy, ty);
                var d = buildPath(conn.x1, conn.y1, conn.x2, conn.y2, conn.s1, conn.s2);

                rg.appendChild(svgEl('path', {
                    d: d, stroke: LC, 'stroke-width': '1.2', fill: 'none',
                    opacity: '0.6', 'stroke-linecap': 'round', 'stroke-linejoin': 'round'
                }));

                drawMany(rg, conn.x1, conn.y1, conn.s1);
                drawOne(rg, conn.x2, conn.y2, conn.s2);
            }

            relG.appendChild(rg);
        });
        g.appendChild(relG);

        // PASS 2: Tables (drawn second = on top of lines)
        Object.keys(TB).forEach(function(name) {
            var box = TB[name], t = box.table;
            var tg = svgEl('g', { class: 'er-table', 'data-table': name, transform: 'translate(' + box.x + ',' + box.y + ')' });

            // Solid background (hides lines behind)
            tg.appendChild(svgEl('rect', { x: 0, y: 0, width: box.w, height: box.h, rx: 4, fill: C.bg, stroke: C.border, 'stroke-width': '1', filter: 'url(#ts)' }));

            // Header with warm tint
            tg.appendChild(svgEl('rect', { x: 0, y: 0, width: box.w, height: HDR_H, rx: 4, fill: C.warning, opacity: '0.12' }));
            tg.appendChild(svgEl('rect', { x: 0, y: HDR_H - 4, width: box.w, height: 4, fill: C.warning, opacity: '0.12' }));
            tg.appendChild(svgEl('rect', { x: 0, y: 0, width: 3, height: HDR_H, fill: C.warning, opacity: '0.5' }));
            tg.appendChild(svgEl('line', { x1: 0, y1: HDR_H, x2: box.w, y2: HDR_H, stroke: C.warning, opacity: '0.25', 'stroke-width': '1' }));

            // Table name
            var nm = svgEl('text', { x: 12, y: HDR_H/2 + 5, 'font-size': '12', 'font-weight': '700', fill: C.text, 'font-family': 'var(--font-mono)', style: 'cursor:move' });
            nm.textContent = name.length > 26 ? name.substring(0, 24) + '…' : name;
            tg.appendChild(nm);

            // Row count
            var bd = svgEl('text', { x: box.w - 8, y: HDR_H/2 + 4, 'text-anchor': 'end', 'font-size': '9', fill: C.textDim, 'font-family': 'var(--font-mono)' });
            bd.textContent = t.rows.toLocaleString();
            tg.appendChild(bd);

            // Columns
            t.columns.forEach(function(col, ci) {
                var cy = HDR_H + ci * COL_H;
                var isPK = col.key === 'PRI';
                var isFK = relations.some(function(r) { return r.from_table === name && r.from_col === col.name; });
                var isNull = col.null === 'YES';
                var iy = cy + COL_H/2 + 4;

                // Alternating row
                if (ci % 2 === 0) {
                    tg.appendChild(svgEl('rect', { x: 1, y: cy, width: box.w - 2, height: COL_H, fill: 'rgba(255,255,255,0.012)' }));
                }

                // Key icon
                if (isPK) {
                    var k = svgEl('text', { x: 10, y: iy, 'font-size': '11', fill: C.gold, 'font-family': 'var(--font-mono)', 'font-weight': '700' });
                    k.textContent = '⚷'; tg.appendChild(k);
                } else if (isFK) {
                    var fk = svgEl('text', { x: 10, y: iy, 'font-size': '9', fill: C.info, 'font-family': 'var(--font-mono)', 'font-weight': '700' });
                    fk.textContent = 'FK'; tg.appendChild(fk);
                }

                // Column name
                var cn = svgEl('text', {
                    x: (isPK || isFK) ? 30 : 12, y: iy,
                    'font-size': '11', fill: isPK ? C.text : C.textSec,
                    'font-family': 'var(--font-mono)',
                    'font-weight': isPK ? '700' : '400'
                });
                cn.textContent = col.name.length > 16 ? col.name.substring(0, 14) + '…' : col.name;
                tg.appendChild(cn);

                // Type
                var typeStr = col.type;
                if (typeStr.length > 16) typeStr = typeStr.substring(0, 14) + '…';
                var tt = svgEl('text', {
                    x: box.w - (isNull ? 22 : 8), y: iy,
                    'text-anchor': 'end', 'font-size': '9', fill: C.textDim, 'font-family': 'var(--font-mono)'
                });
                tt.textContent = typeStr; tg.appendChild(tt);

                // Nullable N
                if (isNull) {
                    var nb = svgEl('text', { x: box.w - 6, y: iy, 'text-anchor': 'end', 'font-size': '10', fill: C.warning, 'font-weight': '700', 'font-family': 'var(--font-mono)' });
                    nb.textContent = 'N'; tg.appendChild(nb);
                }

                // Separator
                if (ci < t.columns.length - 1) {
                    tg.appendChild(svgEl('line', { x1: 0, y1: cy + COL_H, x2: box.w, y2: cy + COL_H, stroke: C.border, 'stroke-width': '0.5', opacity: '0.35' }));
                }
            });

            g.appendChild(tg);
        });

        svg.appendChild(g);
        updateCanvasSize();
    }

    // NAVIGATION
    function updateCanvasSize() {
        svg.setAttribute('width', wrap.clientWidth);
        svg.setAttribute('height', wrap.clientHeight);
    }

    function updateTransform() {
        var g = document.getElementById('er-group');
        if (g) g.setAttribute('transform', 'translate(' + panX + ',' + panY + ') scale(' + scale + ')');
        document.getElementById('er-zoom-label').textContent = Math.round(scale * 100) + '%';
    }

    function fitToView() {
        var keys = Object.keys(TB);
        if (!keys.length) return;
        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        keys.forEach(function(k) {
            var b = TB[k];
            minX = Math.min(minX, b.x); minY = Math.min(minY, b.y);
            maxX = Math.max(maxX, b.x + b.w); maxY = Math.max(maxY, b.y + b.h);
        });
        var cw = maxX - minX + 120, ch = maxY - minY + 120;
        var ww = wrap.clientWidth, wh = wrap.clientHeight;
        scale = Math.min(1.5, Math.min(ww / cw, wh / ch));
        scale = Math.max(0.05, scale);
        panX = (ww - cw * scale) / 2 - minX * scale + 60 * scale;
        panY = (wh - ch * scale) / 2 - minY * scale + 60 * scale;
        updateTransform();
    }

    function svgPoint(e) {
        var r = svg.getBoundingClientRect();
        return { x: (e.clientX - r.left - panX) / scale, y: (e.clientY - r.top - panY) / scale };
    }

    // DRAG & PAN
    svg.addEventListener('mousedown', function(e) {
        var tEl = e.target.closest('.er-table');
        if (tEl) {
            var n = tEl.dataset.table, b = TB[n];
            if (!b) return;
            var p = svgPoint(e);
            dragging = { name: n, ox: p.x - b.x, oy: p.y - b.y };
            e.preventDefault();
            return;
        }
        panning = true;
        panStart = { x: e.clientX, y: e.clientY, px: panX, py: panY };
        svg.style.cursor = 'grabbing';
        e.preventDefault();
    });

    svg.addEventListener('mousemove', function(e) {
        if (dragging) {
            var p = svgPoint(e);
            TB[dragging.name].x = Math.round(p.x - dragging.ox);
            TB[dragging.name].y = Math.round(p.y - dragging.oy);
            render();
            updateTransform();
        } else if (panning) {
            panX = panStart.px + (e.clientX - panStart.x);
            panY = panStart.py + (e.clientY - panStart.y);
            updateTransform();
        }
    });

    document.addEventListener('mouseup', function() {
        if (dragging) { saveLayout(); }
        dragging = null; panning = false; svg.style.cursor = '';
    });

    // ZOOM
    wrap.addEventListener('wheel', function(e) {
        e.preventDefault();
        var d = e.deltaY > 0 ? 0.9 : 1.1;
        var r = svg.getBoundingClientRect();
        var mx = e.clientX - r.left, my = e.clientY - r.top;
        var ns = Math.max(0.05, Math.min(3, scale * d));
        panX = mx - (mx - panX) * (ns / scale);
        panY = my - (my - panY) * (ns / scale);
        scale = ns;
        updateTransform();
    }, { passive: false });

    document.getElementById('er-zoom-in').addEventListener('click', function() { scale = Math.min(3, scale * 1.2); updateTransform(); });
    document.getElementById('er-zoom-out').addEventListener('click', function() { scale = Math.max(0.05, scale / 1.2); updateTransform(); });
    document.getElementById('er-fit').addEventListener('click', fitToView);
    document.getElementById('er-auto-layout').addEventListener('click', function() { doLayout(); render(); fitToView(); saveLayout(); });
    document.getElementById('er-line-style').addEventListener('change', function() { lineStyle = this.value; render(); saveLayout(); });
    window.addEventListener('resize', updateCanvasSize);

    // DOUBLE-CLICK
    svg.addEventListener('dblclick', function(e) {
        var tEl = e.target.closest('.er-table');
        if (tEl) window.location.href = '?db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(tEl.dataset.table) + '&tab=structure';
    });

    // RIGHT-CLICK CONTEXT MENU
    var ctxMenu = null;
    function removeCtx() { if (ctxMenu) { ctxMenu.remove(); ctxMenu = null; } }
    document.addEventListener('click', removeCtx);

    svg.addEventListener('contextmenu', function(e) {
        var tEl = e.target.closest('.er-table');
        if (!tEl) return;
        e.preventDefault();
        removeCtx();
        var tName = tEl.dataset.table;

        var m = document.createElement('div');
        m.style.cssText = 'position:fixed;z-index:9999;min-width:180px;background:var(--bg-panel);border:1px solid var(--border);border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,0.4);padding:4px 0;font-family:var(--font-body);font-size:13px;';

        var items = [
            { icon: '📊', label: 'Browse Data', href: '?db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(tName) + '&tab=browse' },
            { icon: '🔧', label: 'Structure', href: '?db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(tName) + '&tab=structure' },
            { icon: '⌨️', label: 'SQL Editor', href: '?db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(tName) + '&tab=sql' },
            { icon: '💾', label: 'Export SQL', href: '?db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(tName) + '&action=export_sql' },
        ];

        m.innerHTML = '<div style="padding:6px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;font-family:var(--font-mono);">' + tName.substring(0, 28) + '</div><div style="height:1px;background:var(--border);margin:2px 0;"></div>';
        items.forEach(function(it) {
            var a = document.createElement('a');
            a.href = it.href;
            a.style.cssText = 'display:flex;align-items:center;gap:8px;padding:7px 14px;color:var(--text-secondary);text-decoration:none;cursor:pointer;';
            a.innerHTML = '<span style="font-size:14px;">' + it.icon + '</span> ' + it.label;
            a.onmouseenter = function() { this.style.background = 'var(--bg-hover)'; this.style.color = 'var(--text-primary)'; };
            a.onmouseleave = function() { this.style.background = ''; this.style.color = 'var(--text-secondary)'; };
            m.appendChild(a);
        });

        m.style.left = e.clientX + 'px';
        m.style.top = e.clientY + 'px';
        document.body.appendChild(m);
        var rect = m.getBoundingClientRect();
        if (rect.right > window.innerWidth) m.style.left = (e.clientX - rect.width) + 'px';
        if (rect.bottom > window.innerHeight) m.style.top = (e.clientY - rect.height) + 'px';
        ctxMenu = m;
    });

    // HOVER HIGHLIGHT
    svg.addEventListener('mouseover', function(e) {
        var tEl = e.target.closest('.er-table');
        if (!tEl) return;
        var n = tEl.dataset.table;

        svg.querySelectorAll('.er-rel-line').forEach(function(l) {
            var match = l.dataset.from === n || l.dataset.to === n;
            l.querySelectorAll('path').forEach(function(p) {
                p.setAttribute('opacity', match ? '1' : '0.08');
                p.setAttribute('stroke-width', match ? '2.5' : '1.2');
                if (match) p.setAttribute('stroke', C.warning);
            });
            l.querySelectorAll('.er-ep').forEach(function(ep) {
                ep.setAttribute('opacity', match ? '1' : '0.08');
                if (match) ep.setAttribute('stroke', C.warning);
            });
        });

        svg.querySelectorAll('.er-table').forEach(function(t2) {
            var tn = t2.dataset.table;
            if (tn === n) return;
            var related = relations.some(function(r) {
                return (r.from_table === n && r.to_table === tn) || (r.to_table === n && r.from_table === tn);
            });
            t2.style.opacity = related ? '1' : '0.3';
        });
    });

    svg.addEventListener('mouseout', function(e) {
        var tEl = e.target.closest('.er-table');
        if (!tEl) return;

        svg.querySelectorAll('.er-rel-line').forEach(function(l) {
            l.querySelectorAll('path').forEach(function(p) {
                p.setAttribute('opacity', '0.6');
                p.setAttribute('stroke-width', '1.2');
                p.setAttribute('stroke', LC);
            });
            l.querySelectorAll('.er-ep').forEach(function(ep) {
                ep.setAttribute('opacity', '0.6');
                ep.setAttribute('stroke', LC);
            });
        });

        svg.querySelectorAll('.er-table').forEach(function(t2) { t2.style.opacity = '1'; });
    });
});
</script>
