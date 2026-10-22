// Scans to Reports: the Parse button must only be clickable when a click will
// actually parse, i.e. no file is still being read, no parse is running, and
// every listed file is waiting. Real reads finish too fast to race reliably,
// so FileReader is wrapped: files named slow-* finish 1.5 s late, files named
// unreadable-* fail the way an unreadable file does (result null).
const { test, expect } = require('@playwright/test');
const path = require('path');

const FIX = path.join(__dirname, 'fixtures', 'report-generator');
const fixture = (name, as = name) => ({ name: as, mimeType: 'application/octet-stream', buffer: require('fs').readFileSync(path.join(FIX, name)) });

test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
        const read = FileReader.prototype.readAsDataURL;
        FileReader.prototype.readAsDataURL = function (file) {
            if (file.name.startsWith('unreadable-')) {
                Object.defineProperty(this, 'result', { get: () => null });
                setTimeout(() => this.onloadend && this.onloadend(), 50);
            } else if (file.name.startsWith('slow-')) {
                setTimeout(() => read.call(this, file), 1500);
            } else {
                read.call(this, file);
            }
        };
    });
    await page.goto('/report_generator');
});

const parseBtn = (page) => page.locator('#scans2poamParse');
const statuses = (page) => page.locator('table#scanFiles tbody tr td:nth-child(6)');
const upload = (page, files) => page.locator('#fileElem').setInputFiles(files);

test('Parse stays disabled until every file in a batch is read', async ({ page }) => {
    await upload(page, [fixture('host-ckl.ckl'), fixture('scan.nessus', 'slow-scan.nessus')]);
    await expect(statuses(page).first()).toHaveText('Pending');   // the fast file is done...
    await expect(parseBtn(page)).toBeDisabled();                  // ...but the slow one isn't
    await expect(parseBtn(page)).toBeEnabled({ timeout: 5000 });

    await parseBtn(page).click();
    await expect(statuses(page)).toHaveText(['Completed', 'Completed']);
    await expect(page.locator('#scanSummary tbody tr')).toHaveCount(3);
});

test('adding a second batch disables Parse again until it is read', async ({ page }) => {
    await upload(page, fixture('host-ckl.ckl'));
    await expect(parseBtn(page)).toBeEnabled();

    await upload(page, fixture('host-cklb.cklb', 'slow-host.cklb'));
    await expect(parseBtn(page)).toBeDisabled();
    await expect(parseBtn(page)).toBeEnabled({ timeout: 5000 });

    await parseBtn(page).click();
    await expect(statuses(page)).toHaveText(['Completed', 'Completed']);
    await expect(page.locator('#scanSummary tbody tr')).toHaveCount(2);
});

test('Parse is disabled once everything is parsed (a click would do nothing)', async ({ page }) => {
    await upload(page, fixture('host-ckl.ckl'));
    await parseBtn(page).click();
    await expect(statuses(page)).toHaveText(['Completed']);
    await expect(parseBtn(page)).toBeDisabled();
});

test('a file removed while still reading is not parsed', async ({ page }) => {
    await upload(page, [fixture('host-ckl.ckl'), fixture('scan.nessus', 'slow-scan.nessus')]);
    await page.locator('table#scanFiles tbody tr', { hasText: 'slow-scan.nessus' }).locator('span', { hasText: '[X]' }).click();
    await expect(parseBtn(page)).toBeEnabled();
    await page.waitForTimeout(2000); // let the removed file's read finish

    await expect(page.locator('table#scanFiles tbody tr')).toHaveCount(1);
    expect(await page.evaluate(() => scansToPoam.fileArray.map((f) => f.name))).toEqual(['host-ckl.ckl']);
    await parseBtn(page).click();
    await expect(page.locator('#scanSummary tbody tr')).toHaveCount(1);
});

test('an unreadable file is dropped with a message instead of breaking Parse', async ({ page }) => {
    await upload(page, [fixture('host-ckl.ckl'), fixture('host-cklb.cklb', 'unreadable-host.cklb')]);
    await expect(page.locator('#alertWindow p')).toContainText('Could not read unreadable-host.cklb');
    await expect(page.locator('table#scanFiles tbody tr')).toHaveCount(1);
    await expect(parseBtn(page)).toBeEnabled();

    await parseBtn(page).click();
    await expect(statuses(page)).toHaveText(['Completed']);
});
