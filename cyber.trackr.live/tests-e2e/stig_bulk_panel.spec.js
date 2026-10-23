// /stig's "Bulk download" panel fetches its 4,031-row table the first time
// it is opened (it used to be embedded, hidden, in every /stig view). The
// table must still work there: selection, counts and the streamed download.
const { test, expect } = require('@playwright/test');

const TABLE = '/stig/bulk/table';

test('the panel loads the table once, on first open, and downloads a selection', async ({ page }) => {
    test.setTimeout(60000);
    const fetches = [];
    page.on('request', (r) => { if (new URL(r.url()).pathname === TABLE) fetches.push(r.url()); });

    const resp = await page.goto('/stig', { waitUntil: 'networkidle' });
    expect((await resp.body()).length).toBeLessThan(3000000); // was ~7.9 MB
    await expect(page.locator('[data-bulk-root]')).toHaveCount(0);
    expect(fetches).toEqual([]);

    const panel = page.locator('[data-bulk-panel]');
    await panel.locator('summary').click();
    const firstXml = panel.locator('input[data-bulk-row="xml"]:not([disabled])').first();
    await expect(firstXml).toBeVisible();
    expect(fetches).toHaveLength(1);
    await expect(page.locator('#stig-table')).toBeHidden(); // the main list hides while the panel is open

    // Close and reopen: no second fetch.
    await panel.locator('summary').click();
    await expect(page.locator('#stig-table')).toBeVisible();
    await panel.locator('summary').click();
    await expect(firstXml).toBeVisible();
    expect(fetches).toHaveLength(1);

    await firstXml.check();
    await expect(panel.locator('[data-bulk-count-files]')).toHaveText('1');
    const download = page.waitForEvent('download', { timeout: 30000 });
    await panel.locator('[data-bulk-download]').click();
    expect((await download).suggestedFilename()).toMatch(/\.zip$/);
});

test('if the table cannot load, the panel links to the full page and retries on reopen', async ({ page }) => {
    let fail = true;
    await page.route('**' + TABLE, (route) => (fail ? route.abort() : route.continue()));
    await page.goto('/stig');

    const panel = page.locator('[data-bulk-panel]');
    await panel.locator('summary').click();
    await expect(panel.locator('[data-bulk-slot]')).toContainText('Could not load the download list');
    await expect(panel.locator('[data-bulk-slot] a[href="/stig/bulk"]')).toBeVisible();

    fail = false;
    await panel.locator('summary').click();
    await panel.locator('summary').click();
    await expect(panel.locator('input[data-bulk-row="xml"]').first()).toBeAttached();
});
