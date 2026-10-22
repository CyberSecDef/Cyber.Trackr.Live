// Scans to Reports reference data (review P5): 5.7 MB used to be inlined into
// every view of /report_generator. It now lives in /js/report-generator-data.js
// and is only fetched once someone adds files; Execute waits for it.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const DATA = '/js/report-generator-data.js';
const ckl = () => ({
    name: 'host.ckl',
    mimeType: 'application/xml',
    buffer: fs.readFileSync(path.join(__dirname, 'fixtures', 'report-generator', 'host-ckl.ckl')),
});

test('a plain visit is small and does not download the reference data', async ({ page }) => {
    const dataRequests = [];
    page.on('request', (r) => { if (r.url().includes(DATA)) dataRequests.push(r.url()); });

    const resp = await page.goto('/report_generator', { waitUntil: 'networkidle' });
    expect((await resp.body()).length).toBeLessThan(400000); // was ~5.9 MB
    expect(dataRequests).toEqual([]);
});

test('adding a file loads the data once; Execute uses it', async ({ page }) => {
    const dataRequests = [];
    page.on('request', (r) => { if (r.url().includes(DATA)) dataRequests.push(r.url()); });
    await page.goto('/report_generator');

    await page.locator('#fileElem').setInputFiles(ckl());
    await expect.poll(() => page.evaluate(() => Array.isArray(scansToPoam.rmf_cci) && scansToPoam.rmf_cci.length)).toBe(2997);
    await page.locator('#scans2poamParse').click();

    const download = page.waitForEvent('download', { timeout: 30000 });
    await page.locator('#scans2poamExecute').click();
    expect((await download).suggestedFilename()).toMatch(/\.xlsx$/);
    expect(dataRequests).toHaveLength(1);
    expect(dataRequests[0]).toMatch(/\?v=/); // cache-busted
});

test('if the data cannot load, Execute says so instead of producing a broken workbook', async ({ page }) => {
    await page.route('**' + DATA + '*', (route) => route.abort());
    await page.goto('/report_generator');
    await page.locator('#fileElem').setInputFiles(ckl());
    await expect(page.locator('#scans2poamParse')).toBeEnabled();
    await page.locator('#scans2poamParse').click();

    let downloaded = false;
    page.on('download', () => { downloaded = true; });
    await page.locator('#scans2poamExecute').click();

    await expect(page.locator('#alertWindow p')).toContainText('Could not load the report reference data');
    await page.waitForTimeout(1500);
    expect(downloaded).toBe(false);
});
