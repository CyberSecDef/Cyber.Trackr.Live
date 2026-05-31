// End-to-end: the STIG wizard, all the way through to the CKLB viewer.
// This covers the one seam the PHPUnit + Node tests can't: the live
// sessionStorage handoff from /stig-wizard to /ckl-viewer in a real browser.
const { test, expect } = require('@playwright/test');

const ASD_VIEW = '/stig/Application_Security_and_Development/6/4';
const ASD_WIZARD = '/stig-wizard/Application_Security_and_Development/6/4';

// "Minimal utility app" answers — no accounts, no web, no DB, etc. — which
// the reducer turns into a large block of Not Applicable determinations.
const NO_QUESTIONS = [
    'has_user_accounts', 'uses_passwords', 'uses_pki', 'federated_external_users',
    'web_based', 'internet_accessible', 'uses_database', 'uses_soap_saml',
    'processes_xml', 'is_web_service', 'uses_mobile_code', 'supports_remote_access',
    'non_local_maintenance', 'transaction_based', 'audit_self_aggregation',
];

test('view page shows the wizard CTA linking to the wizard', async ({ page }) => {
    await page.goto(ASD_VIEW);
    const cta = page.locator('.stig-wizard-cta');
    await expect(cta).toBeVisible();
    await expect(cta.locator('a')).toHaveAttribute('href', ASD_WIZARD);
});

test('wizard renders the question set from the schema', async ({ page }) => {
    await page.goto(ASD_WIZARD);
    await expect(page.locator('input[data-q="has_user_accounts"]')).toHaveCount(2);
    await expect(page.locator('select[data-q="data_classification"]')).toBeVisible();
});

test('action buttons appear above and below the questions', async ({ page }) => {
    await page.goto(ASD_WIZARD);
    await expect(page.locator('.stig-wizard__generate')).toHaveCount(2);
    await expect(page.locator('.stig-wizard__actions--bottom .stig-wizard__generate')).toBeVisible();
    await expect(page.locator('.stig-wizard__actions--bottom').getByText('Save answers')).toBeVisible();
    await expect(page.locator('.stig-wizard__actions--bottom').getByText('Load answers')).toBeVisible();
});

test('conditional follow-ups reveal only when the parent answer fires', async ({ page }) => {
    await page.goto(ASD_WIZARD);
    const rolesFollowup = page.locator('.stig-wizard__reveal[data-parent="has_user_accounts"][data-key="yes"]');
    await expect(rolesFollowup).toBeHidden();
    await page.check('input[data-q="has_user_accounts"][value="yes"]');
    await expect(rolesFollowup).toBeVisible();
    await page.check('input[data-q="has_user_accounts"][value="no"]');
    await expect(rolesFollowup).toBeHidden();
});

test('full flow: answer -> generate -> viewer loads the generated checklist', async ({ page }) => {
    await page.goto(ASD_VIEW);
    await page.locator('.stig-wizard-cta a').click();
    await expect(page).toHaveURL(/\/stig-wizard\//);

    for (const id of NO_QUESTIONS) {
        await page.check(`input[data-q="${id}"][value="no"]`);
    }
    await page.selectOption('select[data-q="data_classification"]', 'Unclassified (no CUI)');
    await page.selectOption('select[data-q="software_origin"]', 'COTS / third-party');
    await page.fill('[data-q="system_name"]', 'Acme Log Tailer');

    // Use the duplicate button below the questions — the one in reach after filling the form.
    await page.locator('.stig-wizard__actions--bottom .stig-wizard__generate').click();

    // Handoff: we should land in the viewer with the checklist loaded.
    await expect(page).toHaveURL(/\/ckl-viewer/);
    await expect(page.locator('#ckl-loaded')).toBeVisible();
    await expect(page.locator('[data-bind="total"]')).toHaveText('286');
    await expect(page.locator('[data-bind="filename"]')).toContainText('asd-v6r4');

    // Proof the answers were applied (not just a blank skeleton): the donut
    // legend renders a Not Applicable row only when N/A count > 0.
    const naLegend = page.locator('.ckl-legend__item', { hasText: 'Not Applicable' });
    await expect(naLegend).toBeVisible();
    await expect(naLegend.locator('.ckl-legend__count')).not.toHaveText('0');
});

test('Generate buttons stay usable after clicking Back from the viewer', async ({ page }) => {
    await page.goto(ASD_WIZARD);
    await page.check('input[data-q="web_based"][value="no"]');
    await page.locator('.stig-wizard__generate').first().click();
    await expect(page).toHaveURL(/\/ckl-viewer/);

    await page.goBack();
    await expect(page).toHaveURL(/\/stig-wizard\//);

    // Neither button should be stuck disabled/busy from the prior submit.
    const buttons = page.locator('.stig-wizard__generate');
    await expect(buttons.first()).toBeEnabled();
    await expect(buttons.last()).toBeEnabled();

    // Generating again must still work end-to-end.
    await buttons.last().click();
    await expect(page).toHaveURL(/\/ckl-viewer/);
    await expect(page.locator('[data-bind="total"]')).toHaveText('286');
});
