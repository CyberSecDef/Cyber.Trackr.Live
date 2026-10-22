// Review P10: the CCI table's 4.8 MB row data is revalidated by ETag, which
// only works if the browser asks for the same URL every time. DataTables
// defaults to jQuery's cache:false and appended ?_=<timestamp>.
const { test, expect } = require('@playwright/test');

test('the CCI table requests its data at a stable, cacheable URL', async ({ page }) => {
    const urls = [];
    page.on('request', (r) => { if (r.url().includes('/cci/data')) urls.push(new URL(r.url())); });

    for (let i = 0; i < 2; i++) {
        await page.goto('/cci', { waitUntil: 'networkidle' });
        await expect(page.locator('table#ccis tbody tr').first()).toBeVisible();
    }

    expect(urls).toHaveLength(2);
    for (const u of urls) {
        expect(u.pathname + u.search).toBe('/cci/data');
    }
});
