// End-to-end: the "Generate Mitigation Statement" button (review S8).
// The e2e server has no MITIGATION_SCRIPT, so the API answers
// mitigation-status "unavailable". The button used to stay disabled on
// "Please wait....generating statement" forever whenever no statement came
// back, and it called the production API by absolute URL.
const { test, expect } = require('@playwright/test');

const ASD_VIEW = '/stig/Application_Security_and_Development/6/4';

test('mitigation button reports "unavailable", re-enables, and calls the local API', async ({ page }) => {
    await page.goto(ASD_VIEW);

    const button = page.getByRole('button', { name: 'Generate Mitigation Statement' }).first();
    const output = button.locator('xpath=ancestor::h6[1]/following-sibling::p[1]');

    const apiCall = page.waitForRequest((req) => req.url().includes('/api/stig/'));
    await button.evaluate((b) => b.click()); // rule details start collapsed
    const req = await apiCall;

    expect(new URL(req.url()).host).toBe('127.0.0.1:8123');
    await expect(output).toHaveText("A mitigation statement isn't available for this rule right now.");
    await expect(button).toBeEnabled();
});

// S19: a title containing "&" was HTML-escaped to "&amp;" inside the page's
// JS, so this STIG's mitigation call went to a URL that 404s.
test('mitigation call works for a title containing "&"', async ({ page }) => {
    await page.goto('/stig/' + encodeURIComponent('Intune_MDM_Service_Desktop_&_Mobile') + '/1/1');

    const button = page.getByRole('button', { name: 'Generate Mitigation Statement' }).first();
    const apiCall = page.waitForResponse((r) => r.url().includes('/api/stig/'));
    await button.evaluate((b) => b.click());
    const resp = await apiCall;

    expect(decodeURIComponent(new URL(resp.url()).pathname)).toContain('/api/stig/Intune_MDM_Service_Desktop_&_Mobile/1/1/');
    expect(resp.status()).toBe(200);
    expect((await resp.json())['mitigation-status']).toBe('unavailable'); // e2e has no MITIGATION_SCRIPT
});
