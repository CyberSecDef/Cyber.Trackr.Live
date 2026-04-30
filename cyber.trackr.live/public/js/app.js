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
};

$(document).ready(() => {
    window.app.init();
});
