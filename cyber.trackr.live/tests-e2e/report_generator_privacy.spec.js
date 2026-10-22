// Scans to Reports must never send scan data to any server: files are read,
// parsed (page + Web Workers) and turned into the XLSX entirely in the browser.
// During a full upload -> Parse -> Execute run this checks two views:
//   - the server's: tests-e2e/router.php logs every request carrying this
//     run's e2e_run cookie, which the browser attaches to all same-origin
//     requests including those from Web Workers (Playwright can't observe
//     worker traffic itself);
//   - the browser's: every request Playwright sees, to catch anything aimed
//     at another site (the CSP's connect-src 'self' also blocks those).
//
// If it fails, something started uploading. Do not loosen it to make it pass.
const { test, expect } = require('@playwright/test');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const FIXTURES = path.join(__dirname, 'fixtures', 'report-generator');
const FILES = ['host-ckl.ckl', 'host-cklb.cklb', 'scan.nessus', 'scap-results.xml'];
const CANARY = 'CANARY-' + Date.now() + '-do-not-upload';

// The only request allowed once a file has been added: the static reference
// data (review P5), downloaded, never uploaded.
const ALLOWED_AFTER_LOAD = [/^\/js\/report-generator-data\.js(\?v=[\w.]+)?$/, /^\/_wdt\//]; // _wdt: dev toolbar only

/** Fixture bytes with the canary embedded in a way each parser tolerates. */
function withCanary(name) {
    const raw = fs.readFileSync(path.join(FIXTURES, name), 'utf8');
    const content = name.endsWith('.cklb')
        ? raw.replace('"title": "Fixture CKLB"', `"title": "Fixture CKLB ${CANARY}"`)
        : raw.replace(/\?>/, `?><!-- ${CANARY} -->`);
    if (!content.includes(CANARY)) throw new Error(`canary not embedded in ${name}`);
    return { name, mimeType: 'application/octet-stream', buffer: Buffer.from(content) };
}

test('a full report run sends nothing to any server', async ({ page, context }) => {
    test.setTimeout(90000);
    const requests = [];
    let phase = 'page load';
    context.on('request', (r) => requests.push({
        phase,
        method: r.method(),
        url: r.url(),
        body: r.postDataBuffer() ?? Buffer.alloc(0),
    }));
    const sockets = [];
    page.on('websocket', (ws) => sockets.push(ws.url()));

    const run = 'privacy-' + crypto.randomBytes(6).toString('hex');
    await context.addCookies([{ name: 'e2e_run', value: run, url: 'http://127.0.0.1:8123' }]);
    const serverLog = path.join(__dirname, '..', 'test-results', `e2e-requests-${run}.log`);
    const serverSaw = () => (fs.existsSync(serverLog) ? fs.readFileSync(serverLog, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : []);

    await page.goto('/report_generator', { waitUntil: 'networkidle' });
    const origin = new URL(page.url()).origin;
    const atPageLoad = serverSaw().length;
    expect(atPageLoad, 'the server-side request log is working').toBeGreaterThan(0);

    phase = 'add files';
    await page.locator('#fileElem').setInputFiles(FILES.map(withCanary));
    await page.waitForFunction((n) => scansToPoam.fileArray.length === n, FILES.length);
    await expect(page.locator('#scans2poamParse')).toBeEnabled();

    phase = 'parse';
    await page.locator('#scans2poamParse').click();
    await expect(page.locator('table#scanFiles tbody tr td:nth-child(6)')).toHaveText(Array(FILES.length).fill('Completed'));

    phase = 'execute';
    const download = page.waitForEvent('download', { timeout: 60000 });
    await page.locator('#scans2poamExecute').click();
    await download; // the XLSX is saved by the browser, not fetched from the server
    await page.waitForLoadState('networkidle');

    // The server's view: what actually arrived, from the page or any worker.
    await page.waitForTimeout(1000); // let any late worker request land
    const server = serverSaw();
    fs.rmSync(serverLog, { force: true });
    const serverLeaks = server.filter((r) => r.method !== 'GET' || r.bytes > 0
        || decodeURIComponent(r.uri).includes(CANARY) || r.body.includes(CANARY));
    expect(serverLeaks.map((r) => `${r.method} ${r.uri} (${r.bytes} B body)`), 'the server received no data').toEqual([]);
    const serverAfterLoad = server.slice(atPageLoad).map((r) => r.uri)
        .filter((u) => !ALLOWED_AFTER_LOAD.some((re) => re.test(u)));
    expect(serverAfterLoad, 'after page load the server only served the reference data').toEqual([]);

    const describe = (r) => `[${r.phase}] ${r.method} ${r.url} (${r.body.length} B body)`;
    const leaks = requests.filter((r) =>
        r.method !== 'GET'
        || r.body.length > 0
        || decodeURIComponent(r.url).includes(CANARY)
        || r.body.includes(CANARY)
        || !(r.url.startsWith(origin) || r.url.startsWith('blob:') || r.url.startsWith('data:'))
    );
    expect(leaks.map(describe), 'no request may send data or leave the site').toEqual([]);
    expect(sockets, 'no websockets').toEqual([]);

    const afterLoad = requests.filter((r) => r.phase !== 'page load' && !r.url.startsWith('blob:'));
    const unexpected = afterLoad.filter((r) => !ALLOWED_AFTER_LOAD.some((re) => re.test(r.url.slice(origin.length))));
    expect(unexpected.map(describe), 'after page load, only the reference data may be fetched').toEqual([]);
    expect(requests.filter((r) => r.phase === 'parse' || r.phase === 'execute').map(describe),
        'Parse and Execute make no requests at all').toEqual([]);
});
