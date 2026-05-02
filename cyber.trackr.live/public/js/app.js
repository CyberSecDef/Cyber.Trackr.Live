/*
 * Cyber Trackr — shared application JS
 *
 * Loaded after jQuery, Bootstrap, DataTables. Holds shared utilities used
 * across pages. Per-page scripts (stig_app, cci_app, scap_app, etc.) live
 * inside their templates and may depend on this module.
 *
 * Server-rendered route URLs are exposed on `window.routes` from base.html.twig
 * because Twig's `path()` helper isn't available in static JS files.
 *
 * Future additions per TODO.md:
 *   - Theme toggle (§5.1)
 *   - Freshness / relTime utilities (§2.4)
 */

window.app = {
    init() {
        $("#search_terms").on("keyup", function (event) {
            if (event.key === "Enter") {
                window.app.search();
            }
        });

        // Mark the active theme menu item on load.
        window.app.theme.syncMenuChecks();

        // Click outside the theme menu closes it.
        $(document).on("click.themeMenu", function (e) {
            if (!$(e.target).closest(".theme-menu").length) {
                window.app.theme.menuClose();
            }
        });

        // Escape closes the theme menu.
        $(document).on("keydown.themeMenu", function (e) {
            if (e.key === "Escape") {
                window.app.theme.menuClose();
            }
        });

        // Click outside the 800-53 nav dropdown closes it. Uses the native
        // <details> 'open' attribute so no extra state to reconcile.
        $(document).on("click.siteNavMenu", function (e) {
            $(".site-nav__menu[open]").each(function () {
                if (!$(e.target).closest(this).length) {
                    this.removeAttribute("open");
                }
            });
        });
        $(document).on("keydown.siteNavMenu", function (e) {
            if (e.key === "Escape") {
                $(".site-nav__menu[open]").each(function () { this.removeAttribute("open"); });
            }
        });

        // Cmd/Ctrl + K — focus the header search input from anywhere on the page.
        $(document).on("keydown", function (e) {
            if ((e.metaKey || e.ctrlKey) && (e.key === "k" || e.key === "K")) {
                const $input = $("#search_terms");
                if ($input.length) {
                    e.preventDefault();
                    $input.trigger("focus").trigger("select");
                }
            }
        });

        // Make sortable headers keyboard-focusable so screen-reader users
        // can navigate to them and Enter/Space activates the same sort handler.
        $(".data-table thead th[data-sort-key]").attr("tabindex", "0");

        $(".data-table thead th[data-sort-key]").on("keydown", function (e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                $(this).trigger("click");
            }
        });

        // Generic sortable header handler — any th[data-sort-key] inside any
        // table that has a registered handler in app.tableSortHandlers.
        $(".data-table thead th[data-sort-key]").on("click", function () {
            const $th = $(this);
            const $table = $th.closest("table");
            const tableId = $table.attr("id");
            const handler = window.app.tableSortHandlers[tableId];
            if (!handler) return;

            const field = $th.data("sort-key");
            const type = $th.data("sort-type") || "string";
            const wasSorted = $th.hasClass("sorted");
            const newDir = wasSorted && $th.hasClass("desc") ? "asc" : "desc";
            $table.find("thead th")
                .removeClass("sorted asc desc")
                .attr("aria-sort", "none")
                .find(".sort-ind").text("");
            $th.addClass("sorted " + newDir)
               .attr("aria-sort", newDir === "asc" ? "ascending" : "descending")
               .find(".sort-ind").text(newDir === "asc" ? "↑" : "↓");
            handler(field, type, newDir);
        });

        // Initialize stig list pagination on /stig.
        if ($("#stig-table").length) {
            window.app.stigList.apply();
        }
        // Initialize scap list pagination on /scap.
        if ($("#scap-table").length) {
            window.app.scapList.apply();
        }
        // Initialize RMF v5 baseline filter when present.
        if ($("#rmf-page-info").length) {
            window.app.rmfList.apply();
        }

        // React to system theme changes only when the user hasn't set a preference.
        if (window.matchMedia) {
            try {
                window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", function (e) {
                    if (!localStorage.getItem("theme")) {
                        window.app.theme.set(e.matches ? "ink" : "paper", { persist: false });
                    }
                });
            } catch (err) { /* older browsers without addEventListener on MQL */ }
        }
    },

    search() {
        const query = $("#search_terms").val();
        if (!query) return;
        window.location.href = window.routes.search.replace("REPLACE_THIS", encodeURIComponent(query));
    },

    /** Hero search form submit (homepage). Distinct from the header search. */
    heroSubmit(event) {
        event.preventDefault();
        const q = ($("#hero-search-input").val() || "").trim();
        if (!q) return false;
        window.location.href = window.routes.search.replace("REPLACE_THIS", encodeURIComponent(q));
        return false;
    },

    /** Pre-filled "Try" chip → submit immediately. */
    heroSearchFor(query) {
        if (!query) return;
        window.location.href = window.routes.search.replace("REPLACE_THIS", encodeURIComponent(query));
    },

    /** Mobile nav hamburger — toggles the .is-open class on the nav panel. */
    navToggle(btn) {
        const $nav = $("#site-nav");
        const open = !$nav.hasClass("is-open");
        $nav.toggleClass("is-open", open);
        $(btn).attr("aria-expanded", open ? "true" : "false");
    },

    /**
     * Registry of per-table sort callbacks. Keyed by the <table> element id;
     * value is `(field, type, dir) => void`. The generic header click handler
     * in app.init() looks up the table by id and dispatches to the right
     * callback so multiple managed tables can share one binding.
     */
    tableSortHandlers: {
        "recent-stigs-table": (f, t, d) => window.app.recent.sort(f, t, d),
        "stig-table":         (f, t, d) => window.app.stigList.sort(f, t, d),
        "scap-table":         (f, t, d) => window.app.scapList.sort(f, t, d),
    },

    /**
     * Recent-STIGs table — homepage. Combines text-substring filter on
     * name+version with an age-bucket filter (.chip-group). Refreshes
     * the "Showing N of M" footer label after every change.
     */
    recent: {
        ageFilter: "all",
        apply() {
            const q = ($("#recent-stigs-q").val() || "").toLowerCase().trim();
            const age = this.ageFilter;
            const $rows = $("#recent-stigs-table tbody tr");
            let visible = 0;
            $rows.each(function () {
                const $row = $(this);
                const name = ($row.data("name") || "").toString();
                const version = ($row.data("version") || "").toString().toLowerCase();
                const rowAge = ($row.data("age") || "old").toString();
                const matchesQuery = !q || name.indexOf(q) >= 0 || version.indexOf(q) >= 0;
                const matchesAge = age === "all" || rowAge === age;
                const show = matchesQuery && matchesAge;
                $row.toggleClass("is-hidden", !show);
                if (show) visible++;
            });
            const $count = $("#recent-stigs-count");
            const total = $count.data("total") || $rows.length;
            $count.text("Showing " + visible + " of " + Number(total).toLocaleString() + " STIGs");
        },
        sort(field, type, dir) {
            const $tbody = $("#recent-stigs-table tbody");
            const rows = $tbody.find("tr").toArray();
            rows.sort((a, b) => {
                if (type === "date") {
                    const av = new Date($(a).data("released") || 0).getTime() || 0;
                    const bv = new Date($(b).data("released") || 0).getTime() || 0;
                    return dir === "asc" ? av - bv : bv - av;
                }
                if (type === "number") {
                    const av = +($(a).data(field) || 0);
                    const bv = +($(b).data(field) || 0);
                    return dir === "asc" ? av - bv : bv - av;
                }
                const av = ($(a).data(field) || "").toString();
                const bv = ($(b).data(field) || "").toString();
                return dir === "asc" ? av.localeCompare(bv) : bv.localeCompare(av);
            });
            $tbody.empty().append(rows);
        },
    },

    recentFilter() {
        window.app.recent.apply();
    },

    recentAge(elem) {
        const $el = $(elem);
        $el.parent().find(".chip").removeClass("active");
        $el.addClass("active");
        window.app.recent.ageFilter = ($el.data("age") || "all").toString();
        window.app.recent.apply();
    },

    /**
     * Full-library STIG list (/stig). Same filter pattern as the homepage
     * recent table, plus client-side pagination at 50 per page since the
     * full set is ~1,076 rows.
     */
    stigList: {
        pageSize: 50,
        currentPage: 1,
        ageFilter: "all",

        apply() {
            const q = ($("#stig-q").val() || "").toLowerCase().trim();
            const age = this.ageFilter;
            const $rows = $("#stig-table tbody tr");
            const matching = [];
            $rows.each(function () {
                const $row = $(this);
                const name = ($row.data("name") || "").toString();
                const version = ($row.data("version") || "").toString().toLowerCase();
                const rowAge = ($row.data("age") || "old").toString();
                const matchesQuery = !q || name.indexOf(q) >= 0 || version.indexOf(q) >= 0;
                const matchesAge = age === "all" || rowAge === age;
                if (matchesQuery && matchesAge) matching.push(this);
            });

            const total = matching.length;
            const totalPages = Math.max(1, Math.ceil(total / this.pageSize));
            if (this.currentPage > totalPages) this.currentPage = totalPages;
            if (this.currentPage < 1) this.currentPage = 1;

            const start = (this.currentPage - 1) * this.pageSize;
            const end = start + this.pageSize;
            const visibleSet = new Set(matching.slice(start, end));

            $rows.each(function () {
                $(this).toggleClass("is-hidden", !visibleSet.has(this));
            });

            $("#stig-page-info").text(
                "Page " + this.currentPage + " of " + totalPages +
                " · " + total.toLocaleString() + " STIGs"
            );
            $("#stig-prev").prop("disabled", this.currentPage <= 1);
            $("#stig-next").prop("disabled", this.currentPage >= totalPages);
        },

        sort(field, type, dir) {
            const $tbody = $("#stig-table tbody");
            const rows = $tbody.find("tr").toArray();
            rows.sort((a, b) => {
                if (type === "date") {
                    const av = new Date($(a).data("released") || 0).getTime() || 0;
                    const bv = new Date($(b).data("released") || 0).getTime() || 0;
                    return dir === "asc" ? av - bv : bv - av;
                }
                if (type === "number") {
                    const av = +($(a).data(field) || 0);
                    const bv = +($(b).data(field) || 0);
                    return dir === "asc" ? av - bv : bv - av;
                }
                const av = ($(a).data(field) || "").toString();
                const bv = ($(b).data(field) || "").toString();
                return dir === "asc" ? av.localeCompare(bv) : bv.localeCompare(av);
            });
            $tbody.empty().append(rows);
            this.currentPage = 1;
            this.apply();
        },

        filter() {
            this.currentPage = 1;
            this.apply();
        },

        age(elem) {
            const $el = $(elem);
            $el.parent().find(".chip").removeClass("active");
            $el.addClass("active");
            this.ageFilter = ($el.data("age") || "all").toString();
            this.currentPage = 1;
            this.apply();
        },

        prev() {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.apply();
                this.scrollToTable();
            }
        },

        next() {
            this.currentPage++;
            this.apply();
            this.scrollToTable();
        },

        scrollToTable() {
            const wrap = document.querySelector("#stig-table");
            if (wrap) wrap.scrollIntoView({ behavior: "smooth", block: "start" });
        },
    },

    /**
     * Full-library SCAP list (/scap). Same shape as stigList but operates
     * over the smaller (~91-title) SCAP toc. Drops Severity Mix and Rules
     * columns since the SCAP toc doesn't pre-compute sev counts.
     */
    scapList: {
        pageSize: 50,
        currentPage: 1,
        ageFilter: "all",

        apply() {
            const q = ($("#scap-q").val() || "").toLowerCase().trim();
            const age = this.ageFilter;
            const $rows = $("#scap-table tbody tr");
            const matching = [];
            $rows.each(function () {
                const $row = $(this);
                const name = ($row.data("name") || "").toString();
                const version = ($row.data("version") || "").toString().toLowerCase();
                const rowAge = ($row.data("age") || "old").toString();
                const matchesQuery = !q || name.indexOf(q) >= 0 || version.indexOf(q) >= 0;
                const matchesAge = age === "all" || rowAge === age;
                if (matchesQuery && matchesAge) matching.push(this);
            });

            const total = matching.length;
            const totalPages = Math.max(1, Math.ceil(total / this.pageSize));
            if (this.currentPage > totalPages) this.currentPage = totalPages;
            if (this.currentPage < 1) this.currentPage = 1;

            const start = (this.currentPage - 1) * this.pageSize;
            const end = start + this.pageSize;
            const visibleSet = new Set(matching.slice(start, end));

            $rows.each(function () {
                $(this).toggleClass("is-hidden", !visibleSet.has(this));
            });

            $("#scap-page-info").text(
                "Page " + this.currentPage + " of " + totalPages +
                " · " + total.toLocaleString() + " benchmarks"
            );
            $("#scap-prev").prop("disabled", this.currentPage <= 1);
            $("#scap-next").prop("disabled", this.currentPage >= totalPages);
        },

        sort(field, type, dir) {
            const $tbody = $("#scap-table tbody");
            const rows = $tbody.find("tr").toArray();
            rows.sort((a, b) => {
                if (type === "date") {
                    const av = new Date($(a).data("released") || 0).getTime() || 0;
                    const bv = new Date($(b).data("released") || 0).getTime() || 0;
                    return dir === "asc" ? av - bv : bv - av;
                }
                const av = ($(a).data(field) || "").toString();
                const bv = ($(b).data(field) || "").toString();
                return dir === "asc" ? av.localeCompare(bv) : bv.localeCompare(av);
            });
            $tbody.empty().append(rows);
            this.currentPage = 1;
            this.apply();
        },

        filter() {
            this.currentPage = 1;
            this.apply();
        },

        age(elem) {
            const $el = $(elem);
            $el.parent().find(".chip").removeClass("active");
            $el.addClass("active");
            this.ageFilter = ($el.data("age") || "all").toString();
            this.currentPage = 1;
            this.apply();
        },

        prev() {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.apply();
                this.scrollToTable();
            }
        },

        next() {
            this.currentPage++;
            this.apply();
            this.scrollToTable();
        },

        scrollToTable() {
            const wrap = document.querySelector("#scap-table");
            if (wrap) wrap.scrollIntoView({ behavior: "smooth", block: "start" });
        },
    },

    /**
     * RMF v5 controls list — baseline filter (/rmf/5).
     *
     * Each <section.control-card> carries `data-overlays="low moderate high"`
     * (server-rendered from App\Service\OverlayLoader). Clicking a chip
     * toggles `is-hidden` on cards whose data-overlays don't include the
     * selected baseline. "All" shows everything including controls in zero
     * baselines (deprecated / privacy-only / not-yet-baselined).
     */
    rmfList: {
        overlayFilter: "all",
        apply() {
            const f = this.overlayFilter;
            const $cards = $(".control-card");
            let visible = 0;
            $cards.each(function () {
                const $c = $(this);
                const overlays = ($c.attr("data-overlays") || "").trim().split(/\s+/).filter(Boolean);
                const show = f === "all" || overlays.indexOf(f) >= 0;
                $c.toggleClass("is-hidden", !show);
                if (show) visible++;
            });
            const $info = $("#rmf-page-info");
            const total = +($info.data("total") || $cards.length);
            $info.text("Showing " + visible.toLocaleString() + " of " + total.toLocaleString() + " controls");
        },
        filter(elem) {
            const $el = $(elem);
            $el.parent().find(".chip").removeClass("active");
            $el.addClass("active");
            this.overlayFilter = ($el.data("overlay") || "all").toString();
            this.apply();
        },
    },

    /**
     * Bucket a date by age into one of: fresh / stale / aged / old.
     * Mirrors the App\Twig\AppExtension::freshnessTag PHP filter so
     * client-side filtering (Group 4 STIG list age dropdown) matches
     * server-rendered tags exactly.
     */
    freshnessTag(dateStr) {
        if (!dateStr) return "old";
        const t = new Date(dateStr).getTime();
        if (isNaN(t)) return "old";
        const days = Math.floor((Date.now() - t) / 86400000);
        if (days <= 365) return "fresh";
        if (days <= 1095) return "stale";
        if (days <= 1825) return "aged";
        return "old";
    },

    /** Mirror of App\Twig\AppExtension::relTime. */
    relTime(dateStr) {
        if (!dateStr) return "unknown";
        const t = new Date(dateStr).getTime();
        if (isNaN(t)) return "unknown";
        const days = Math.floor((Date.now() - t) / 86400000);
        if (days < 14) return days + (days === 1 ? " day" : " days") + " ago";
        if (days < 60) {
            const w = Math.round(days / 7);
            return w + (w === 1 ? " week" : " weeks") + " ago";
        }
        if (days < 365) {
            const m = Math.round(days / 30);
            return m + (m === 1 ? " month" : " months") + " ago";
        }
        const y = days / 365;
        if (y < 10) return y.toFixed(1) + " years ago";
        return Math.floor(y) + " years ago";
    },

    /**
     * Theme management. The preflight script in base.html.twig sets
     * document.documentElement.dataset.theme synchronously to prevent FOUC,
     * so .get() always returns the active theme on load. Available themes
     * must mirror the THEMES array in base.html.twig and the [data-theme="*"]
     * blocks in app.css.
     */
    theme: {
        THEMES: ["paper", "ink", "mono", "solarized-light", "solarized-dark", "nord", "dracula", "high-contrast"],

        get() {
            return document.documentElement.dataset.theme || "paper";
        },
        set(theme, opts = { persist: true }) {
            if (this.THEMES.indexOf(theme) === -1) return;
            document.documentElement.dataset.theme = theme;
            if (opts.persist) {
                try { localStorage.setItem("theme", theme); } catch (e) { /* ignore */ }
            }
            this.syncMenuChecks();
            this.menuClose();
        },
        syncMenuChecks() {
            const active = this.get();
            $(".theme-menu__item").each(function () {
                const name = $(this).data("theme-name");
                $(this).attr("aria-checked", name === active ? "true" : "false");
            });
        },
        menuToggle(btn) {
            const $btn = $(btn);
            const $panel = $btn.siblings(".theme-menu__panel");
            const isOpen = !$panel.prop("hidden");
            if (isOpen) {
                this.menuClose();
            } else {
                $panel.prop("hidden", false);
                $btn.attr("aria-expanded", "true");
            }
        },
        menuClose() {
            $(".theme-menu__panel").prop("hidden", true);
            $(".theme-menu .theme-toggle").attr("aria-expanded", "false");
        },
    },
};

$(document).ready(() => {
    window.app.init();

    // Lazy-apply the paper-grain overlay after first paint so the inlined
    // SVG noise data URI doesn't push back the initial render. requestAnimationFrame
    // schedules it for the next paint cycle — the gap is imperceptible.
    requestAnimationFrame(() => {
        document.body.classList.add("grain");
    });
});
