/*
 * STIG wizard client-side logic (GitHub issue #15).
 *
 * Reads window.stigWizardConfig (set by templates/stig/wizard.html.twig):
 *   { key, title, version, release, schema, skeletonUrl, viewerUrl }
 *
 * Flow:
 *   1. Render system + applicability questions from schema (recursive
 *      follow-ups revealed by parent answers).
 *   2. On "Generate": fetch the blank CKLB skeleton, walk the answers to
 *      collect actions, merge them per the schema contract, apply to the
 *      skeleton, then hand off to the CKL/CKLB viewer via sessionStorage.
 *
 * Nothing is uploaded — the skeleton is public STIG content and the answers
 * stay in the browser. See resources/data/stig-wizard/README.md for the
 * schema contract and merge semantics this implements.
 */
(function () {
    "use strict";

    var cfg = window.stigWizardConfig || {};
    var schema = cfg.schema || {};
    var STORAGE_KEY = "stig-wizard.answers." + cfg.key + ".v" + cfg.version;

    // Status precedence: Not_Applicable dominant; then Open > NotAFinding > Not_Reviewed.
    var STATUS_RANK = { Not_Reviewed: 0, NotAFinding: 1, Open: 2, Not_Applicable: 3 };
    var CONF_RANK = { high: 3, medium: 2, low: 1 };

    /** Every question by id (system + applicability + nested follow-ups). */
    var byId = {};

    // ---------------------------------------------------------------------
    //  Rendering
    // ---------------------------------------------------------------------

    function init() {
        var form = document.getElementById("stig-wizard-form");
        if (!form) return;

        if (Array.isArray(schema.system_questions) && schema.system_questions.length) {
            form.appendChild(section("Your application", schema.system_questions, true));
        }
        form.appendChild(section("Applicability questions", schema.questions || [], false));

        form.addEventListener("input", onChange);
        form.addEventListener("change", onChange);

        restore();
        refresh();
    }

    function section(label, questions, isSystem) {
        var details = el("details", "stig-wizard__section");
        details.open = true;
        var summary = el("summary");
        summary.appendChild(el("span", "stig-wizard__section-label", label));
        details.appendChild(summary);
        var body = el("div", "stig-wizard__section-body");
        renderQuestions(questions, body, isSystem);
        details.appendChild(body);
        return details;
    }

    function renderQuestions(questions, parent, isSystem) {
        (questions || []).forEach(function (q) {
            byId[q.id] = q;
            parent.appendChild(buildField(q));

            // Reveal-on-answer containers: free-text for yesno_text, and any
            // answer branch that declares follow-up questions.
            if (q.type === "yesno_text") {
                var textGrp = revealGroup(q.id, "yes");
                textGrp.appendChild(buildTextReveal(q));
                parent.appendChild(textGrp);
            }
            Object.keys(q.answers || {}).forEach(function (key) {
                var branch = q.answers[key];
                if (branch && branch.followups && branch.followups.length) {
                    var grp = revealGroup(q.id, key);
                    renderQuestions(branch.followups, grp, false);
                    parent.appendChild(grp);
                }
            });
        });
    }

    function revealGroup(parentId, key) {
        var grp = el("div", "stig-wizard__reveal");
        grp.dataset.parent = parentId;
        grp.dataset.key = key;
        grp.hidden = true;
        return grp;
    }

    function buildField(q) {
        var field = el("div", "form-field");
        field.appendChild(label(q));
        if (q.help) field.appendChild(el("p", "form-field__help", q.help));

        if (q.type === "yesno" || q.type === "yesno_text") {
            field.appendChild(radioGroup(q, ["yes", "no"], ["Yes", "No"]));
        } else if (q.type === "select") {
            field.appendChild(selectInput(q));
        } else if (q.type === "multiselect") {
            field.appendChild(checkboxGroup(q));
        } else if (q.type === "textarea") {
            field.appendChild(textInput(q, "textarea"));
        } else if (q.type === "date") {
            field.appendChild(textInput(q, "date"));
        } else {
            field.appendChild(textInput(q, "text"));
        }
        return field;
    }

    function label(q) {
        var l = el("label");
        l.textContent = q.label || q.id;
        if (q.required) {
            var r = el("span", "form-field__req", " *");
            r.setAttribute("aria-hidden", "true");
            l.appendChild(r);
        }
        return l;
    }

    function radioGroup(q, values, labels) {
        var wrap = el("div", "stig-wizard__radios");
        values.forEach(function (v, i) {
            var lab = el("label", "stig-wizard__radio");
            var input = document.createElement("input");
            input.type = "radio";
            input.name = "q_" + q.id;
            input.value = v;
            input.dataset.q = q.id;
            lab.appendChild(input);
            lab.appendChild(document.createTextNode(" " + labels[i]));
            wrap.appendChild(lab);
        });
        return wrap;
    }

    function checkboxGroup(q) {
        var wrap = el("div", "stig-wizard__checks");
        (q.options || []).forEach(function (opt) {
            var lab = el("label", "stig-wizard__check");
            var input = document.createElement("input");
            input.type = "checkbox";
            input.value = opt;
            input.dataset.q = q.id;
            lab.appendChild(input);
            lab.appendChild(document.createTextNode(" " + opt));
            wrap.appendChild(lab);
        });
        return wrap;
    }

    function selectInput(q) {
        var sel = document.createElement("select");
        sel.dataset.q = q.id;
        var blank = document.createElement("option");
        blank.value = "";
        blank.textContent = "— select —";
        sel.appendChild(blank);
        (q.options || []).forEach(function (opt) {
            var o = document.createElement("option");
            o.value = opt;
            o.textContent = opt;
            sel.appendChild(o);
        });
        return sel;
    }

    function textInput(q, kind) {
        var input = kind === "textarea" ? document.createElement("textarea") : document.createElement("input");
        if (kind !== "textarea") input.type = kind;
        if (kind === "textarea") input.rows = q.rows || 3;
        input.dataset.q = q.id;
        return input;
    }

    function buildTextReveal(q) {
        var field = el("div", "form-field");
        var l = el("label");
        l.textContent = q.text_label || "Details";
        field.appendChild(l);
        var ta = document.createElement("textarea");
        ta.rows = 3;
        ta.dataset.qText = q.id;
        field.appendChild(ta);
        return field;
    }

    // ---------------------------------------------------------------------
    //  Reactivity
    // ---------------------------------------------------------------------

    function onChange() {
        refresh();
        persist();
    }

    /** Show/hide reveal groups based on which parent answer keys have fired. */
    function refresh() {
        var answers = collectAnswers();
        var groups = document.querySelectorAll(".stig-wizard__reveal");
        Array.prototype.forEach.call(groups, function (grp) {
            var pq = byId[grp.dataset.parent];
            var fired = pq ? firedKeys(pq, answers) : [];
            grp.hidden = fired.indexOf(grp.dataset.key) < 0;
        });
    }

    // ---------------------------------------------------------------------
    //  Answer collection
    // ---------------------------------------------------------------------

    function collectAnswers() {
        var a = {};
        Object.keys(byId).forEach(function (id) {
            var q = byId[id];
            if (q.type === "multiselect") {
                a[id] = sel('input[type=checkbox][data-q="' + id + '"]:checked').map(function (cb) { return cb.value; });
            } else if (q.type === "yesno" || q.type === "yesno_text") {
                var r = document.querySelector('input[type=radio][data-q="' + id + '"]:checked');
                a[id] = r ? r.value : "";
                if (q.type === "yesno_text") {
                    var t = document.querySelector('textarea[data-q-text="' + id + '"]');
                    a[id + "_text"] = t ? t.value : "";
                }
            } else if (q.type === "select") {
                var s = document.querySelector('select[data-q="' + id + '"]');
                a[id] = s ? s.value : "";
            } else {
                var inp = document.querySelector('[data-q="' + id + '"]');
                a[id] = inp ? inp.value : "";
            }
        });
        return a;
    }

    /** Which answer-keys of a question currently "fire" (drive actions/reveals). */
    function firedKeys(q, answers) {
        var val = answers[q.id];
        if (q.type === "multiselect") {
            var selected = Array.isArray(val) ? val : [];
            return (q.options || []).map(function (opt) {
                return selected.indexOf(opt) >= 0 ? opt : "not:" + opt;
            });
        }
        if (val === undefined || val === null || val === "") return [];
        return [String(val)];
    }

    // ---------------------------------------------------------------------
    //  Reducer — answers -> per-rule status + comments
    // ---------------------------------------------------------------------

    function resolveRules(val, groups) {
        var items = Array.isArray(val) ? val : [val];
        var out = [];
        items.forEach(function (it) {
            if (typeof it === "string" && it.charAt(0) === "@") {
                (groups[it] || []).forEach(function (v) { out.push(v); });
            } else {
                out.push(it);
            }
        });
        return out;
    }

    function interpolate(str, answers) {
        return String(str || "").replace(/\{(\w+)\}/g, function (_, k) {
            var v = answers[k];
            if (Array.isArray(v)) v = v.join(", ");
            return (v === undefined || v === null) ? "" : String(v);
        });
    }

    function collectActions(questions, answers, groups, acc) {
        (questions || []).forEach(function (q) {
            firedKeys(q, answers).forEach(function (key) {
                var branch = (q.answers || {})[key];
                if (!branch) return;
                (branch.actions || []).forEach(function (action) {
                    resolveRules(action.rules, groups).forEach(function (vid) {
                        acc.push({
                            vid: vid,
                            status: action.status || "Not_Reviewed",
                            confidence: action.confidence || "",
                            comment: interpolate(action.comment, answers),
                        });
                    });
                });
                if (branch.followups) collectActions(branch.followups, answers, groups, acc);
            });
        });
    }

    function collectAlways(alwaysApply, answers, groups, acc) {
        (alwaysApply || []).forEach(function (bucket) {
            resolveRules(bucket.rules, groups).forEach(function (vid) {
                acc.push({ vid: vid, status: "Not_Reviewed", confidence: "", comment: interpolate(bucket.comment, answers) });
            });
        });
    }

    /** Merge accumulated actions into one record per V-ID. */
    function mergeByRule(acc) {
        var map = {};
        acc.forEach(function (a) {
            var e = map[a.vid] || (map[a.vid] = { status: "Not_Reviewed", comments: [], confs: [] });
            if (STATUS_RANK[a.status] > STATUS_RANK[e.status]) e.status = a.status;
            if (a.comment && e.comments.indexOf(a.comment) < 0) e.comments.push(a.comment);
            if (a.confidence) e.confs.push(a.confidence);
        });
        return map;
    }

    /** Lowest (most cautious) confidence among an action set. */
    function lowestConf(confs) {
        var lo = "";
        confs.forEach(function (c) {
            if (!lo || (CONF_RANK[c] || 9) < (CONF_RANK[lo] || 9)) lo = c;
        });
        return lo;
    }

    function applyToCklb(cklb, map) {
        var stamp = (schema.defaults && schema.defaults.review_stamp) || "";
        (cklb.stigs || []).forEach(function (stig) {
            (stig.rules || []).forEach(function (rule) {
                var e = map[rule.group_id];
                if (!e) return;
                var changed = e.status !== "Not_Reviewed";
                if (changed) rule.status = e.status;

                var parts = [];
                if (e.comments.length) parts.push(e.comments.join("\n\n"));
                if (changed && stamp) {
                    var conf = e.confs.length ? " (confidence: " + lowestConf(e.confs) + ")" : "";
                    parts.push(stamp + conf);
                }
                if (parts.length) {
                    rule.comments = (rule.comments ? rule.comments + "\n\n" : "") + parts.join("\n\n");
                }
            });
        });
    }

    function applySystemMeta(cklb, answers) {
        if (cklb.target_data && typeof cklb.target_data === "object") {
            if (answers.system_name) cklb.target_data.host_name = answers.system_name;
            if (answers.technology_area) cklb.target_data.technology_area = answers.technology_area;
        }
        cklb.customname = answers.system_acronym || answers.system_name || cklb.customname || "";
    }

    // ---------------------------------------------------------------------
    //  Generate + handoff
    // ---------------------------------------------------------------------

    /** Toggle busy state across every Generate button (top + bottom bars). */
    function setGenerateBusy(on) {
        sel(".stig-wizard__generate").forEach(function (b) {
            b.disabled = on;
            b.classList.toggle("is-busy", on);
        });
    }

    function generate() {
        var answers = collectAnswers();
        setGenerateBusy(true);

        fetch(cfg.skeletonUrl, { credentials: "same-origin" })
            .then(function (res) {
                if (!res.ok) throw new Error("Could not load STIG skeleton (HTTP " + res.status + ").");
                return res.json();
            })
            .then(function (cklb) {
                var groups = schema.rule_groups || {};
                var acc = [];
                collectActions(schema.questions || [], answers, groups, acc);
                collectAlways(schema.always_apply, answers, groups, acc);
                applyToCklb(cklb, mergeByRule(acc));
                applySystemMeta(cklb, answers);

                var stem = (answers.system_acronym || answers.system_name || cfg.title)
                    .replace(/[^A-Za-z0-9]+/g, "-").replace(/^-+|-+$/g, "").toLowerCase() || "app";
                var filename = stem + "-" + cfg.key + "-v" + cfg.version + "r" + cfg.release + ".cklb";

                sessionStorage.setItem("cyber_ckl_handoff", JSON.stringify(cklb));
                sessionStorage.setItem("cyber_ckl_handoff_meta", JSON.stringify({ filename: filename, dirty: true }));
                window.location.href = cfg.viewerUrl;
            })
            .catch(function (e) {
                setGenerateBusy(false);
                alert(e && e.message ? e.message : String(e));
            });
    }

    // ---------------------------------------------------------------------
    //  Draft persistence
    // ---------------------------------------------------------------------

    function persist() {
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(collectAnswers())); } catch (e) { /* ignore */ }
    }

    function restore() {
        var raw;
        try { raw = sessionStorage.getItem(STORAGE_KEY); } catch (e) { return; }
        if (raw) {
            try { hydrate(JSON.parse(raw)); } catch (e) { /* ignore */ }
        }
    }

    function hydrate(answers) {
        Object.keys(answers || {}).forEach(function (id) {
            var val = answers[id];
            if (/_text$/.test(id)) {
                var ta = document.querySelector('textarea[data-q-text="' + id.replace(/_text$/, "") + '"]');
                if (ta) ta.value = val || "";
                return;
            }
            var q = byId[id];
            if (!q) return;
            if (q.type === "multiselect") {
                (Array.isArray(val) ? val : []).forEach(function (v) {
                    var cb = document.querySelector('input[type=checkbox][data-q="' + id + '"][value="' + cssEscape(v) + '"]');
                    if (cb) cb.checked = true;
                });
            } else if (q.type === "yesno" || q.type === "yesno_text") {
                var r = document.querySelector('input[type=radio][data-q="' + id + '"][value="' + cssEscape(val) + '"]');
                if (r) r.checked = true;
            } else {
                var inp = document.querySelector('[data-q="' + id + '"]');
                if (inp) inp.value = val || "";
            }
        });
    }

    function saveAnswers() {
        var blob = new Blob([JSON.stringify(collectAnswers(), null, 2)], { type: "application/json" });
        var a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = cfg.key + "-wizard-answers.json";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
    }

    function loadAnswers(event) {
        var file = event.target && event.target.files && event.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            try {
                hydrate(JSON.parse(String(e.target.result || "{}")));
                refresh();
                persist();
            } catch (err) {
                alert("Could not read answers file: " + (err && err.message ? err.message : err));
            }
        };
        reader.readAsText(file);
        event.target.value = "";
    }

    // ---------------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------------

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    function sel(q) {
        return Array.prototype.slice.call(document.querySelectorAll(q));
    }

    function cssEscape(v) {
        return String(v == null ? "" : v).replace(/(["\\])/g, "\\$1");
    }

    window.stigWizard = {
        generate: generate,
        saveAnswers: saveAnswers,
        loadAnswers: loadAnswers,
        // Pure reducer surfaced for testing and reuse. Stable contract:
        // see resources/data/stig-wizard/README.md.
        _reduce: {
            resolveRules: resolveRules,
            interpolate: interpolate,
            firedKeys: firedKeys,
            collectActions: collectActions,
            collectAlways: collectAlways,
            mergeByRule: mergeByRule,
            applyToCklb: applyToCklb,
            applySystemMeta: applySystemMeta,
        },
    };

    // When the page is restored from the back-forward cache (e.g. user clicks
    // Generate, lands in the viewer, then hits Back), the DOM — including the
    // disabled/busy Generate buttons — is restored as-is and init() does NOT
    // re-run. Clear the busy state on every show so the buttons stay usable.
    window.addEventListener("pageshow", function () { setGenerateBusy(false); });

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
