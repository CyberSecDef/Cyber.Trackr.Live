/*
 * Plan wizard client-side logic.
 *
 * Reads window.planConfig (set by wizard.html.twig) for family + URLs.
 *
 * Responsibilities:
 *   - Collect form answers into the BYOJSON shape expected by PlanBuilder.
 *   - Autosave to localStorage on every input change so a tab-close
 *     doesn't lose work.
 *   - Save progress: download the current state as a .json file.
 *   - Load draft: parse an uploaded .json file and hydrate the form.
 *   - Generate preview: POST the answers to /plans/{family}/preview and
 *     open the rendered HTML in a new tab.
 *   - Per-control fields: scoped per .plan-control card via CSS-class
 *     toggles (is-implemented, is-inherited, is-na, is-selected,
 *     is-tailored).
 *   - Per-control cards: lazy-loaded via fetch when the baseline
 *     changes; existing answers in state.controls are preserved across
 *     baseline switches and re-hydrated into matching cards on swap.
 *
 * Phase 2A additions:
 *   - Enhancement disposition pickers (Selected / Inherited / Tailored
 *     Out). Tailored Out auto-selected for not-in-baseline enhancements.
 *   - ODV (organization-defined value) input fields per control,
 *     keyed by position-index (data-control-odv-id="0|1|2...").
 *     Selection-many ODVs render as checkbox groups; collected as arrays.
 *   - Per-control extra fields (data-control-extra-key) feeding
 *     state.controls[num].extras for schema-driven extensibility.
 *   - Evidence-suggestion chips that one-click prepopulate the evidence
 *     list field.
 *   - schema_version bumped to 2.
 */
(function () {
    var STORAGE_KEY = 'plan.draft.' + window.planConfig.family;
    var SCHEMA_VERSION = 2;

    var state = {
        schema_version: SCHEMA_VERSION,
        family: window.planConfig.family,
        saved_at: null,
        system: {},
        baseline: '',
        family_answers: {},
        controls: {},
        document: {}
    };

    /* ---- collect / hydrate ---- */

    function collect() {
        // Snapshot per-control answers from the DOM into state.controls
        // before we serialize. Only currently-rendered cards contribute,
        // but we merge into existing state.controls so answers from a
        // previously-rendered baseline are retained.
        var liveControls = collectControls();
        Object.keys(liveControls).forEach(function (num) {
            state.controls[num] = liveControls[num];
        });

        return {
            schema_version: SCHEMA_VERSION,
            family: state.family,
            saved_at: new Date().toISOString(),
            system: collectGroup('system'),
            baseline: getBaseline(),
            family_answers: collectGroup('family_answers'),
            controls: state.controls || {},
            document: collectGroup('document')
        };
    }

    function collectGroup(group) {
        var out = {};
        document.querySelectorAll('[data-answer-group="' + group + '"]').forEach(function (el) {
            var key = el.getAttribute('data-answer-key');
            if (!key) return;
            if (el.classList.contains('form-field__list')) {
                out[key] = listValues(el);
            } else if (el.getAttribute('data-multi-select') === '1') {
                var picked = [];
                el.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) {
                    picked.push(cb.value);
                });
                out[key] = picked;
            } else {
                out[key] = el.value;
            }
        });
        return out;
    }

    /**
     * Walk every per-control element and bucket its value into the right
     * sub-key of the per-control record. Three attribute conventions:
     *
     *   data-control-key="status|narrative|disposition|..." → top-level
     *   data-control-odv-id="N"                            → controls[num].odv[N]
     *   data-control-extra-key="K"                         → controls[num].extras[K]
     *
     * Multi-select ODVs use a wrapping div with data-control-odv-multi="1"
     * containing checkboxes; collected as an array of checked values.
     */
    function collectControls() {
        var out = {};

        // Top-level keys
        document.querySelectorAll('[data-control-num][data-control-key]').forEach(function (el) {
            var num = el.getAttribute('data-control-num');
            var key = el.getAttribute('data-control-key');
            if (!num || !key) return;
            ensureControl(out, num);
            if (el.classList.contains('form-field__list')) {
                out[num][key] = listValues(el);
            } else {
                out[num][key] = el.value;
            }
        });

        // ODVs (single + multi)
        document.querySelectorAll('[data-control-odv-id]').forEach(function (el) {
            var num = el.getAttribute('data-control-num');
            var id  = el.getAttribute('data-control-odv-id');
            if (!num || id === null) return;
            ensureControl(out, num);
            if (!out[num].odv) out[num].odv = {};
            if (el.getAttribute('data-control-odv-multi') === '1') {
                var picked = [];
                el.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) {
                    picked.push(cb.value);
                });
                out[num].odv[id] = picked;
            } else {
                out[num].odv[id] = el.value;
            }
        });

        // Extras (text / textarea / select / multi_select / list)
        document.querySelectorAll('[data-control-num][data-control-extra-key]').forEach(function (el) {
            var num = el.getAttribute('data-control-num');
            var key = el.getAttribute('data-control-extra-key');
            if (!num || !key) return;
            ensureControl(out, num);
            if (!out[num].extras) out[num].extras = {};
            if (el.classList.contains('form-field__list')) {
                out[num].extras[key] = listValues(el);
            } else if (el.getAttribute('data-multi-select') === '1') {
                var picked = [];
                el.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) {
                    picked.push(cb.value);
                });
                out[num].extras[key] = picked;
            } else {
                out[num].extras[key] = el.value;
            }
        });

        return out;
    }

    function ensureControl(out, num) {
        if (!out[num]) out[num] = {};
    }

    function listValues(container) {
        return Array.from(container.querySelectorAll('input[type="text"]'))
            .map(function (i) { return i.value.trim(); })
            .filter(Boolean);
    }

    function getBaseline() {
        var el = document.querySelector('[data-answer-key="baseline"]');
        return el ? el.value : '';
    }

    function hydrate(data) {
        if (!data || data.family !== window.planConfig.family) return;

        function setVal(group, key, val) {
            var el = document.querySelector(
                '[data-answer-group="' + group + '"][data-answer-key="' + key + '"]'
            );
            if (!el) return;
            applyValue(el, val);
        }

        Object.entries(data.system || {}).forEach(function (kv) { setVal('system', kv[0], kv[1]); });
        Object.entries(data.family_answers || {}).forEach(function (kv) { setVal('family_answers', kv[0], kv[1]); });
        Object.entries(data.document || {}).forEach(function (kv) { setVal('document', kv[0], kv[1]); });

        var baselineEl = document.querySelector('[data-answer-key="baseline"]');
        if (baselineEl) baselineEl.value = data.baseline || '';

        // Stash control answers in state; renderControls() will hydrate
        // any cards that match once they're in the DOM.
        state.controls = data.controls || {};

        // Trigger control fragment fetch if a baseline is set in the draft.
        if (data.baseline) {
            renderControls(data.baseline);
        }

        applyShowWhen();
    }

    function applyValue(el, val) {
        if (el.classList.contains('form-field__list')) {
            var ul = el.querySelector('.form-field__list-items');
            ul.innerHTML = '';
            (val || []).forEach(function (v) { listAddRow(el, v); });
        } else if (el.getAttribute('data-multi-select') === '1') {
            var picked = Array.isArray(val) ? val : [];
            el.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                cb.checked = picked.indexOf(cb.value) !== -1;
            });
        } else {
            el.value = val == null ? '' : val;
        }
    }

    /* ---- per-control cards: lazy fetch + hydration ---- */

    function renderControls(baseline) {
        var target = document.getElementById('plan-controls-target');
        if (!target) return;

        if (!baseline) {
            target.innerHTML = '<p class="plan-wizard__placeholder">Pick a baseline in section 2 and the applicable controls will appear here.</p>';
            return;
        }

        var loading = document.createElement('p');
        loading.className = 'plan-wizard__placeholder';
        loading.textContent = 'Loading controls for ' + baseline + '…';
        target.replaceChildren(loading);

        var url = window.planConfig.controlsUrlTemplate.replace('baseline-placeholder', encodeURIComponent(baseline));
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('Could not load controls (' + res.status + ')');
                return res.text();
            })
            .then(function (html) {
                target.innerHTML = html;
                wireControlCards();
                hydrateControlCards();
                applyControlStatusClasses();
                applyControlDispositionClasses();
            })
            .catch(function (err) {
                var error = document.createElement('p');
                error.className = 'plan-wizard__placeholder';
                error.textContent = 'Could not load controls: ' + err.message;
                target.replaceChildren(error);
            });
    }

    function wireControlCards() {
        // Top-level + extras: any [data-control-num] gets autosave + class refresh hooks
        document.querySelectorAll('.plan-control [data-control-num]').forEach(function (el) {
            var handler = function () {
                autosave();
                if (el.hasAttribute('data-control-status')) {
                    var card = el.closest('.plan-control');
                    setControlStatusClass(card, el.value);
                    refreshStatusBadge(card);
                }
                if (el.hasAttribute('data-control-disposition')) {
                    var card = el.closest('.plan-control');
                    setControlDispositionClass(card, el.value);
                    refreshStatusBadge(card);
                }
            };
            el.addEventListener('input', handler);
            el.addEventListener('change', handler);
        });
    }

    function hydrateControlCards() {
        if (!state.controls) return;
        document.querySelectorAll('.plan-control').forEach(function (card) {
            var num = card.getAttribute('data-control-num');
            var ans = state.controls[num];
            if (!ans) return;

            // Top-level keys
            card.querySelectorAll('[data-control-num="' + num + '"][data-control-key]').forEach(function (el) {
                var key = el.getAttribute('data-control-key');
                if (key in ans) applyValue(el, ans[key]);
            });

            // ODVs
            if (ans.odv) {
                card.querySelectorAll('[data-control-num="' + num + '"][data-control-odv-id]').forEach(function (el) {
                    var id = el.getAttribute('data-control-odv-id');
                    if (!(id in ans.odv)) return;
                    var val = ans.odv[id];
                    if (el.getAttribute('data-control-odv-multi') === '1') {
                        var picked = Array.isArray(val) ? val : [];
                        el.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                            cb.checked = picked.indexOf(cb.value) !== -1;
                        });
                    } else {
                        el.value = val == null ? '' : val;
                    }
                });
            }

            // Extras
            if (ans.extras) {
                card.querySelectorAll('[data-control-num="' + num + '"][data-control-extra-key]').forEach(function (el) {
                    var key = el.getAttribute('data-control-extra-key');
                    if (key in ans.extras) applyValue(el, ans.extras[key]);
                });
            }
        });
    }

    function applyControlStatusClasses() {
        document.querySelectorAll('.plan-control').forEach(function (card) {
            var statusEl = card.querySelector('[data-control-status]');
            if (statusEl) {
                setControlStatusClass(card, statusEl.value);
                refreshStatusBadge(card);
            }
        });
    }

    function applyControlDispositionClasses() {
        document.querySelectorAll('.plan-control').forEach(function (card) {
            var dispEl = card.querySelector('[data-control-disposition]');
            if (dispEl) {
                setControlDispositionClass(card, dispEl.value);
                refreshStatusBadge(card);
            } else {
                // Base controls don't have a disposition; treat as "active"
                // so when-active fields are visible.
                card.classList.add('is-selected');
            }
        });
    }

    function setControlStatusClass(card, status) {
        if (!card) return;
        card.classList.remove('is-implemented', 'is-partial', 'is-not-implemented', 'is-na', 'is-inherited');
        switch (status) {
            case 'Implemented':           card.classList.add('is-implemented'); break;
            case 'Partially Implemented': card.classList.add('is-implemented', 'is-partial'); break;
            case 'Not Implemented':       card.classList.add('is-not-implemented'); break;
            case 'Not Applicable':        card.classList.add('is-na'); break;
            case 'Inherited':             card.classList.add('is-inherited'); break;
        }
    }

    function setControlDispositionClass(card, disposition) {
        if (!card) return;
        card.classList.remove('is-selected', 'is-tailored', 'is-disp-inherited');
        switch (disposition) {
            case 'Selected':     card.classList.add('is-selected');         break;
            case 'Inherited':    card.classList.add('is-disp-inherited');   break;
            case 'Tailored Out': card.classList.add('is-tailored');         break;
            default:             card.classList.add('is-selected');         break;
        }
    }

    function refreshStatusBadge(card) {
        if (!card) return;
        var badge = card.querySelector('[data-control-status-badge]');
        if (!badge) return;

        // Enhancement badge prefers disposition (Tailored Out / Inherited
        // / Selected) since that's the primary decision. For Selected
        // enhancements and base controls, the implementation status is
        // the relevant signal.
        var dispEl   = card.querySelector('[data-control-disposition]');
        var statusEl = card.querySelector('[data-control-status]');
        var label = '—';
        if (dispEl && (dispEl.value === 'Tailored Out' || dispEl.value === 'Inherited')) {
            label = dispEl.value;
        } else if (statusEl && statusEl.value) {
            label = statusEl.value;
        } else if (dispEl && dispEl.value === 'Selected') {
            label = 'Selected';
        }
        badge.textContent = label;
        badge.className = 'plan-control__status-badge';
        if (label !== '—') {
            badge.classList.add('plan-control__status-badge--' + label.toLowerCase().replace(/[^a-z]+/g, '-'));
        }
    }

    /* ---- show_when (system / family / document scope only) ---- */

    function evalShowWhen(expr) {
        var inMatch = expr.match(/^(\w+)\s+in\s+(.+)$/);
        if (inMatch) {
            var key = inMatch[1];
            var values = inMatch[2].split(',').map(function (v) { return v.trim(); });
            return values.indexOf(currentValue(key)) !== -1;
        }
        var isMatch = expr.match(/^(\w+)\s+is\s+(.+)$/);
        if (isMatch) {
            return currentValue(isMatch[1]) === isMatch[2].trim();
        }
        return true;
    }

    function currentValue(key) {
        var el = document.querySelector('[data-answer-key="' + key + '"]');
        return el ? el.value : '';
    }

    function applyShowWhen() {
        document.querySelectorAll('[data-show-when]').forEach(function (el) {
            var visible = evalShowWhen(el.getAttribute('data-show-when'));
            el.style.display = visible ? '' : 'none';
        });
    }

    /* ---- evidence list field ---- */

    function listAddRow(container, initialValue) {
        var ul = container.querySelector('.form-field__list-items');
        var li = document.createElement('li');
        li.className = 'form-field__list-row';
        li.innerHTML =
            '<input type="text" class="form-field__list-input" value="' +
            (initialValue || '').replace(/"/g, '&quot;') +
            '">' +
            '<button type="button" class="form-field__list-remove" aria-label="Remove">' +
            '<i class="bi bi-x"></i></button>';
        li.querySelector('.form-field__list-remove').addEventListener('click', function () {
            li.remove();
            autosave();
        });
        li.querySelector('input').addEventListener('input', autosave);
        ul.appendChild(li);
    }

    /* ---- persistence ---- */

    function autosave() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(collect()));
        } catch (e) { /* quota or disabled — silently ignore */ }
    }

    function restore() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            var data = JSON.parse(raw);
            hydrate(data);
        } catch (e) { /* corrupt — ignore */ }
    }

    /* ---- public API ---- */

    window.planWizard = {
        listAdd: function (btn) {
            var container = btn.closest('.form-field__list');
            listAddRow(container, '');
        },
        evidenceSuggestionAdd: function (btn, value) {
            var container = btn.closest('.form-field__list');
            if (!container) return;
            // Don't double-add identical values
            var existing = listValues(container);
            if (existing.indexOf(value) !== -1) return;
            listAddRow(container, value);
            autosave();
        },
        saveDraft: function () {
            var blob = new Blob([JSON.stringify(collect(), null, 2)], { type: 'application/json' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = window.planConfig.family + '-plan-draft.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },
        loadDraft: function (event) {
            var file = event.target.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                try {
                    var data = JSON.parse(e.target.result);
                    if (data.schema_version && data.schema_version > SCHEMA_VERSION) {
                        alert('This draft was saved with a newer version of the wizard. Some fields may not load.');
                    }
                    hydrate(data);
                    autosave();
                } catch (err) {
                    alert('Could not parse the draft file: ' + err.message);
                }
            };
            reader.readAsText(file);
            event.target.value = '';
        },
        preview: function () {
            var payload = collect();
            fetch(window.planConfig.previewUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (res) {
                if (!res.ok) throw new Error('Preview failed: ' + res.status);
                return res.text();
            }).then(function (html) {
                var w = window.open('', '_blank');
                w.document.open();
                w.document.write(html);
                w.document.close();
            }).catch(function (err) {
                alert('Could not generate preview: ' + err.message);
            });
        },
        download: function () {
            var payload = collect();
            fetch(window.planConfig.downloadUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (res) {
                if (!res.ok) throw new Error('Download failed: ' + res.status);
                var disp = res.headers.get('Content-Disposition') || '';
                var match = disp.match(/filename="([^"]+)"/);
                var filename = match ? match[1] : window.planConfig.family + '-plan.docx';
                return res.blob().then(function (blob) {
                    return { blob: blob, filename: filename };
                });
            }).then(function (info) {
                var url = URL.createObjectURL(info.blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = info.filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }).catch(function (err) {
                alert('Could not generate Word document: ' + err.message);
            });
        }
    };

    /* ---- bootstrap ---- */

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-answer-group], [data-answer-key="baseline"]').forEach(function (el) {
            el.addEventListener('input', function () { autosave(); applyShowWhen(); });
            el.addEventListener('change', function () { autosave(); applyShowWhen(); });
        });

        // Multi-select checkboxes nested inside a [data-multi-select] wrapper
        // bubble change events up to the wrapper, which the listener above
        // already fields. Add an explicit listener on the inner checkboxes
        // too so older browsers without bubbling on the wrapper still
        // trigger autosave.
        document.querySelectorAll('[data-multi-select="1"] input[type="checkbox"]').forEach(function (cb) {
            cb.addEventListener('change', function () { autosave(); applyShowWhen(); });
        });

        var baselineEl = document.querySelector('[data-answer-key="baseline"]');
        if (baselineEl) {
            baselineEl.addEventListener('change', function () {
                renderControls(baselineEl.value);
            });
        }

        restore();
        applyShowWhen();
    });
})();
