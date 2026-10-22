// DataTables (2.7 MB JS / 1.1 MB gzipped incl. pdfmake + JSZip) is only
// loaded by pages that build a table (review P6). Before, every page loaded
// it in <head>. A page that starts using DataTables must opt in with the
// datatables_css / datatables_js blocks; this fails if it forgets (JS error)
// or if a page without a table loads it again.
const { test, expect } = require('@playwright/test');

const ASD = 'Application_Security_and_Development';
const WITH_TABLES = {
    '/cci': true, '/rmf/4-to-5': true, '/vulnerabilities/kev': true, '/stig/bulk': true,
    '/stig': false, '/scap/Red_Hat_Enterprise_Linux_8/1/2': false, '/report_generator': false, // table built later or on interaction
};
const WITHOUT = ['/', `/stig/${ASD}/6/4`, `/stig/${ASD}/4/8/6/4`, '/search/ssh', '/rmf/5', '/plans', '/plans/ac',
    '/baselines', '/vulnerabilities', '/ckl-viewer', '/contactus', `/stig-wizard/${ASD}/6/4`];

async function visit(page, url) {
    const errors = [];
    const loaded = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('request', (r) => { if (/datatables\.min\.(js|css)/.test(r.url())) loaded.push(new URL(r.url()).pathname.split('/').pop()); });
    await page.goto(url, { waitUntil: 'networkidle' });
    return { errors, loaded };
}

for (const [url, buildsOnLoad] of Object.entries(WITH_TABLES)) {
    test(`loads DataTables: ${url}`, async ({ page }) => {
        const { errors, loaded } = await visit(page, url);
        expect(errors).toEqual([]);
        expect(loaded.sort()).toEqual(['datatables.min.css', 'datatables.min.js']);
        expect(await page.evaluate(() => typeof jQuery.fn.dataTable)).toBe('function');
        if (buildsOnLoad) await expect(page.locator('.dataTables_wrapper').first()).toBeVisible();
    });
}

for (const url of WITHOUT) {
    test(`does not load DataTables: ${url}`, async ({ page }) => {
        const { errors, loaded } = await visit(page, url);
        expect(errors).toEqual([]);
        expect(loaded).toEqual([]);
    });
}
