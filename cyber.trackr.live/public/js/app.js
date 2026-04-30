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

        // Sync theme-toggle aria-pressed with the active theme on load.
        $(".theme-toggle").attr("aria-pressed", window.app.theme.get() === "dark" ? "true" : "false");

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

        // React to system theme changes only when the user hasn't set a preference.
        if (window.matchMedia) {
            try {
                window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", function (e) {
                    if (!localStorage.getItem("theme")) {
                        window.app.theme.set(e.matches ? "dark" : "light", { persist: false });
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
     * so .get() always returns the active theme on load.
     */
    theme: {
        get() {
            return document.documentElement.dataset.theme || "light";
        },
        set(theme, opts = { persist: true }) {
            document.documentElement.dataset.theme = theme;
            if (opts.persist) {
                try { localStorage.setItem("theme", theme); } catch (e) { /* ignore */ }
            }
            $(".theme-toggle").attr("aria-pressed", theme === "dark" ? "true" : "false");
        },
        toggle() {
            this.set(this.get() === "dark" ? "light" : "dark");
        },
    },
};

$(document).ready(() => {
    window.app.init();
});
