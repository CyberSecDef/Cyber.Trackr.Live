// End-to-end: the Content-Security-Policy (review S9) must not break the site.
// The browser enforces the real header here, so any blocked script, style,
// worker, fetch or form shows up as a securitypolicyviolation event.
const { test, expect } = require('@playwright/test');

const ASD = 'Application_Security_and_Development';

// Collect violations from the moment each document starts.
test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
        window.__csp = [];
        document.addEventListener('securitypolicyviolation', (e) => {
            window.__csp.push(`${e.effectiveDirective} blocked ${e.blockedURI || '(inline)'} at ${e.sourceFile}:${e.lineNumber}`);
        });
    });
});

async function expectNoViolations(page) {
    const v = await page.evaluate(() => window.__csp || []);
    expect(v, v.join('\n')).toEqual([]);
}

const PAGES = [
    '/', '/stig', '/scap', '/stig/bulk', '/cci', '/rmf/4', '/rmf/5', '/rmf/4-to-5',
    '/baselines', '/plans', '/plans/ac', '/vulnerabilities', '/vulnerabilities/kev',
    '/search/rhel', '/contactus', '/changelog', '/ckl-viewer', '/report_generator',
    `/stig/${ASD}/6/4`, `/stig/${ASD}/4/8/6/4`, `/stig-wizard/${ASD}/6/4`,
    '/scap/Red_Hat_Enterprise_Linux_8/1/2',
];

for (const path of PAGES) {
    test(`no CSP violations on ${path}`, async ({ page }) => {
        const resp = await page.goto(path, { waitUntil: 'networkidle' });
        expect(resp.status()).toBe(200);
        expect(resp.headers()['content-security-policy']).toContain("frame-ancestors 'none'");
        await expectNoViolations(page);
    });
}

test('STIG view loads markdown-it from the allowed CDN', async ({ page }) => {
    await page.goto(`/stig/${ASD}/6/4`, { waitUntil: 'networkidle' });
    expect(await page.evaluate(() => typeof window.markdownit)).toBe('function');
    await expectNoViolations(page);
});

test('report generator: worker, CKL parse and XLSX export run under the CSP', async ({ page }) => {
    const ckl = `<?xml version="1.0" encoding="UTF-8"?><CHECKLIST><ASSET><HOST_NAME>host1</HOST_NAME><HOST_IP>10.0.0.1</HOST_IP></ASSET>
<STIGS><iSTIG><STIG_INFO>
<SI_DATA><SID_NAME>version</SID_NAME><SID_DATA>1</SID_DATA></SI_DATA>
<SI_DATA><SID_NAME>stigid</SID_NAME><SID_DATA>E2E_Test_STIG</SID_DATA></SI_DATA>
<SI_DATA><SID_NAME>releaseinfo</SID_NAME><SID_DATA>Release: 1 Benchmark Date: 01 Jan 2026</SID_DATA></SI_DATA>
<SI_DATA><SID_NAME>title</SID_NAME><SID_DATA>E2E Test STIG</SID_DATA></SI_DATA>
</STIG_INFO><VULN>
<STIG_DATA><VULN_ATTRIBUTE>Vuln_Num</VULN_ATTRIBUTE><ATTRIBUTE_DATA>V-1</ATTRIBUTE_DATA></STIG_DATA>
<STIG_DATA><VULN_ATTRIBUTE>Severity</VULN_ATTRIBUTE><ATTRIBUTE_DATA>high</ATTRIBUTE_DATA></STIG_DATA>
<STIG_DATA><VULN_ATTRIBUTE>Rule_Title</VULN_ATTRIBUTE><ATTRIBUTE_DATA>E2E rule</ATTRIBUTE_DATA></STIG_DATA>
<STATUS>Open</STATUS><FINDING_DETAILS></FINDING_DETAILS><COMMENTS></COMMENTS>
</VULN></iSTIG></STIGS></CHECKLIST>`;

    await page.goto('/report_generator', { waitUntil: 'networkidle' });
    await page.locator('#fileElem').setInputFiles({ name: 'host1.ckl', mimeType: 'application/xml', buffer: Buffer.from(ckl) });
    await expect(page.locator('#scans2poamParse')).toBeEnabled();
    await page.locator('#scans2poamParse').click();
    await expect(page.locator('#scanSummary tbody tr td').nth(1)).toHaveText('host1');

    const download = page.waitForEvent('download', { timeout: 20000 });
    await page.locator('#scans2poamExecute').click();
    expect((await download).suggestedFilename()).toMatch(/\.xlsx$/i);
    await expectNoViolations(page);
});

test('bulk download: plan + chunk fetch and blob download run under the CSP', async ({ page }) => {
    await page.goto('/stig/bulk', { waitUntil: 'networkidle' });
    await page.locator('input[data-bulk-row="xml"]').first().check();

    const download = page.waitForEvent('download', { timeout: 20000 });
    await page.locator('[data-bulk-download]').click();
    expect((await download).suggestedFilename()).toMatch(/\.zip$/i);
    await expectNoViolations(page);
});

test('plan wizard: DOCX download runs under the CSP', async ({ page }) => {
    await page.goto('/plans/ac', { waitUntil: 'networkidle' });

    const download = page.waitForEvent('download', { timeout: 30000 });
    await page.evaluate(() => window.planWizard.download());
    expect((await download).suggestedFilename()).toMatch(/\.docx$/i);
    await expectNoViolations(page);
});

test('the site refuses to be framed', async ({ page }) => {
    await page.goto('/robots.txt');
    const target = new URL('/', page.url()).href;
    await page.setContent(`<iframe id="f" src="${target}"></iframe>`);
    await page.waitForTimeout(2000);
    const frame = await (await page.locator('#f').elementHandle()).contentFrame();
    // Blocked by frame-ancestors / X-Frame-Options, Chromium shows its error
    // page instead of the app (unblocked, this is http://127.0.0.1:8123/ with
    // title "Cyber Trackr!").
    expect(frame.url()).toMatch(/^chrome-error:/);
    expect(await frame.title()).not.toContain('Cyber Trackr');
});
