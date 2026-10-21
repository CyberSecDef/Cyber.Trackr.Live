// End-to-end: Scans to Reports must render uploaded scan metadata as text.
// Security review S4: the summary DataTable wrote HOST_NAME, HOST_IP and the
// file name through innerHTML, so a crafted .ckl ran script on this origin
// with every other loaded scan file in reach.
const { test, expect } = require('@playwright/test');

const HOST_PAYLOAD = '<img src=x onerror="window.__xssHost=1">';
const IP_PAYLOAD = '<img src=x onerror="window.__xssIp=1">';
const FILE_PAYLOAD = '<img src=x onerror=window.__xssFile=1>.ckl';

const xmlEscape = (s) => s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

// Smallest CKL the parser accepts: an ASSET, the STIG_INFO fields it reads,
// and one open high-severity VULN.
const ckl = `<?xml version="1.0" encoding="UTF-8"?>
<CHECKLIST>
  <ASSET>
    <HOST_NAME>${xmlEscape(HOST_PAYLOAD)}</HOST_NAME>
    <HOST_IP>${xmlEscape(IP_PAYLOAD)}</HOST_IP>
  </ASSET>
  <STIGS>
    <iSTIG>
      <STIG_INFO>
        <SI_DATA><SID_NAME>version</SID_NAME><SID_DATA>1</SID_DATA></SI_DATA>
        <SI_DATA><SID_NAME>stigid</SID_NAME><SID_DATA>E2E_Test_STIG</SID_DATA></SI_DATA>
        <SI_DATA><SID_NAME>releaseinfo</SID_NAME><SID_DATA>Release: 1 Benchmark Date: 01 Jan 2026</SID_DATA></SI_DATA>
        <SI_DATA><SID_NAME>title</SID_NAME><SID_DATA>E2E Test STIG</SID_DATA></SI_DATA>
      </STIG_INFO>
      <VULN>
        <STIG_DATA><VULN_ATTRIBUTE>Vuln_Num</VULN_ATTRIBUTE><ATTRIBUTE_DATA>V-1</ATTRIBUTE_DATA></STIG_DATA>
        <STIG_DATA><VULN_ATTRIBUTE>Severity</VULN_ATTRIBUTE><ATTRIBUTE_DATA>high</ATTRIBUTE_DATA></STIG_DATA>
        <STIG_DATA><VULN_ATTRIBUTE>Rule_Title</VULN_ATTRIBUTE><ATTRIBUTE_DATA>E2E rule</ATTRIBUTE_DATA></STIG_DATA>
        <STATUS>Open</STATUS>
        <FINDING_DETAILS></FINDING_DETAILS>
        <COMMENTS></COMMENTS>
      </VULN>
    </iSTIG>
  </STIGS>
</CHECKLIST>`;

test('crafted CKL metadata renders as text in the scan summary', async ({ page }) => {
    await page.goto('/report_generator');

    await page.locator('#fileElem').setInputFiles({
        name: FILE_PAYLOAD,
        mimeType: 'application/xml',
        buffer: Buffer.from(ckl),
    });
    await expect(page.locator('#scans2poamParse')).toBeEnabled();
    await page.locator('#scans2poamParse').click();

    const row = page.locator('#scanSummary tbody tr').first();
    await expect(row.locator('td').nth(1)).toHaveText(HOST_PAYLOAD);
    await expect(row.locator('td').nth(2)).toHaveText(IP_PAYLOAD);
    await expect(row.locator('td').nth(4)).toHaveText(FILE_PAYLOAD);
    await expect(row.locator('td').nth(5)).toHaveText('1'); // CAT I count still renders

    // An injected <img src=x> fails to load at once; give onerror a beat to fire.
    await page.waitForTimeout(500);
    const fired = await page.evaluate(() => [window.__xssHost, window.__xssIp, window.__xssFile]);
    expect(fired).toEqual([undefined, undefined, undefined]);
    await expect(page.locator('#scanSummary tbody img')).toHaveCount(0);
});
