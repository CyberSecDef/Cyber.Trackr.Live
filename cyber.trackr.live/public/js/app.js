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
};

$(document).ready(() => {
    window.app.init();
});
