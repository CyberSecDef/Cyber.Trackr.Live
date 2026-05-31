// Playwright end-to-end config for the STIG wizard (GitHub issue #15).
//
// Boots a throwaway PHP dev server on :8123 and drives the full flow:
// STIG view page -> wizard -> generate -> CKLB viewer handoff.
//
// CHROMIUM_BIN lets the run use the system Chromium (set automatically by
// bin/e2e.sh) instead of Playwright's bundled download, so the suite works in
// environments where `playwright install` can't fetch browsers.
const { defineConfig, devices } = require('@playwright/test');

const chromiumBin = process.env.CHROMIUM_BIN || undefined;

module.exports = defineConfig({
    testDir: './tests-e2e',
    timeout: 30000,
    expect: { timeout: 7000 },
    fullyParallel: false,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: 'http://127.0.0.1:8123',
        headless: true,
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: chromiumBin
                    ? { executablePath: chromiumBin, args: ['--no-sandbox'] }
                    : { args: ['--no-sandbox'] },
            },
        },
    ],
    webServer: {
        command: 'php -S 127.0.0.1:8123 -t public tests-e2e/router.php',
        url: 'http://127.0.0.1:8123/',
        reuseExistingServer: true,
        timeout: 30000,
    },
});
