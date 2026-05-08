<?php if (!$currentDb): ?>
<div class="error-box">Select a database to search.</div>
<?php return; endif; ?>

<?php $searchTerm = input('q', ''); ?>

<!-- Header -->
<div class="info-header info-header-purple">
    <div class="info-header-left">
        <div class="info-header-icon"><?= icon('search', 24) ?></div>
        <div>
            <h3 class="info-header-title">Search Across Tables</h3>
            <span class="info-header-sub"><?= h($currentDb) ?> — find a value in every table</span>
        </div>
    </div>
</div>

<!-- Search Form -->
<div class="search-across-form">
    <div class="search-across-input-wrap">
        <?= icon('search', 16) ?>
        <input type="text" id="search-across-input" class="search-across-input"
               placeholder="Enter a value to find across all tables…"
               value="<?= h($searchTerm) ?>"
               autofocus>
        <button type="button" class="btn btn-primary" id="search-across-btn">
            <?= icon('search', 13) ?> Search
        </button>
    </div>
    <div class="search-across-hint">
        Searches all VARCHAR, TEXT, CHAR, INT, DECIMAL, DATE, ENUM, and SET columns. Skips BLOB and BINARY.
    </div>
</div>

<!-- Results Container -->
<div id="search-results"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('search-across-input');
    var btn = document.getElementById('search-across-btn');
    var results = document.getElementById('search-results');
    var db = '<?= addslashes($currentDb) ?>';

    function doSearch() {
        var term = input.value.trim();
        if (!term) { input.focus(); return; }

        results.innerHTML = '<div class="search-loading">' +
            '<?= icon("search", 20) ?>' +
            '<div class="search-loading-text">Searching <strong>' + escHtml(term) + '</strong> across all tables in <strong>' + escHtml(db) + '</strong>…</div>' +
            '<div class="search-loading-bar"><div class="search-loading-fill"></div></div>' +
            '</div>';

        fetch('ajax.php?action=search_tables&db=' + encodeURIComponent(db) + '&term=' + encodeURIComponent(term) + '&limit=5')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    results.innerHTML = '<div class="error-box">' + escHtml(data.error) + '</div>';
                    return;
                }
                renderResults(data, term);
            })
            .catch(function(err) {
                results.innerHTML = '<div class="error-box">Network error: ' + escHtml(err.message) + '</div>';
            });
    }

    btn.addEventListener('click', doSearch);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') doSearch();
    });

    // Auto-search if query param present
    if (input.value.trim()) doSearch();

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function highlightTerm(text, term) {
        if (!text || !term) return escHtml(String(text));
        var escaped = escHtml(String(text));
        var termEsc = escHtml(term);
        var re = new RegExp('(' + termEsc.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return escaped.replace(re, '<mark class="search-highlight">$1</mark>');
    }

    function renderResults(data, term) {
        var html = '';

        // Summary bar
        html += '<div class="search-summary">';
        if (data.tables_matched > 0) {
            html += '<span class="search-summary-found"><?= icon("check", 14) ?> Found in <strong>' + data.tables_matched + '</strong> table' + (data.tables_matched !== 1 ? 's' : '') + '</span>';
            html += '<span class="search-summary-detail">' + data.total_matches + '+ matches across ' + data.tables_searched + ' tables searched</span>';
        } else {
            html += '<span class="search-summary-empty"><?= icon("search", 14) ?> No matches found in ' + data.tables_searched + ' tables</span>';
        }
        html += '</div>';

        // Results by table
        if (data.results && data.results.length > 0) {
            data.results.forEach(function(tbl) {
                html += '<div class="search-table-result">';
                html += '<div class="search-table-header">';
                html += '<span class="search-table-name"><?= icon("table", 14) ?> ' + escHtml(tbl.table) + '</span>';
                html += '<span class="search-table-cols">Matched in: ' + tbl.columns.map(function(c) { return '<code>' + escHtml(c) + '</code>'; }).join(', ') + '</span>';
                if (tbl.has_more) {
                    html += '<span class="search-table-more">' + tbl.match_count + '+ rows</span>';
                } else {
                    html += '<span class="search-table-more">' + tbl.match_count + ' row' + (tbl.match_count !== 1 ? 's' : '') + '</span>';
                }
                html += '<a href="?db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(tbl.table) + '&tab=browse&search=' + encodeURIComponent(term) + '" class="btn btn-ghost btn-sm search-table-browse"><?= icon("table", 11) ?> Browse</a>';
                html += '</div>';

                // Data rows
                html += '<div class="table-wrapper"><table class="data-table"><thead><tr>';
                // Get columns from first row
                var cols = tbl.rows.length > 0 ? Object.keys(tbl.rows[0]) : [];
                cols.forEach(function(c) {
                    var isMatch = tbl.columns.indexOf(c) !== -1;
                    html += '<th' + (isMatch ? ' class="search-match-col"' : '') + '>' + escHtml(c) + '</th>';
                });
                html += '</tr></thead><tbody>';

                tbl.rows.forEach(function(row, ri) {
                    html += '<tr class="' + (ri % 2 === 0 ? '' : '') + '">';
                    cols.forEach(function(c) {
                        var val = row[c];
                        var isMatch = tbl.columns.indexOf(c) !== -1;
                        if (val === null) {
                            html += '<td><span class="cell-null">NULL</span></td>';
                        } else {
                            var display = String(val);
                            if (display.length > 80) display = display.substring(0, 80) + '…';
                            if (isMatch) {
                                html += '<td>' + highlightTerm(display, term) + '</td>';
                            } else {
                                html += '<td>' + escHtml(display) + '</td>';
                            }
                        }
                    });
                    html += '</tr>';
                });

                html += '</tbody></table></div>';

                if (tbl.has_more) {
                    html += '<div class="search-table-footer">';
                    html += '<a href="?db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(tbl.table) + '&tab=browse&search=' + encodeURIComponent(term) + '">View all matches in ' + escHtml(tbl.table) + ' →</a>';
                    html += '</div>';
                }

                html += '</div>';
            });
        }

        results.innerHTML = html;
    }
});
</script>
