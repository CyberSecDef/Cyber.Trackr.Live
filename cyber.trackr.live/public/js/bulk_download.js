/* ============================================================
   Bulk STIG/SCAP download — selection state, plan request, and
   sequential streamed chunk downloads.

   Loaded on /stig (inline collapsed panel, lazy-init on first
   open) and /stig/bulk (standalone, init on DOMContentLoaded).
   Depends on jQuery + DataTables + Scroller plugin (bundled
   site-wide in datatables.min.js).

   Wire format:
     POST /stig/bulk-download/plan
       body: { selections: [ {kind:"xml"|"zip", id:"<title>__V#R#"} … ] }
       → { chunks:[{index,of,files,bytes}], total_files, total_bytes }
     POST /stig/bulk-download/chunk
       body: { files:[…], index, of }
       → streamed application/zip
   ============================================================ */
(function () {
    "use strict";

    const PLAN_URL  = '/stig/bulk-download/plan';
    const CHUNK_URL = '/stig/bulk-download/chunk';

    function fmtBytes(n) {
        if (n >= (1 << 30)) return (n / (1 << 30)).toFixed(2) + ' GB';
        if (n >= (1 << 20)) return (n / (1 << 20)).toFixed(1) + ' MB';
        if (n >= (1 << 10)) return (n / (1 << 10)).toFixed(0) + ' KB';
        return n + ' B';
    }

    function init(root) {
        if (!root || root.dataset.bulkInitialized === '1') return;
        root.dataset.bulkInitialized = '1';

        const $        = window.jQuery;
        const tableEl  = root.querySelector('[data-bulk-table]');
        const bar      = root.querySelector('[data-bulk-bar]');
        const elFiles  = root.querySelector('[data-bulk-count-files]');
        const elBytes  = root.querySelector('[data-bulk-count-bytes]');
        const elChunks = root.querySelector('[data-bulk-count-chunks]');
        const btnGo    = root.querySelector('[data-bulk-download]');
        const btnClear = root.querySelector('[data-bulk-clear]');
        const progress = root.querySelector('[data-bulk-progress]');
        const headerAll = {
            xml: root.querySelector('[data-bulk-all="xml"]'),
            zip: root.querySelector('[data-bulk-all="zip"]'),
        };

        // Selection map keyed by "kind::id".
        const selection = new Map();

        const CHUNK_LIMIT = 500 * 1024 * 1024;

        // Snapshot every available (kind,id,size) tuple from the source
        // <tbody> *before* DataTables initialises. Scroller virtualises
        // rows in/out of the DOM, so this is the only reliable way for
        // "select all" to operate on the full set without round-tripping
        // through Scroller's viewport.
        const allItems = (function () {
            const out = [];
            tableEl.querySelectorAll('tbody tr').forEach(function (tr) {
                const id = tr.dataset.id;
                if (!id) return;
                const x = parseInt(tr.dataset.xmlSize || '0', 10);
                const z = parseInt(tr.dataset.zipSize || '0', 10);
                if (x > 0) out.push({ kind: 'xml', id: id, size: x });
                if (z > 0) out.push({ kind: 'zip', id: id, size: z });
            });
            return out;
        })();

        function recomputeCounts() {
            let files = 0, bytes = 0;
            for (const v of selection.values()) {
                files++;
                bytes += v.size;
            }
            // Local chunk estimate using the same greedy fit the server uses.
            let chunks = 0, accum = 0;
            for (const v of selection.values()) {
                if (accum > 0 && accum + v.size > CHUNK_LIMIT) {
                    chunks++;
                    accum = 0;
                }
                accum += v.size;
            }
            if (accum > 0) chunks++;

            elFiles.textContent  = files.toLocaleString();
            elBytes.textContent  = fmtBytes(bytes);
            elChunks.textContent = chunks.toLocaleString();
            btnGo.disabled    = files === 0;
            btnClear.disabled = files === 0;
        }

        function selKey(kind, id) { return kind + '::' + id; }

        // ---------- DataTables init ----------
        // Scroller requires paging:true (it uses page-size internally to
        // size the virtual viewport). The visible pager controls are
        // hidden via `dom`. Scroller virtualises rows in/out of the DOM,
        // so we never rely on querySelectorAll to find a row's checkbox
        // for state management — `allItems` (captured above) plus a
        // `draw.dt` sync handler are the source of truth.
        const dt = $(tableEl).DataTable({
            dom: '<"bulk-dl__toolbar"f>rt',
            paging: true,
            scroller: { boundaryScale: 0.5 },
            scrollY: '60vh',
            order: [[ 2, 'asc' ]], // sort by Title default
            columnDefs: [
                { orderable: false, targets: [0, 1] }, // checkbox columns
                { type: 'num',     targets: [5, 6] },  // size columns
            ],
            language: { search: 'Filter:', info: '_TOTAL_ STIG versions' },
        });

        // Re-sync visible checkboxes against the selection map on every
        // Scroller redraw (i.e. each time the user scrolls a new band of
        // rows into view).
        function syncVisibleCheckboxes() {
            tableEl.querySelectorAll('input[data-bulk-row]').forEach(function (chk) {
                const k = selKey(chk.dataset.bulkRow, chk.dataset.id);
                const want = selection.has(k);
                if (chk.checked !== want) chk.checked = want;
            });
        }
        $(tableEl).on('draw.dt', syncVisibleCheckboxes);

        // ---------- Row checkbox handler (event delegation) ----------
        $(tableEl).on('change', 'input[data-bulk-row]', function () {
            const kind = this.dataset.bulkRow;
            const id   = this.dataset.id;
            const size = parseInt(this.dataset.size, 10) || 0;
            const k    = selKey(kind, id);
            if (this.checked) {
                selection.set(k, { kind: kind, id: id, size: size });
            } else {
                selection.delete(k);
            }
            recomputeCounts();
        });

        // ---------- Header "select all of kind" ----------
        // Operates on `allItems` (captured at init from the source <tbody>),
        // not on the currently-rendered DOM, so every available row toggles
        // regardless of Scroller's virtual viewport.
        function toggleAll(kind, checked) {
            for (let i = 0; i < allItems.length; i++) {
                const it = allItems[i];
                if (it.kind !== kind) continue;
                const k = selKey(kind, it.id);
                if (checked) {
                    selection.set(k, { kind: kind, id: it.id, size: it.size });
                } else {
                    selection.delete(k);
                }
            }
            syncVisibleCheckboxes();
            recomputeCounts();
        }
        headerAll.xml.addEventListener('change', function () { toggleAll('xml', this.checked); });
        headerAll.zip.addEventListener('change', function () { toggleAll('zip', this.checked); });

        // ---------- Clear ----------
        btnClear.addEventListener('click', function () {
            selection.clear();
            headerAll.xml.checked = false;
            headerAll.zip.checked = false;
            syncVisibleCheckboxes();
            recomputeCounts();
        });

        // ---------- Download flow ----------
        btnGo.addEventListener('click', async function () {
            if (selection.size === 0) return;
            btnGo.disabled = true;
            btnClear.disabled = true;
            progress.hidden = false;
            progress.textContent = 'Requesting download plan …';

            const selections = Array.from(selection.values()).map(function (v) {
                return { kind: v.kind, id: v.id };
            });

            let plan;
            try {
                const resp = await fetch(PLAN_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ selections: selections }),
                });
                if (!resp.ok) throw new Error('plan HTTP ' + resp.status);
                plan = await resp.json();
            } catch (err) {
                progress.textContent = 'Could not build the download plan: ' + err.message;
                btnGo.disabled = false;
                btnClear.disabled = false;
                return;
            }

            const chunks = plan.chunks || [];
            if (chunks.length === 0) {
                progress.textContent = 'Nothing valid to download — check that the selected files still exist.';
                btnGo.disabled = false;
                btnClear.disabled = false;
                return;
            }

            for (let i = 0; i < chunks.length; i++) {
                const c = chunks[i];
                progress.textContent = 'Downloading chunk ' + c.index + ' of ' + c.of +
                    ' (' + fmtBytes(c.bytes) + ', ' + c.files.length + ' files) …';
                try {
                    await downloadChunk(c);
                } catch (err) {
                    progress.textContent = 'Chunk ' + c.index + ' failed: ' + err.message +
                        '. Stopping; selection preserved so you can retry.';
                    btnGo.disabled = false;
                    btnClear.disabled = false;
                    return;
                }
                // Small gap helps the browser group multi-download prompt.
                if (i < chunks.length - 1) await sleep(500);
            }
            progress.textContent = 'Done — ' + chunks.length + ' chunk' + (chunks.length === 1 ? '' : 's') + ' delivered.';
            btnGo.disabled = false;
            btnClear.disabled = false;
        });

        function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

        async function downloadChunk(c) {
            const resp = await fetch(CHUNK_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ files: c.files, index: c.index, of: c.of }),
            });
            if (!resp.ok) throw new Error('chunk HTTP ' + resp.status);
            const blob = await resp.blob();

            // Force a download even though we already have the bytes.
            const disposition = resp.headers.get('Content-Disposition') || '';
            const m = /filename="?([^";]+)"?/.exec(disposition);
            const filename = m ? m[1] : 'cyber-trackr-bulk-part' + c.index + '-of-' + c.of + '.zip';

            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            // Defer revoke so Safari/Firefox can finish kicking off the save.
            setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        }

        recomputeCounts();
    }

    window.bulkDownload = { init: init };
})();
