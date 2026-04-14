(function ($) {
    'use strict';

    // ── Tabs ──────────────────────────────────────────────────────────────────
    $('.et-tab').on('click', function () {
        var tab = $(this).data('tab');
        $('.et-tab').removeClass('active');
        $('.et-tab-panel').removeClass('active');
        $(this).addClass('active');
        $('#et-tab-' + tab).addClass('active');
    });

    // ── Export: checkbox select-all + counter ─────────────────────────────────
    $(document).on('click', '.et-select-all', function () {
        var group   = $(this).data('group');
        var $checks = $('.et-multi-check[data-group="' + group + '"]');
        var allOn   = $checks.length === $checks.filter(':checked').length;
        $checks.prop('checked', !allOn);
        $(this).text(allOn ? 'Select all' : 'Deselect all');
        updateCount();
    });

    $(document).on('change', '.et-multi-check', updateCount);

    function updateCount() {
        var count = $('.et-multi-check:checked').length;
        $('#et-selected-count').text(count + ' selected');
        $('#et-export-multi-btn').prop('disabled', count === 0);
    }

    // ── Export ────────────────────────────────────────────────────────────────
    $('#et-export-multi-btn').on('click', function () {
        var $btn = $(this);

        // Content post IDs (not wsf / gss / loop)
        var ids     = $('.et-multi-check:checked:not(.et-wsf-check):not(.et-gss-check):not(.et-loop-check)').map(function () { return this.value; }).get();
        var wsfIds  = $('.et-wsf-check:checked').map(function () { return this.value; }).get();
        var gssIds  = $('.et-gss-check:checked').map(function () { return this.value; }).get();
        var loopIds = $('.et-loop-check:checked').map(function () { return this.value; }).get();

        setLoading($btn, true);
        hideStatus('#et-multi-export-status');

        var postData = {
            action:             'etch_export_multi',
            nonce:              etchTransfer.nonce,
            ids:                ids.join(','),
            wsf_ids:            wsfIds.join(','),
            gss_ids:            gssIds.join(','),
            loop_ids:           loopIds.join(','),
            include_media:      $('#et-opt-include-media').is(':checked') ? 1 : 0,
            include_dependents: $('#et-opt-include-deps').is(':checked') ? 1 : 0
        };

        $.post(etchTransfer.ajaxUrl, postData)
        .done(function (res) {
            if (res.success) {
                var p        = res.data;
                var now      = new Date();
                var datePart = now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate());
                var filename = 'etch-bundle-' + datePart + '-' + p.item_count + '-items.json';
                downloadJSON(p, filename);
                var msg = 'Bundle downloaded: ' + p.item_count + ' item(s).';
                if (p.errors && p.errors.length) {
                    showStatus('#et-multi-export-status', 'error', msg + ' Warnings: ' + p.errors.join('; '));
                } else {
                    showStatus('#et-multi-export-status', 'success', msg);
                }
            } else {
                showStatus('#et-multi-export-status', 'error', res.data || 'Export failed.');
            }
        })
        .fail(function () { showStatus('#et-multi-export-status', 'error', 'Request failed.'); })
        .always(function () { setLoading($btn, false); $btn.prop('disabled', !$('.et-multi-check:checked').length); });
    });

    // ── Import: file drop / pick ──────────────────────────────────────────────
    var $drop      = $('#et-file-drop');
    var $fileInput = $('#et-import-file');
    var $fileLabel = $('#et-file-label');
    var importFile = null;

    $fileInput.on('change', function () {
        if (this.files[0]) handleFileSelected(this.files[0]);
    });

    $drop.on('dragover dragenter', function (e) {
        e.preventDefault(); $drop.addClass('dragover');
    }).on('dragleave drop', function (e) {
        e.preventDefault(); $drop.removeClass('dragover');
        if (e.type === 'drop' && e.originalEvent.dataTransfer.files[0]) {
            handleFileSelected(e.originalEvent.dataTransfer.files[0]);
        }
    });

    function handleFileSelected(file) {
        if (!file.name.match(/\.json$/i)) {
            showStatus('#et-import-status', 'error', 'Please select a .json file.');
            return;
        }

        // Quick client-side validation first
        var reader = new FileReader();
        reader.onload = function (e) {
            try {
                var data = JSON.parse(e.target.result);
                if (data && data.success && data.data) data = data.data;
                if (!data || !data.etch_export_version) throw new Error('Not a valid Etch export file.');

                importFile = file;
                $drop.addClass('has-file');
                $fileLabel.html('<strong>' + escHtml(file.name) + '</strong>');

                // Auto-check "Import media" if the bundle contains media files
                if (data.media && Object.keys(data.media).length > 0) {
                    $('#et-opt-import-media').prop('checked', true);
                }

                // Show summary immediately from client-parsed data
                renderFileSummary(data);

                // Then fire dry-run to server for full analysis
                runDryRun(file);

            } catch (err) {
                showStatus('#et-import-status', 'error', 'Invalid file: ' + err.message);
                importFile = null;
                $drop.removeClass('has-file');
                $fileLabel.html('Drop file here or <u>browse</u>');
                $('#et-import-preview-wrap').hide();
            }
        };
        reader.readAsText(file);
    }

    function renderFileSummary(data) {
        var isMulti   = data.etch_export_type === 'multi';
        var count     = isMulti ? data.item_count : 1;
        var typeList  = '';
        var hasMedia  = data.media && Object.keys(data.media).length > 0;

        if (isMulti) {
            var typeCounts = {};
            (data.items || []).forEach(function (item) {
                var t = item.source_post_type || 'unknown';
                if (t === 'wp_template')    t = 'Templates';
                else if (t === 'wp_block')  t = item.is_component ? 'Components' : 'Patterns';
                else if (t === 'page')      t = 'Pages';
                else if (t === 'post')      t = 'Posts';
                typeCounts[t] = (typeCounts[t] || 0) + 1;
            });
            if ((data.wsf_forms || []).length)                             typeCounts['WS Forms']          = data.wsf_forms.length;
            if (Object.keys(data.etch_global_stylesheets || {}).length)   typeCounts['Global Stylesheets'] = Object.keys(data.etch_global_stylesheets).length;
            if (Object.keys(data.etch_loops || {}).length)                typeCounts['Loops']              = Object.keys(data.etch_loops).length;
            typeList = Object.keys(typeCounts).map(function (t) { return typeCounts[t] + ' ' + t; }).join(', ');
        } else {
            typeList = (data.source_post_type || 'page') + ': ' + (data.post_title || '');
        }

        var mediaRow = hasMedia ? '<div class="et-summary-row"><span>Media:</span><strong>' + Object.keys(data.media).length + ' files bundled</strong></div>' : '';

        $('#et-import-summary').html(
            '<div class="et-summary-row"><span>From:</span><strong>'     + escHtml(data.source_site  || '') + '</strong></div>' +
            '<div class="et-summary-row"><span>Exported:</span><strong>' + escHtml(data.exported_at  || '') + '</strong></div>' +
            '<div class="et-summary-row"><span>Items:</span><strong>'    + count                            + '</strong></div>' +
            '<div class="et-summary-row"><span>Contents:</span><strong>' + escHtml(typeList)                + '</strong></div>' +
            mediaRow
        );

        $('#et-dry-run-results').html('<p class="et-dry-run-loading">Analysing bundle…</p>');
        $('#et-import-preview-wrap').show();
    }

    // ── Auto dry-run (fires after every file drop) ────────────────────────────
    function runDryRun(file) {
        var fd = new FormData();
        fd.append('action',      'etch_dry_run');
        fd.append('nonce',       etchTransfer.nonce);
        fd.append('import_file', file, file.name);

        $.ajax({ url: etchTransfer.ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false })
        .done(function (res) {
            if (res.success) {
                renderDryRun(res.data);
            } else {
                $('#et-dry-run-results').html('<p class="et-dry-run-error">' + escHtml(res.data || 'Analysis failed.') + '</p>');
            }
        })
        .fail(function () {
            $('#et-dry-run-results').html('<p class="et-dry-run-error">Could not reach server for analysis.</p>');
        });
    }

    function renderDryRun(data) {
        var items      = data.items        || [];
        var styleConf  = data.style_conflicts || [];
        var wsfConf    = data.wsf_conflicts   || [];
        var mediaCount = data.media_count     || 0;

        var html = '<div class="et-dry-run">';
        html += '<h3 class="et-dry-run__title">' + icons.preview + ' Preview: what will happen</h3>';

        if (items.length === 0) {
            html += '<p style="color:#8c8f94;font-size:13px;">No items found in this bundle.</p>';
        } else {
            html += '<table class="et-dry-table"><thead><tr><th>Item</th><th>Type</th><th>Action</th></tr></thead><tbody>';
            items.forEach(function (item) {
                var actionClass = item.action === 'create' ? 'create' : (item.action === 'merge' ? 'update' : 'update');
                html += '<tr><td>' + escHtml(item.title) + '</td>';
                html += '<td><code>' + escHtml(typeLabel(item.type, item)) + '</code></td>';
                html += '<td><span class="et-tag et-tag--' + actionClass + '">' + escHtml(item.action) + '</span></td></tr>';
            });
            html += '</tbody></table>';
        }

        if (styleConf.length > 0) {
            html += '<div class="et-conflict-notice">' + icons.warning + ' <strong>' + styleConf.length + ' style conflict(s) detected.</strong> Use the Style Conflicts dropdown below to choose how to handle them.<ul>';
            styleConf.forEach(function (c) { html += '<li><code>' + escHtml(c.selector || c.id) + '</code></li>'; });
            html += '</ul></div>';
        }

        if (wsfConf.length > 0) {
            html += '<div class="et-conflict-notice">' + icons.warning + ' <strong>' + wsfConf.length + ' WS Form conflict(s): existing forms will be overwritten.</strong><ul>';
            wsfConf.forEach(function (c) { html += '<li>"' + escHtml(c.label) + '" (ID ' + c.dest_id + ')</li>'; });
            html += '</ul></div>';
        }

        if (mediaCount > 0) {
            html += '<div class="et-info-notice">' + icons.image + ' Bundle contains ' + mediaCount + ' media file(s). Enable "Import media" to sideload them.</div>';
        }

        html += '</div>';
        $('#et-dry-run-results').html(html);
    }

    // ── Import ────────────────────────────────────────────────────────────────
    $('#et-import-btn').on('click', function () {
        if (!importFile) return;
        var $btn = $(this);
        setLoading($btn, true);
        hideStatus('#et-import-status');
        $('#et-item-log').hide().empty();

        var fd = new FormData();
        fd.append('action',          'etch_import');
        fd.append('nonce',           etchTransfer.nonce);
        fd.append('import_file',     importFile, importFile.name);
        fd.append('style_conflicts', $('#et-opt-style-conflicts').val());
        fd.append('import_media',    $('#et-opt-import-media').is(':checked') ? 1 : 0);

        $.ajax({ url: etchTransfer.ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false })
        .done(function (res) {
            if (res.success) {
                showStatus('#et-import-status', 'success', res.data.message);
                renderItemLog(res.data.item_log || []);
            } else {
                showStatus('#et-import-status', 'error', res.data || 'Import failed.');
            }
        })
        .fail(function (xhr) {
            var detail = xhr.status + ' ' + xhr.statusText;
            try {
                var parsed = JSON.parse(xhr.responseText);
                detail = parsed.message || parsed.data || detail;
            } catch(e) { detail = xhr.responseText ? xhr.responseText.substring(0, 300) : detail; }
            showStatus('#et-import-status', 'error', 'Import failed: ' + detail);
        })
        .always(function () { setLoading($btn, false); });
    });

    function renderItemLog(log) {
        if (!log || !log.length) return;
        var html = '<div class="et-item-log"><h4>Item results</h4><table class="et-dry-table"><thead><tr><th>Item</th><th>Status</th><th>ID</th></tr></thead><tbody>';
        log.forEach(function (row) {
            var cls = row.status === 'error' ? 'error' : (row.status === 'created' ? 'create' : 'update');
            html += '<tr><td>' + escHtml(row.title) + '</td>';
            html += '<td><span class="et-tag et-tag--' + cls + '">' + escHtml(row.status) + '</span></td>';
            html += '<td>' + (row.dest_id ? '#' + row.dest_id : (row.message ? escHtml(row.message) : '—')) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        $('#et-item-log').html(html).show();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function typeLabel(t, item) {
        if (t === 'wp_block') return item && item.is_component ? 'Component' : 'Pattern';
        var map = { wp_template: 'Template', page: 'Page', post: 'Post', wsf_form: 'WS Form', global_stylesheet: 'Global Stylesheet', loop: 'Loop' };
        return map[t] || t;
    }

    function downloadJSON(data, filename) {
        var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click();
        document.body.removeChild(a); URL.revokeObjectURL(url);
    }

    function showStatus(sel, type, msg) { $(sel).removeClass('success error').addClass(type).text(msg).show(); }
    function hideStatus(sel)            { $(sel).hide().text(''); }
    function setLoading($btn, on)       { $btn.toggleClass('loading', on).prop('disabled', on); }
    function escHtml(str)               { return $('<div>').text(str || '').html(); }
    function pad(n)                     { return n < 10 ? '0' + n : '' + n; }

    var icons = {
        warning: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        preview: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        image:   '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
    };

})(jQuery);
