/**
 * CKL / CKLB Viewer — browser-only.
 *
 * Loads .ckl (XML) or .cklb (JSON) STIG checklists, lets the user edit each
 * rule (status, finding details, comments, severity override) and asset
 * metadata (host name, IP, MAC, etc.), overlays SCAP XCCDF scan results to
 * auto-update statuses, and exports the result as a CKLB JSON.
 *
 * No server interaction — every byte stays in the browser. Charts are
 * hand-rolled SVG to avoid pulling in a chart library.
 */
(function () {
    "use strict";

    // ---------------------------------------------------------------------
    //  Constants
    // ---------------------------------------------------------------------

    const STATUSES = ["Not_Reviewed", "Open", "NotAFinding", "Not_Applicable"];
    const STATUS_LABELS = {
        Open: "Open",
        NotAFinding: "Not a Finding",
        Not_Applicable: "Not Applicable",
        Not_Reviewed: "Not Reviewed",
    };
    const STATUS_COLORS = {
        Open: "var(--ckl-color-open, #b91c1c)",
        NotAFinding: "var(--ckl-color-na-finding, #15803d)",
        Not_Applicable: "var(--ckl-color-na, #6b7280)",
        Not_Reviewed: "var(--ckl-color-nr, #d97706)",
    };
    const SEVERITY_ORDER = { high: 3, medium: 2, low: 1, "": 0 };

    /** Editable asset fields shown in the asset header. CKLB shape canonical. */
    const ASSET_FIELDS = [
        { key: "target_type",    label: "Target type",       width: "narrow" },
        { key: "host_name",      label: "Host name",         width: "wide" },
        { key: "host_ip",        label: "Host IP",           width: "narrow" },
        { key: "host_mac",       label: "Host MAC",          width: "narrow" },
        { key: "host_fqdn",      label: "FQDN",              width: "wide" },
        { key: "host_guid",      label: "Host GUID",         width: "narrow" },
        { key: "role",           label: "Role",              width: "narrow" },
        { key: "technology_area",label: "Technology area",   width: "narrow" },
        { key: "web_or_database",label: "Web or database",   width: "narrow", type: "checkbox" },
        { key: "web_db_site",    label: "Web/DB site",       width: "wide" },
        { key: "web_db_instance",label: "Web/DB instance",   width: "wide" },
        { key: "is_ia_controls", label: "IA controls",       width: "narrow", type: "checkbox" },
    ];

    // ---------------------------------------------------------------------
    //  State
    // ---------------------------------------------------------------------

    /**
     * Unified internal model — mirrors CKLB shape so export is straight
     * serialization. After load:
     *   model = {
     *       title, id, stig_guid, classification, customname, evaluate_stig_version,
     *       target_data: { ...ASSET_FIELDS keys... },
     *       cci_data: {...} (kept opaque, round-tripped through),
     *       stigs: [
     *           { stig_name, display_name, stig_id, version, release_info, uuid,
     *             reference_identifier, size, rules: [...] }
     *       ]
     *   }
     */
    let model = null;
    let originalSnapshot = null;       // for change-detection (deep-cloned at load)
    let sourceFormat = null;           // "CKL" | "CKLB"
    let sourceFilename = "checklist.cklb";
    let dirty = false;

    /** Filter state. */
    const filter = {
        text: "",
        sev: { high: true, medium: true, low: true },
        status: { Open: true, NotAFinding: true, Not_Applicable: true, Not_Reviewed: true },
        sort: "vuln",
    };

    // ---------------------------------------------------------------------
    //  Public API exposed as window.cklViewer
    // ---------------------------------------------------------------------

    const api = {
        init,
        openScap,
        exportCklb,
        reset,
        toggleSev,
        toggleStatus,
        sort,
        filter: applyFilter,
        toggleRule,
        editRule,
        editAsset,
    };

    // ---------------------------------------------------------------------
    //  Initialization
    // ---------------------------------------------------------------------

    function init() {
        const dropPrimary = document.getElementById("ckl-drop-primary");
        const filePrimary = document.getElementById("ckl-file-primary");
        const fileScap = document.getElementById("ckl-file-scap");

        wireDropZone(dropPrimary, filePrimary, handlePrimaryFile);

        if (fileScap) {
            fileScap.addEventListener("change", function () {
                if (fileScap.files && fileScap.files[0]) {
                    handleScapFile(fileScap.files[0]);
                    fileScap.value = "";  // allow re-selecting the same file
                }
            });
        }

        // Allow drop on the loaded view too (replace + scap overlay).
        const loaded = document.getElementById("ckl-loaded");
        if (loaded) {
            loaded.addEventListener("dragover", function (e) {
                e.preventDefault();
                loaded.classList.add("ckl-drag-over");
            });
            loaded.addEventListener("dragleave", function () {
                loaded.classList.remove("ckl-drag-over");
            });
            loaded.addEventListener("drop", function (e) {
                e.preventDefault();
                loaded.classList.remove("ckl-drag-over");
                if (!e.dataTransfer.files || !e.dataTransfer.files[0]) return;
                routeDroppedFile(e.dataTransfer.files[0]);
            });
        }
    }

    function wireDropZone(zone, input, handler) {
        if (!zone) return;
        zone.addEventListener("dragover", function (e) {
            e.preventDefault();
            zone.classList.add("ckl-drop--over");
        });
        zone.addEventListener("dragleave", function () {
            zone.classList.remove("ckl-drop--over");
        });
        zone.addEventListener("drop", function (e) {
            e.preventDefault();
            zone.classList.remove("ckl-drop--over");
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                handler(e.dataTransfer.files[0]);
            }
        });
        if (input) {
            input.addEventListener("change", function () {
                if (input.files && input.files[0]) {
                    handler(input.files[0]);
                    input.value = "";
                }
            });
        }
    }

    /**
     * When a file is dropped on the loaded view, decide whether it's a new
     * checklist (replace) or an XCCDF result (overlay).
     */
    function routeDroppedFile(file) {
        const name = (file.name || "").toLowerCase();
        if (name.endsWith(".ckl") || name.endsWith(".cklb") || name.endsWith(".json")) {
            handlePrimaryFile(file);
        } else if (name.endsWith(".xml")) {
            // Could be a new CKL or an XCCDF result — peek at content.
            const reader = new FileReader();
            reader.onload = function (e) {
                const txt = String(e.target.result || "").slice(0, 8192);
                if (/<\s*Benchmark[\s>]/i.test(txt) || /<\s*TestResult[\s>]/i.test(txt)) {
                    handleScapFile(file);
                } else {
                    handlePrimaryFile(file);
                }
            };
            reader.readAsText(file);
        } else {
            // Unknown extension — try as primary.
            handlePrimaryFile(file);
        }
    }

    // ---------------------------------------------------------------------
    //  File handling — primary checklist
    // ---------------------------------------------------------------------

    function handlePrimaryFile(file) {
        const reader = new FileReader();
        reader.onload = function (e) {
            const txt = String(e.target.result || "");
            const name = (file.name || "checklist").toLowerCase();
            try {
                if (name.endsWith(".cklb") || name.endsWith(".json") || /^\s*\{/.test(txt)) {
                    model = parseCklb(txt);
                    sourceFormat = "CKLB";
                } else {
                    model = parseCkl(txt);
                    sourceFormat = "CKL";
                }
            } catch (err) {
                alert("Could not parse file:\n" + (err && err.message ? err.message : err));
                return;
            }
            sourceFilename = file.name || "checklist";
            originalSnapshot = JSON.stringify(model);
            dirty = false;

            renderEverything();
            showLoaded(true);
        };
        reader.onerror = function () { alert("Could not read file."); };
        reader.readAsText(file);
    }

    // ---------------------------------------------------------------------
    //  CKLB (JSON) parser
    // ---------------------------------------------------------------------

    function parseCklb(txt) {
        const raw = JSON.parse(txt);
        if (!raw || typeof raw !== "object") throw new Error("Empty or invalid CKLB JSON.");

        // Normalize asset fields.
        const target = raw.target_data || {};
        const td = {};
        ASSET_FIELDS.forEach(function (f) {
            td[f.key] = target[f.key] !== undefined ? target[f.key] : (f.type === "checkbox" ? false : "");
        });

        // Normalize each STIG block + each rule.
        const stigs = (Array.isArray(raw.stigs) ? raw.stigs : []).map(normalizeStigBlock);

        return {
            title: raw.title || "",
            id: raw.id || "",
            stig_guid: raw.stig_guid || "",
            classification: raw.classification || "UNCLASSIFIED",
            customname: raw.customname || "",
            evaluate_stig_version: raw.evaluate_stig_version || "",
            cci_data: raw.cci_data || {},
            target_data: td,
            stigs: stigs,
        };
    }

    function normalizeStigBlock(s) {
        const rules = (Array.isArray(s.rules) ? s.rules : []).map(normalizeRule);
        return {
            stig_name: s.stig_name || s.display_name || "",
            display_name: s.display_name || s.stig_name || "",
            stig_id: s.stig_id || "",
            release_info: s.release_info || "",
            version: s.version || "",
            uuid: s.uuid || "",
            reference_identifier: s.reference_identifier || "",
            size: s.size || (rules ? rules.length : 0),
            rules: rules,
        };
    }

    function normalizeRule(r) {
        return {
            uuid: r.uuid || "",
            stig_uuid: r.stig_uuid || "",
            target_key: r.target_key || null,
            stig_ref: r.stig_ref || null,
            group_id_src: r.group_id_src || "",
            group_tree: r.group_tree || [],
            group_id: r.group_id || "",
            severity: (r.severity || "").toLowerCase(),
            group_title: r.group_title || "",
            rule_id_src: r.rule_id_src || "",
            rule_id: r.rule_id || "",
            rule_version: r.rule_version || r.version || "",
            rule_title: r.rule_title || "",
            fix_text: r.fix_text || "",
            weight: r.weight || "",
            classification: r.classification || "",
            ccis: Array.isArray(r.ccis) ? r.ccis : [],
            legacy_ids: Array.isArray(r.legacy_ids) ? r.legacy_ids : [],
            discussion: r.discussion || "",
            check_content: r.check_content || "",
            check_content_ref: r.check_content_ref || {},
            false_positives: r.false_positives || "",
            false_negatives: r.false_negatives || "",
            documentable: !!r.documentable,
            mitigations: r.mitigations || "",
            potential_impacts: r.potential_impacts || "",
            third_party_tools: r.third_party_tools || "",
            mitigation_control: r.mitigation_control || "",
            responsibility: r.responsibility || "",
            security_override_guidance: r.security_override_guidance || "",
            ia_controls: r.ia_controls || "",
            // The editable bits — initialize to safe defaults if absent.
            status: STATUSES.includes(r.status) ? r.status : "Not_Reviewed",
            finding_details: r.finding_details || "",
            comments: r.comments || "",
            severity_override: r.severity_override || "",
            severity_justification: r.severity_justification || "",
            // Annotated fields (Evaluate-STIG style); pass through if present.
            stig_evaluation: r.stig_evaluation || null,
        };
    }

    // ---------------------------------------------------------------------
    //  CKL (XML) parser → unified CKLB-shape model
    // ---------------------------------------------------------------------

    function parseCkl(txt) {
        const doc = new DOMParser().parseFromString(txt, "application/xml");
        if (doc.querySelector("parsererror")) {
            throw new Error("CKL XML parse error.");
        }

        // Asset block.
        const asset = doc.querySelector("ASSET") || {};
        const td = {};
        ASSET_FIELDS.forEach(function (f) { td[f.key] = (f.type === "checkbox" ? false : ""); });
        td.host_name        = childText(asset, "HOST_NAME");
        td.host_ip          = childText(asset, "HOST_IP");
        td.host_mac         = childText(asset, "HOST_MAC");
        td.host_fqdn        = childText(asset, "HOST_FQDN");
        td.host_guid        = childText(asset, "HOST_GUID");
        td.target_type      = childText(asset, "ASSET_TYPE");
        td.role             = childText(asset, "ROLE");
        td.technology_area  = childText(asset, "TECH_AREA");
        td.web_or_database  = (childText(asset, "WEB_OR_DATABASE") || "").toLowerCase() === "true";
        td.web_db_site      = childText(asset, "WEB_DB_SITE");
        td.web_db_instance  = childText(asset, "WEB_DB_INSTANCE");

        // STIG blocks — one <iSTIG> per STIG embedded in the checklist.
        const stigBlocks = Array.from(doc.querySelectorAll("STIGS > iSTIG"));
        const stigs = stigBlocks.map(parseCklStigBlock);

        return {
            title: "",
            id: "",
            stig_guid: "",
            classification: "UNCLASSIFIED",
            customname: "",
            evaluate_stig_version: "",
            cci_data: {},
            target_data: td,
            stigs: stigs,
        };
    }

    function parseCklStigBlock(iStig) {
        const info = {};
        Array.from(iStig.querySelectorAll("STIG_INFO > SI_DATA")).forEach(function (si) {
            const k = childText(si, "SID_NAME");
            const v = childText(si, "SID_DATA");
            if (k) info[k] = v;
        });

        const rules = Array.from(iStig.querySelectorAll(":scope > VULN")).map(parseCklVuln);

        return {
            stig_name: info.title || info.stigid || "",
            display_name: info.title || info.stigid || "",
            stig_id: info.stigid || "",
            release_info: info.releaseinfo || "",
            version: info.version || "",
            uuid: info.uuid || "",
            reference_identifier: info.classification || "",
            size: rules.length,
            rules: rules,
        };
    }

    function parseCklVuln(vuln) {
        const sti = {};
        Array.from(vuln.querySelectorAll(":scope > STIG_DATA")).forEach(function (sd) {
            const k = childText(sd, "VULN_ATTRIBUTE");
            const v = childText(sd, "ATTRIBUTE_DATA");
            if (!k) return;
            // Some attributes (CCI_REF, LEGACY_ID, IA_Controls) repeat — collect arrays.
            if (sti[k] !== undefined) {
                if (!Array.isArray(sti[k])) sti[k] = [sti[k]];
                sti[k].push(v);
            } else {
                sti[k] = v;
            }
        });
        const status   = mapCklStatus(childText(vuln, "STATUS"));
        const finding  = childText(vuln, "FINDING_DETAILS");
        const comments = childText(vuln, "COMMENTS");
        const sevOverride = childText(vuln, "SEVERITY_OVERRIDE");
        const sevJust  = childText(vuln, "SEVERITY_JUSTIFICATION");

        const arrayify = function (v) {
            if (v === undefined) return [];
            return Array.isArray(v) ? v.filter(Boolean) : (v ? [v] : []);
        };

        return {
            uuid: "",
            stig_uuid: "",
            target_key: null,
            stig_ref: null,
            group_id_src: sti.Vuln_Num || "",
            group_id: sti.Vuln_Num || "",
            group_tree: [],
            severity: (sti.Severity || "").toLowerCase(),
            group_title: sti.Group_Title || "",
            rule_id_src: sti.Rule_ID || "",
            rule_id: sti.Rule_ID || "",
            rule_version: sti.Rule_Ver || "",
            rule_title: sti.Rule_Title || "",
            fix_text: sti.Fix_Text || "",
            weight: sti.Weight || "",
            classification: sti.Class || "",
            ccis: arrayify(sti.CCI_REF),
            legacy_ids: arrayify(sti.LEGACY_ID),
            discussion: sti.Vuln_Discuss || "",
            check_content: sti.Check_Content || "",
            check_content_ref: { name: sti.Check_Content_Ref || "", href: "" },
            false_positives: sti.False_Positives || "",
            false_negatives: sti.False_Negatives || "",
            documentable: (sti.Documentable || "").toString().toLowerCase() === "true",
            mitigations: sti.Mitigations || "",
            potential_impacts: sti.Potential_Impact || "",
            third_party_tools: sti.Third_Party_Tools || "",
            mitigation_control: sti.Mitigation_Control || "",
            responsibility: sti.Responsibility || "",
            security_override_guidance: sti.Security_Override_Guidance || "",
            ia_controls: Array.isArray(sti.IA_Controls) ? sti.IA_Controls.join(", ") : (sti.IA_Controls || ""),
            status: status,
            finding_details: finding,
            comments: comments,
            severity_override: sevOverride && sevOverride !== "0" ? sevOverride.toLowerCase() : "",
            severity_justification: sevJust,
            stig_evaluation: null,
        };
    }

    function mapCklStatus(raw) {
        switch ((raw || "").trim()) {
            case "Open":            return "Open";
            case "NotAFinding":     return "NotAFinding";
            case "Not_Applicable":  return "Not_Applicable";
            case "Not_Reviewed":
            default:                return "Not_Reviewed";
        }
    }

    // ---------------------------------------------------------------------
    //  SCAP XCCDF overlay
    // ---------------------------------------------------------------------

    function openScap() {
        document.getElementById("ckl-file-scap").click();
    }

    function handleScapFile(file) {
        const reader = new FileReader();
        reader.onload = function (e) {
            try {
                const summary = applyXccdfResults(String(e.target.result || ""));
                alert(
                    "SCAP results applied:\n" +
                    "  Pass → Not a Finding: " + summary.pass + "\n" +
                    "  Fail → Open: "          + summary.fail + "\n" +
                    "  Not applicable: "       + summary.na + "\n" +
                    "  Unknown / not checked: "+ summary.unknown + "\n" +
                    "  Unmatched (no rule with that ID): " + summary.unmatched
                );
            } catch (err) {
                alert("Could not apply SCAP results:\n" + (err && err.message ? err.message : err));
            }
        };
        reader.readAsText(file);
    }

    function applyXccdfResults(txt) {
        const doc = new DOMParser().parseFromString(txt, "application/xml");
        if (doc.querySelector("parsererror")) {
            throw new Error("SCAP XCCDF XML parse error.");
        }

        // Build an index of rules by both rule_id_src and rule_version so we
        // can match either form coming out of XCCDF.
        const byRuleId = new Map();
        const byVersion = new Map();
        model.stigs.forEach(function (stig) {
            stig.rules.forEach(function (r) {
                if (r.rule_id_src) byRuleId.set(r.rule_id_src, r);
                if (r.rule_id)     byRuleId.set(r.rule_id, r);
                // Some XCCDFs use ".../rule_<id>" full URI form.
                if (r.rule_id_src) byRuleId.set("xccdf_mil.disa.stig_rule_" + r.rule_id_src, r);
                if (r.rule_version) byVersion.set(r.rule_version, r);
            });
        });

        const summary = { pass: 0, fail: 0, na: 0, unknown: 0, unmatched: 0 };

        // SCAP <rule-result> elements — could be in any namespace, so query all.
        const ruleResults = Array.from(doc.getElementsByTagNameNS("*", "rule-result"));
        ruleResults.forEach(function (rr) {
            const idref = rr.getAttribute("idref") || "";
            // Try: full idref → bare suffix → match by rule_version.
            let rule = byRuleId.get(idref);
            if (!rule) {
                const tail = idref.split("_").pop();
                if (tail) rule = byRuleId.get(tail);
            }
            if (!rule) {
                // Maybe XCCDF used the rule_version (e.g., WLAN-ND-000200).
                const verAttr = idref.replace(/^.*xccdf_mil\.disa\.stig_rule_/, "");
                rule = byVersion.get(verAttr) || byVersion.get(idref);
            }
            if (!rule) {
                summary.unmatched++;
                return;
            }

            const resultNode = Array.from(rr.children).find(function (c) {
                return /(^|:)result$/.test(c.tagName);
            });
            const result = (resultNode ? resultNode.textContent : "").trim().toLowerCase();

            switch (result) {
                case "pass":
                    rule.status = "NotAFinding"; summary.pass++; break;
                case "fail":
                    rule.status = "Open";        summary.fail++; break;
                case "notapplicable":
                    rule.status = "Not_Applicable"; summary.na++; break;
                default:
                    summary.unknown++;
            }

            // Append a finding-details note recording the SCAP application.
            const stamp = "[SCAP " + (result || "unknown") + " — " + new Date().toISOString().slice(0, 10) + "]";
            if (!rule.finding_details.includes(stamp)) {
                rule.finding_details = (rule.finding_details ? rule.finding_details + "\n\n" : "") + stamp;
            }
        });

        markDirty();
        renderEverything();
        return summary;
    }

    // ---------------------------------------------------------------------
    //  Rendering
    // ---------------------------------------------------------------------

    function renderEverything() {
        renderToolbar();
        renderAsset();
        renderDashboard();
        renderRules();
    }

    function renderToolbar() {
        setBind("filename", sourceFilename);
        setBind("source-format", sourceFormat || "");
        const dirtyEl = document.querySelector("[data-bind='dirty-flag']");
        if (dirtyEl) dirtyEl.hidden = !dirty;
    }

    function renderAsset() {
        const grid = document.getElementById("ckl-asset-grid");
        if (!grid) return;
        grid.innerHTML = "";
        const td = model.target_data;
        ASSET_FIELDS.forEach(function (f) {
            const wrap = document.createElement("div");
            wrap.className = "ckl-asset__field ckl-asset__field--" + (f.width || "narrow");
            const label = document.createElement("label");
            const lbl = document.createElement("span");
            lbl.className = "eyebrow";
            lbl.textContent = f.label;
            label.appendChild(lbl);
            let input;
            if (f.type === "checkbox") {
                input = document.createElement("input");
                input.type = "checkbox";
                input.checked = !!td[f.key];
                input.addEventListener("change", function () {
                    td[f.key] = input.checked;
                    markDirty();
                    renderAssetSummary();
                });
            } else {
                input = document.createElement("input");
                input.type = "text";
                input.value = td[f.key] || "";
                input.addEventListener("input", function () {
                    td[f.key] = input.value;
                    markDirty();
                    renderAssetSummary();
                });
            }
            label.appendChild(input);
            wrap.appendChild(label);
            grid.appendChild(wrap);
        });
        renderAssetSummary();
    }

    function renderAssetSummary() {
        const td = model.target_data || {};
        const bits = [td.host_name, td.host_ip, td.target_type].filter(Boolean);
        setBind("asset-summary", bits.length ? bits.join(" · ") : "—");
    }

    function renderDashboard() {
        const counts = computeCounts();
        setBind("total",     counts.total);
        setBind("sev-high",  counts.bySev.high);
        setBind("sev-medium",counts.bySev.medium);
        setBind("sev-low",   counts.bySev.low);

        renderDonut(counts.byStatus);
        renderBars(counts.bySevStatus);
    }

    function computeCounts() {
        const out = {
            total: 0,
            bySev: { high: 0, medium: 0, low: 0 },
            byStatus: { Open: 0, NotAFinding: 0, Not_Applicable: 0, Not_Reviewed: 0 },
            bySevStatus: {
                high:   { Open: 0, NotAFinding: 0, Not_Applicable: 0, Not_Reviewed: 0 },
                medium: { Open: 0, NotAFinding: 0, Not_Applicable: 0, Not_Reviewed: 0 },
                low:    { Open: 0, NotAFinding: 0, Not_Applicable: 0, Not_Reviewed: 0 },
            },
        };
        model.stigs.forEach(function (stig) {
            stig.rules.forEach(function (r) {
                out.total++;
                const sev = effectiveSeverity(r);
                if (out.bySev[sev] !== undefined) out.bySev[sev]++;
                const st  = STATUSES.includes(r.status) ? r.status : "Not_Reviewed";
                out.byStatus[st]++;
                if (out.bySevStatus[sev]) out.bySevStatus[sev][st]++;
            });
        });
        return out;
    }

    function effectiveSeverity(r) {
        if (r.severity_override && SEVERITY_ORDER[r.severity_override]) return r.severity_override;
        return SEVERITY_ORDER[r.severity] ? r.severity : "low";
    }

    // ----- SVG donut chart -----

    function renderDonut(byStatus) {
        const svg = document.getElementById("ckl-donut");
        const legend = document.getElementById("ckl-donut-legend");
        if (!svg || !legend) return;
        svg.innerHTML = "";
        legend.innerHTML = "";

        const cx = 100, cy = 100, r = 80, hole = 50;
        const total = Object.values(byStatus).reduce(function (a, b) { return a + b; }, 0);

        if (total === 0) {
            svg.appendChild(circle(cx, cy, r, "var(--border)"));
            svg.appendChild(circle(cx, cy, hole, "var(--surface)"));
            const txt = svgText(cx, cy + 5, "No data", { class: "ckl-donut__total" });
            svg.appendChild(txt);
            return;
        }

        let angle = -Math.PI / 2;
        STATUSES.forEach(function (st) {
            const v = byStatus[st] || 0;
            if (v === 0) return;
            const span = (v / total) * Math.PI * 2;
            const arc = donutArc(cx, cy, r, hole, angle, angle + span);
            const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
            path.setAttribute("d", arc);
            path.setAttribute("fill", STATUS_COLORS[st]);
            svg.appendChild(path);
            angle += span;

            const li = document.createElement("li");
            li.className = "ckl-legend__item";
            li.innerHTML = '<span class="ckl-legend__swatch" style="background:' + STATUS_COLORS[st] + '"></span>' +
                '<span class="ckl-legend__label">' + STATUS_LABELS[st] + '</span>' +
                '<span class="ckl-legend__count">' + v + '</span>' +
                '<span class="ckl-legend__pct">' + Math.round((v / total) * 100) + '%</span>';
            legend.appendChild(li);
        });

        // Center label — total finding rate.
        const open = byStatus.Open || 0;
        const compliant = (byStatus.NotAFinding || 0);
        const reviewed = total - (byStatus.Not_Reviewed || 0);
        const rate = reviewed > 0 ? Math.round((compliant / reviewed) * 100) : 0;
        svg.appendChild(svgText(cx, cy - 4, rate + "%", { class: "ckl-donut__big" }));
        svg.appendChild(svgText(cx, cy + 16, "compliant", { class: "ckl-donut__sub" }));
    }

    function donutArc(cx, cy, rOuter, rInner, a0, a1) {
        const x0o = cx + rOuter * Math.cos(a0), y0o = cy + rOuter * Math.sin(a0);
        const x1o = cx + rOuter * Math.cos(a1), y1o = cy + rOuter * Math.sin(a1);
        const x0i = cx + rInner * Math.cos(a1), y0i = cy + rInner * Math.sin(a1);
        const x1i = cx + rInner * Math.cos(a0), y1i = cy + rInner * Math.sin(a0);
        const large = a1 - a0 > Math.PI ? 1 : 0;
        return [
            "M", x0o, y0o,
            "A", rOuter, rOuter, 0, large, 1, x1o, y1o,
            "L", x0i, y0i,
            "A", rInner, rInner, 0, large, 0, x1i, y1i,
            "Z",
        ].join(" ");
    }

    function circle(cx, cy, r, fill) {
        const c = document.createElementNS("http://www.w3.org/2000/svg", "circle");
        c.setAttribute("cx", cx); c.setAttribute("cy", cy); c.setAttribute("r", r);
        c.setAttribute("fill", fill);
        return c;
    }

    function svgText(x, y, text, attrs) {
        const t = document.createElementNS("http://www.w3.org/2000/svg", "text");
        t.setAttribute("x", x); t.setAttribute("y", y);
        t.setAttribute("text-anchor", "middle");
        if (attrs && attrs.class) t.setAttribute("class", attrs.class);
        t.textContent = text;
        return t;
    }

    // ----- SVG grouped bars: severity (rows) × status (stacked) -----

    function renderBars(bySevStatus) {
        const svg = document.getElementById("ckl-bars");
        if (!svg) return;
        svg.innerHTML = "";

        const W = 360, H = 180;
        const left = 60, right = 16, top = 16, bottom = 28;
        const innerW = W - left - right;
        const innerH = H - top - bottom;
        const sevs = ["high", "medium", "low"];
        const sevLabels = { high: "High", medium: "Med", low: "Low" };
        const rowH = innerH / sevs.length;
        const barH = rowH * 0.7;

        // Find max so we can scale.
        let max = 0;
        sevs.forEach(function (s) {
            const total = STATUSES.reduce(function (a, st) { return a + (bySevStatus[s][st] || 0); }, 0);
            if (total > max) max = total;
        });
        if (max === 0) max = 1;

        sevs.forEach(function (s, i) {
            const y = top + i * rowH + (rowH - barH) / 2;
            // Severity label.
            const lab = svgText(left - 8, y + barH * 0.65, sevLabels[s], { class: "ckl-bars__label" });
            lab.setAttribute("text-anchor", "end");
            svg.appendChild(lab);

            let x = left;
            STATUSES.forEach(function (st) {
                const v = bySevStatus[s][st] || 0;
                if (v === 0) return;
                const w = (v / max) * innerW;
                const r = document.createElementNS("http://www.w3.org/2000/svg", "rect");
                r.setAttribute("x", x); r.setAttribute("y", y);
                r.setAttribute("width", w); r.setAttribute("height", barH);
                r.setAttribute("fill", STATUS_COLORS[st]);
                r.setAttribute("rx", "2");
                svg.appendChild(r);

                if (w >= 22) {
                    const t = svgText(x + w / 2, y + barH / 2 + 4, String(v), { class: "ckl-bars__count" });
                    svg.appendChild(t);
                }
                x += w;
            });
        });
    }

    // ---------------------------------------------------------------------
    //  Rule list rendering + editing
    // ---------------------------------------------------------------------

    function renderRules() {
        const host = document.getElementById("ckl-rules");
        if (!host) return;
        host.innerHTML = "";
        const tpl = document.getElementById("ckl-rule-template");
        if (!tpl) return;

        const flat = [];
        model.stigs.forEach(function (stig) {
            stig.rules.forEach(function (r) { flat.push({ stig: stig, rule: r }); });
        });

        // Sort.
        flat.sort(function (a, b) {
            switch (filter.sort) {
                case "severity": {
                    const d = SEVERITY_ORDER[effectiveSeverity(b.rule)] - SEVERITY_ORDER[effectiveSeverity(a.rule)];
                    if (d !== 0) return d;
                    return (a.rule.group_id || "").localeCompare(b.rule.group_id || "");
                }
                case "status":
                    return (a.rule.status || "").localeCompare(b.rule.status || "");
                case "title":
                    return (a.rule.rule_title || "").localeCompare(b.rule.rule_title || "");
                case "vuln":
                default:
                    return naturalCompare(a.rule.group_id || "", b.rule.group_id || "");
            }
        });

        let visible = 0;
        flat.forEach(function (entry) {
            const r = entry.rule;
            if (!filter.sev[effectiveSeverity(r)]) return;
            if (!filter.status[r.status]) return;
            if (filter.text) {
                const haystack = [
                    r.group_id, r.rule_id, r.rule_id_src, r.rule_title, r.rule_version,
                    r.discussion, r.check_content, r.fix_text, r.finding_details, r.comments,
                ].join(" ").toLowerCase();
                if (!haystack.includes(filter.text)) return;
            }

            const node = tpl.content.cloneNode(true);
            const article = node.querySelector(".ckl-rule");
            article.dataset.vulnId = r.group_id || r.rule_id || "";

            const sev = effectiveSeverity(r);
            article.classList.add("ckl-rule--" + sev);
            article.classList.add("ckl-rule--status-" + r.status);
            const sevSpan = node.querySelector(".ckl-rule__sev");
            sevSpan.textContent = sev === "high" ? "I" : (sev === "medium" ? "II" : "III");
            sevSpan.title = "CAT " + (sev === "high" ? "I" : (sev === "medium" ? "II" : "III")) + " — " + sev;

            setNodeBind(node, "vuln-id", r.group_id || "—");
            setNodeBind(node, "title", r.rule_title || r.group_title || "(untitled)");
            const pill = node.querySelector("[data-bind='status-pill']");
            pill.textContent = STATUS_LABELS[r.status];
            pill.className = "ckl-rule__status ckl-rule__status--" + r.status;

            setNodeBind(node, "rule-id", r.rule_id || r.rule_id_src || "—");
            setNodeBind(node, "group-id", r.rule_version || "—");
            setNodeBind(node, "cci", (r.ccis && r.ccis.length ? r.ccis.join(", ") : "—"));
            setNodeBind(node, "severity", sev);
            setNodeBind(node, "discussion", r.discussion || "—");
            setNodeBind(node, "check", r.check_content || "—");
            setNodeBind(node, "fix", r.fix_text || "—");

            if (isRuleChanged(r)) {
                const flag = node.querySelector("[data-bind='changed-flag']");
                if (flag) flag.hidden = false;
            }

            // Wire editor inputs.
            const sel = node.querySelector("[data-edit='status']");
            sel.value = r.status;
            sel.dataset.vulnId = r.group_id || r.rule_id;

            const so = node.querySelector("[data-edit='severity_override']");
            so.value = r.severity_override || "";
            so.dataset.vulnId = r.group_id || r.rule_id;

            const sj = node.querySelector("[data-edit='severity_justification']");
            sj.value = r.severity_justification || "";
            sj.dataset.vulnId = r.group_id || r.rule_id;

            const fd = node.querySelector("[data-edit='finding_details']");
            fd.value = r.finding_details || "";
            fd.dataset.vulnId = r.group_id || r.rule_id;

            const cm = node.querySelector("[data-edit='comments']");
            cm.value = r.comments || "";
            cm.dataset.vulnId = r.group_id || r.rule_id;

            host.appendChild(node);
            visible++;
        });

        const empty = document.getElementById("ckl-empty-after-filter");
        if (empty) empty.hidden = visible !== 0;
    }

    function isRuleChanged(r) {
        if (!originalSnapshot) return false;
        try {
            const orig = JSON.parse(originalSnapshot);
            for (let i = 0; i < orig.stigs.length; i++) {
                const found = orig.stigs[i].rules.find(function (or) { return or.group_id === r.group_id; });
                if (!found) continue;
                return (
                    found.status !== r.status ||
                    found.finding_details !== r.finding_details ||
                    found.comments !== r.comments ||
                    found.severity_override !== r.severity_override ||
                    found.severity_justification !== r.severity_justification
                );
            }
        } catch (e) { /* swallow */ }
        return false;
    }

    function naturalCompare(a, b) {
        return a.localeCompare(b, undefined, { numeric: true, sensitivity: "base" });
    }

    function toggleRule(headerEl) {
        const article = headerEl.closest(".ckl-rule");
        if (article) article.classList.toggle("ckl-rule--open");
    }

    function editRule(input) {
        const article = input.closest(".ckl-rule");
        if (!article) return;
        const vulnId = article.dataset.vulnId;
        const field = input.dataset.edit;
        const rule = findRuleByVuln(vulnId);
        if (!rule) return;

        rule[field] = input.value;
        markDirty();

        // Live-update the status pill + dashboard.
        if (field === "status" || field === "severity_override") {
            const sev = effectiveSeverity(rule);
            article.className = article.className
                .replace(/ckl-rule--(high|medium|low)/g, "")
                .replace(/ckl-rule--status-\w+/g, "")
                .trim();
            article.classList.add("ckl-rule");
            article.classList.add("ckl-rule--" + sev);
            article.classList.add("ckl-rule--status-" + rule.status);
            if (article.classList.contains("ckl-rule--open") === false) {
                // Keep collapsed-state — don't add this class.
            }

            const sevSpan = article.querySelector(".ckl-rule__sev");
            if (sevSpan) sevSpan.textContent = sev === "high" ? "I" : (sev === "medium" ? "II" : "III");

            const pill = article.querySelector("[data-bind='status-pill']");
            if (pill) {
                pill.textContent = STATUS_LABELS[rule.status];
                pill.className = "ckl-rule__status ckl-rule__status--" + rule.status;
            }

            renderDashboard();
        }

        const flag = article.querySelector("[data-bind='changed-flag']");
        if (flag) flag.hidden = !isRuleChanged(rule);
    }

    function editAsset() { /* wired via input listeners in renderAsset */ }

    function findRuleByVuln(vulnId) {
        for (let s = 0; s < model.stigs.length; s++) {
            const r = model.stigs[s].rules.find(function (rr) {
                return (rr.group_id || rr.rule_id) === vulnId;
            });
            if (r) return r;
        }
        return null;
    }

    // ---------------------------------------------------------------------
    //  Filtering / sorting
    // ---------------------------------------------------------------------

    function toggleSev(btn) {
        btn.classList.toggle("active");
        filter.sev[btn.dataset.sev] = btn.classList.contains("active");
        renderRules();
    }
    function toggleStatus(btn) {
        btn.classList.toggle("active");
        filter.status[btn.dataset.status] = btn.classList.contains("active");
        renderRules();
    }
    function sort() {
        const v = document.getElementById("ckl-sort").value;
        filter.sort = v;
        renderRules();
    }
    function applyFilter() {
        const v = (document.getElementById("ckl-search").value || "").toLowerCase();
        filter.text = v;
        renderRules();
    }

    // ---------------------------------------------------------------------
    //  Export to CKLB JSON
    // ---------------------------------------------------------------------

    function exportCklb() {
        if (!model) return;
        const json = JSON.stringify(model, null, 2);
        const blob = new Blob([json], { type: "application/json" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        const base = (sourceFilename || "checklist").replace(/\.(ckl|cklb|json|xml)$/i, "");
        a.download = base + "_edited.cklb";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        // Reset dirty + snapshot to the just-exported state.
        originalSnapshot = json;
        dirty = false;
        renderToolbar();
        renderRules();
    }

    // ---------------------------------------------------------------------
    //  Reset / utilities
    // ---------------------------------------------------------------------

    function reset() {
        if (dirty && !confirm("You have unsaved changes. Close this checklist anyway?")) return;
        model = null;
        originalSnapshot = null;
        sourceFormat = null;
        sourceFilename = "checklist.cklb";
        dirty = false;
        showLoaded(false);
    }

    function showLoaded(on) {
        const empty = document.getElementById("ckl-empty");
        const loaded = document.getElementById("ckl-loaded");
        if (empty)  empty.hidden = on;
        if (loaded) loaded.hidden = !on;
    }

    function markDirty() {
        if (!dirty) {
            dirty = true;
            renderToolbar();
        }
    }

    function setBind(key, value) {
        const el = document.querySelector("[data-bind='" + key + "']");
        if (el) el.textContent = value;
    }

    function setNodeBind(node, key, value) {
        const el = node.querySelector("[data-bind='" + key + "']");
        if (el) el.textContent = value;
    }

    function childText(node, tag) {
        if (!node || !node.children) return "";
        for (let i = 0; i < node.children.length; i++) {
            if (node.children[i].tagName === tag) return (node.children[i].textContent || "").trim();
        }
        return "";
    }

    // ---------------------------------------------------------------------
    //  Bootstrap
    // ---------------------------------------------------------------------

    window.cklViewer = api;
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
