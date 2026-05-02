/**
 * Saved searches — localStorage-backed bookmarks for the global search.
 *
 * No backend. No server interaction. Drafts live in the user's browser only,
 * keyed under 'cyber-trackr.savedSearches' as a JSON array of:
 *
 *     { id: string, label: string, query: string, saved_at: number (ms epoch) }
 *
 * Usage:
 *
 *     window.savedSearches.add("Windows 11");
 *     window.savedSearches.add("AC-2",  "Account management");  // optional label
 *     window.savedSearches.list();
 *     window.savedSearches.has("AC-2") // → true
 *
 * Pages mount the dropdown UI by passing trigger + panel elements:
 *
 *     savedSearches.wireDropdown(
 *         document.querySelector("#hero-bookmark-trigger"),
 *         document.querySelector("#hero-bookmark-panel")
 *     );
 */
(function () {
    "use strict";

    const STORAGE_KEY = "cyber-trackr.savedSearches";

    // ---------------------------------------------------------------------
    //  Storage helpers
    // ---------------------------------------------------------------------

    function load() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            const arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr.filter(isValid) : [];
        } catch (e) {
            return [];
        }
    }

    function save(arr) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(arr));
        } catch (e) {
            // Quota or disabled — silently no-op. The user keeps the in-page
            // state but won't see it next visit.
        }
    }

    function isValid(entry) {
        return entry
            && typeof entry === "object"
            && typeof entry.query === "string"
            && entry.query.length > 0;
    }

    function makeId() {
        // Compact unique enough for the user's local list — no need for UUIDs.
        return "s" + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    }

    // ---------------------------------------------------------------------
    //  Public API
    // ---------------------------------------------------------------------

    function list() {
        return load().slice().sort(function (a, b) {
            return (b.saved_at || 0) - (a.saved_at || 0);
        });
    }

    function has(query) {
        return getByQuery(query) !== null;
    }

    function getByQuery(query) {
        const q = (query || "").trim();
        if (!q) return null;
        return load().find(function (e) { return e.query === q; }) || null;
    }

    function add(query, label) {
        const q = (query || "").trim();
        if (!q) return null;
        const arr = load();
        const existing = arr.find(function (e) { return e.query === q; });
        if (existing) {
            // Already saved — bump the timestamp + update label if supplied.
            existing.saved_at = Date.now();
            if (typeof label === "string") existing.label = label.trim();
            save(arr);
            notify();
            return existing;
        }
        const entry = {
            id: makeId(),
            label: (label || "").trim(),
            query: q,
            saved_at: Date.now(),
        };
        arr.push(entry);
        save(arr);
        notify();
        return entry;
    }

    function remove(id) {
        const arr = load().filter(function (e) { return e.id !== id; });
        save(arr);
        notify();
    }

    function removeByQuery(query) {
        const e = getByQuery(query);
        if (e) remove(e.id);
    }

    function rename(id, label) {
        const arr = load();
        const e = arr.find(function (x) { return x.id === id; });
        if (!e) return;
        e.label = (label || "").trim();
        save(arr);
        notify();
    }

    // ---------------------------------------------------------------------
    //  Notification — pages can listen for changes via window event
    // ---------------------------------------------------------------------

    function notify() {
        document.dispatchEvent(new CustomEvent("savedsearches:change", {
            detail: { count: load().length },
        }));
    }

    // ---------------------------------------------------------------------
    //  Dropdown UI
    // ---------------------------------------------------------------------

    /**
     * Mount the dropdown UI inside `panel`, wire `trigger` to open/close it.
     * The panel renders fresh on every open so it always shows the latest
     * list. The trigger gets aria-expanded toggled.
     */
    function wireDropdown(trigger, panel, opts) {
        if (!trigger || !panel) return;
        opts = opts || {};
        const baseUrl = opts.searchBaseUrl;       // e.g. "/search/"
        if (!baseUrl) {
            console.warn("savedSearches.wireDropdown: opts.searchBaseUrl is required");
            return;
        }

        function open() {
            renderPanel(panel, baseUrl);
            panel.hidden = false;
            trigger.setAttribute("aria-expanded", "true");
        }
        function close() {
            panel.hidden = true;
            trigger.setAttribute("aria-expanded", "false");
        }
        function toggle() {
            if (panel.hidden) open(); else close();
        }

        trigger.addEventListener("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggle();
        });
        document.addEventListener("click", function (e) {
            if (panel.hidden) return;
            if (panel.contains(e.target) || trigger.contains(e.target)) return;
            close();
        });
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && !panel.hidden) close();
        });
        document.addEventListener("savedsearches:change", function () {
            if (!panel.hidden) renderPanel(panel, baseUrl);
            updateTriggerCount(trigger);
        });

        updateTriggerCount(trigger);
    }

    function updateTriggerCount(trigger) {
        const n = load().length;
        const badge = trigger.querySelector("[data-saved-count]");
        if (badge) {
            badge.textContent = n > 0 ? n : "";
            badge.hidden = n === 0;
        }
        trigger.classList.toggle("saved-search-trigger--has-saved", n > 0);
    }

    function renderPanel(panel, baseUrl) {
        const items = list();
        panel.innerHTML = "";

        const header = document.createElement("div");
        header.className = "saved-search-panel__header";
        header.innerHTML =
            '<span class="saved-search-panel__title">Saved searches</span>' +
            '<span class="saved-search-panel__count">' + items.length + '</span>';
        panel.appendChild(header);

        if (items.length === 0) {
            const empty = document.createElement("p");
            empty.className = "saved-search-panel__empty";
            empty.textContent = "No saved searches yet. Run a search and click the bookmark icon to save it.";
            panel.appendChild(empty);
            return;
        }

        const list_el = document.createElement("ul");
        list_el.className = "saved-search-panel__list";
        items.forEach(function (e) {
            const li = document.createElement("li");
            li.className = "saved-search-panel__item";
            li.dataset.id = e.id;

            const link = document.createElement("a");
            link.className = "saved-search-panel__link";
            link.href = baseUrl + encodeURIComponent(e.query);
            link.title = "Run search: " + e.query;
            const labelText = e.label || e.query;
            link.innerHTML =
                '<span class="saved-search-panel__label">' + escapeHtml(labelText) + '</span>' +
                (e.label ? '<span class="saved-search-panel__query">' + escapeHtml(e.query) + '</span>' : '');
            li.appendChild(link);

            const actions = document.createElement("div");
            actions.className = "saved-search-panel__actions";

            const renameBtn = document.createElement("button");
            renameBtn.type = "button";
            renameBtn.className = "saved-search-panel__action";
            renameBtn.title = "Rename";
            renameBtn.setAttribute("aria-label", "Rename saved search");
            renameBtn.innerHTML = '<i class="bi bi-pencil" aria-hidden="true"></i>';
            renameBtn.addEventListener("click", function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                const next = window.prompt("Rename this saved search:", e.label || "");
                if (next === null) return;
                rename(e.id, next);
            });
            actions.appendChild(renameBtn);

            const delBtn = document.createElement("button");
            delBtn.type = "button";
            delBtn.className = "saved-search-panel__action saved-search-panel__action--danger";
            delBtn.title = "Delete";
            delBtn.setAttribute("aria-label", "Delete saved search");
            delBtn.innerHTML = '<i class="bi bi-trash" aria-hidden="true"></i>';
            delBtn.addEventListener("click", function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                if (window.confirm("Delete saved search “" + (e.label || e.query) + "”?")) {
                    remove(e.id);
                }
            });
            actions.appendChild(delBtn);

            li.appendChild(actions);
            list_el.appendChild(li);
        });
        panel.appendChild(list_el);
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    // ---------------------------------------------------------------------
    //  Save-toggle button (used on /search page header)
    // ---------------------------------------------------------------------

    /**
     * Wire a single button so its icon reflects whether `query` is saved,
     * and clicking toggles the saved state. Optional second arg sets the
     * default label prompt (only shown on save, not unsave).
     */
    function wireSaveToggle(button, query) {
        if (!button || !query) return;
        function refresh() {
            const saved = has(query);
            button.classList.toggle("is-saved", saved);
            button.title = saved ? "Saved — click to remove" : "Save this search";
            button.setAttribute("aria-pressed", saved ? "true" : "false");
            const icon = button.querySelector("i");
            if (icon) {
                icon.classList.toggle("bi-bookmark-fill", saved);
                icon.classList.toggle("bi-bookmark", !saved);
            }
            const label = button.querySelector("[data-save-label]");
            if (label) label.textContent = saved ? "Saved" : "Save";
        }

        button.addEventListener("click", function () {
            if (has(query)) {
                removeByQuery(query);
            } else {
                const proposed = window.prompt("Optional label for this saved search (leave blank to use the query):", "");
                if (proposed === null) return;  // canceled
                add(query, proposed || "");
            }
            refresh();
        });
        document.addEventListener("savedsearches:change", refresh);
        refresh();
    }

    // ---------------------------------------------------------------------
    //  Export
    // ---------------------------------------------------------------------

    window.savedSearches = {
        list: list,
        has: has,
        getByQuery: getByQuery,
        add: add,
        remove: remove,
        removeByQuery: removeByQuery,
        rename: rename,
        wireDropdown: wireDropdown,
        wireSaveToggle: wireSaveToggle,
    };
})();
