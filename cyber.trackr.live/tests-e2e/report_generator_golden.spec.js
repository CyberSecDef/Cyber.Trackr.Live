// Golden-file regression test for Scans to Reports (review S15).
//
// Uploads one fixture of each supported type (CKL, CKLB, .nessus, SCAP
// XCCDF results), runs Parse and Execute (Execute is where the background
// Web Workers build most tabs), and compares every cell of every sheet in the
// downloaded XLSX with tests-e2e/fixtures/report-generator/expected-workbook.json.
// The page clock, timezone and file mtimes are fixed so the output is
// deterministic.
//
// Any intended change to the report output needs the golden file refreshed:
//   UPDATE_REPORT_GOLDEN=1 bin/e2e.sh tests-e2e/report_generator_golden.spec.js
// and the diff reviewed before committing.
const { test, expect } = require('@playwright/test');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const XLSX = require('../public/js/xlsx.full.min.js');

const FIXTURES = path.join(__dirname, 'fixtures', 'report-generator');
const GOLDEN = path.join(FIXTURES, 'expected-workbook.json');
const FILES = ['host-ckl.ckl', 'host-cklb.cklb', 'scan.nessus', 'scap-results.xml'];
const NOW = new Date('2026-02-01T12:00:00Z');   // drives POA&M due dates and milestones
const MTIME = new Date('2026-01-14T08:30:00Z'); // CKL/CKLB scan date comes from the file mtime

test.use({ timezoneId: 'UTC', acceptDownloads: true });

/**
 * Copy files with a fixed mtime (git checkouts don't preserve one) into the
 * test's output dir. Not os.tmpdir(): a snap-packaged Chromium has a private
 * /tmp and reads host temp files as empty (FileReader result null).
 */
function stage(files) {
    const dir = test.info().outputPath('upload');
    fs.mkdirSync(dir, { recursive: true });
    return files.map(({ name, content }) => {
        const p = path.join(dir, name);
        fs.writeFileSync(p, content ?? fs.readFileSync(path.join(FIXTURES, name)));
        fs.utimesSync(p, MTIME, MTIME);
        return p;
    });
}

async function parse(page, files) {
    // setFixedTime pins Date only; install() would also fake the timers the
    // parse loop relies on, and it never finishes.
    await page.clock.setFixedTime(NOW);
    await page.goto('/report_generator');
    await page.locator('#fileElem').setInputFiles(stage(files));
    // Belt and braces: Parse only enables once every file is read, but make
    // the precondition explicit (see report_generator_upload.spec.js).
    await page.waitForFunction((n) => scansToPoam.fileArray.length === n, files.length);
    await expect(page.locator('#scans2poamParse')).toBeEnabled();
    const started = Date.now();
    await page.locator('#scans2poamParse').click();
    await expect(page.locator('table#scanFiles tbody tr td:nth-child(6)'))
        .toHaveText(Array(files.length).fill('Completed'), { timeout: 60000 });
    return Date.now() - started;
}

// Sheets above this size (the page's built-in CCI and RMF reference tabs,
// thousands of rows that don't depend on the uploads) are stored as a row
// count + SHA-256 so the golden file stays small; any change still fails.
const DIGEST_OVER_ROWS = 200;

function workbookAsJson(file) {
    const wb = XLSX.readFile(file);
    return {
        sheets: wb.SheetNames,
        cells: Object.fromEntries(wb.SheetNames.map((n) => {
            const rows = XLSX.utils.sheet_to_json(wb.Sheets[n], { header: 1, raw: true, defval: null });
            return [n, rows.length > DIGEST_OVER_ROWS
                ? { rows: rows.length, sha256: crypto.createHash('sha256').update(JSON.stringify(rows)).digest('hex') }
                : rows];
        })),
    };
}

test('report workbook matches the golden file cell for cell', async ({ page }) => {
    test.setTimeout(120000);
    await parse(page, FILES.map((name) => ({ name })));
    await expect(page.locator('#alertWindow p'), 'no parse errors').toHaveCount(0);

    const download = page.waitForEvent('download', { timeout: 60000 });
    await page.locator('#scans2poamExecute').click();
    const file = await (await download).path();
    await expect(page.locator('#alertWindow p'), 'no export errors').toHaveCount(0);

    const actual = workbookAsJson(file);
    if (process.env.UPDATE_REPORT_GOLDEN) {
        fs.writeFileSync(GOLDEN, JSON.stringify(actual, null, 1) + '\n');
        test.info().annotations.push({ type: 'golden', description: `wrote ${GOLDEN}` });
        return;
    }
    const expected = JSON.parse(fs.readFileSync(GOLDEN, 'utf8'));
    expect(actual.sheets).toEqual(expected.sheets);
    for (const sheet of expected.sheets) {
        expect(actual.cells[sheet], `sheet "${sheet}"`).toEqual(expected.cells[sheet]);
    }
});

// CVE-2022-31129: moment < 2.29.4 parses some strings in quadratic time.
// HOST_START from an uploaded .nessus file is parsed with no format, which
// reaches that code. Measured in Chromium on 2.22.2: 100k "(" 124 ms, 200k
// 487 ms, 400k 1.9 s, so 800k is ~8 s and freezes the tab; on 2.30.1 it is
// effectively instant.
test('a hostile HOST_START does not freeze the parser', async ({ page }) => {
    test.setTimeout(90000);
    const nessus = fs.readFileSync(path.join(FIXTURES, 'scan.nessus'), 'utf8')
        .replace('<tag name="HOST_START">Tue Jan 13 10:20:30 2026</tag>',
                 `<tag name="HOST_START">${'('.repeat(800000)}</tag>`);
    const ms = await parse(page, [{ name: 'hostile.nessus', content: nessus }]);

    expect(ms).toBeLessThan(2000);
    await expect(page.locator('#scanSummary tbody tr')).toHaveCount(2); // both hosts still listed
});
