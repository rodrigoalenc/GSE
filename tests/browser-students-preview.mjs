// Real rendered views, disposable in-memory school data and an isolated browser profile.
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {createServer} from 'node:http';
import {readFile, mkdir, writeFile} from 'node:fs/promises';
import {resolve, join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {launchBrowser} from './browser-contract-tabs.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const php = process.env.PHP_BINARY || 'php';
const pages = new Map();
for (const mode of ['all', 'restricted', 'empty']) {
    const fixture = spawnSync(php, ['tests/fixtures/students-preview-browser.php', mode], {cwd: root, encoding: 'utf8', windowsHide: true});
    if (fixture.status !== 0) throw new Error(fixture.error?.message || fixture.stderr || fixture.stdout);
    pages.set('/' + mode, fixture.stdout);
}
pages.set('/no-preview-js', pages.get('/all').replace(/<script src="[^"]*\/alunos-preview\.js[^"<>]*"><\/script>/, ''));
const server = createServer(async (req, res) => {
    const path = new URL(req.url, 'http://local').pathname;
    res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; font-src 'self'");
    if (path.startsWith('/assets/') && !path.includes('..')) {
        try {
            const data = await readFile(join(root, 'public', path));
            res.setHeader('Content-Type', path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'image/png');
            res.end(data); return;
        } catch { res.statusCode = 404; res.end(); return; }
    }
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(pages.get(path) || pages.get('/all'));
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const base = `http://127.0.0.1:${server.address().port}`;
let browser;
let checks = 0;
const check = (actual, expected, message) => { assert.deepEqual(actual, expected, message); checks++; };
try {
    browser = await launchBrowser();
    const page = await browser.newPage();
    for (const width of [1440, 1366, 375, 320]) {
        await page.command('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: false});
        await page.navigate(base + '/all');
        check(await page.evaluate('document.querySelector("[data-preview-field=name]").textContent'), 'Ana Clara Santos', 'First student is shown without a click');
        check(await page.evaluate('document.querySelector("[data-student-preview-name][aria-current=true]").textContent'), 'Ana Clara Santos', 'First student name is initially current');
        check(await page.evaluate('document.querySelectorAll("[data-student-preview-select]").length'), 0, 'Rows have no redundant preview button');
        check(await page.evaluate('document.querySelector("[data-preview-field=studentPhone]").textContent'), '(67) 99999-0001', 'Authorized contacts are formatted');
        if (width > 1050) {
            check(await page.evaluate('document.querySelector(".student-detail-preview").getBoundingClientRect().left > document.querySelector(".students-list-card").getBoundingClientRect().left'), true, 'Preview is on the right on desktop');
        } else {
            await page.click('[data-student-preview-return]');
            check(await page.evaluate('document.activeElement === document.querySelector("[data-student-preview-name][aria-current=true]")'), true, 'Mobile return focuses the initially displayed student name');
        }
        const second = '[data-student-preview-row]:nth-child(2) [data-student-preview-name]';
        if (width === 1440 || width === 375) {
            await page.command('Page.bringToFront');
            await page.evaluate(`document.querySelector(${JSON.stringify(second)}).focus()`);
            await page.command('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13});
            await page.command('Input.dispatchKeyEvent', {type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13});
        } else {
            await page.click(second);
        }
        await page.until('document.querySelector("[data-preview-field=name]").textContent === "Bruno Lima & Souza"');
        check(await page.evaluate('location.pathname'), '/all', 'Selecting a name updates the preview without navigating');
        check(await page.evaluate('document.querySelector("[data-preview-dva]").textContent'), 'Vencida', 'Selecting a name updates the health status');
        check(await page.evaluate('document.querySelector("[data-preview-profile]").getAttribute("href")'), '/aluno/perfil/2', 'Profile action follows the selected student');
        check(await page.evaluate('document.querySelector("[data-preview-field=guardianPhone]").textContent'), 'Não informado', 'Missing contact does not reuse the previous student');
        check(await page.evaluate('[...document.querySelectorAll("[data-student-preview-name][aria-current=true]")].map(link => link.textContent)'), ['Bruno Lima & Souza'], 'Only the selected name is current');
        check(await page.evaluate('document.querySelectorAll("[data-student-selection-id]:checked").length'), 0, 'Preview selection does not change batch selection');
        if (width === 1440) {
            const firstCheckbox = '[data-student-preview-row]:first-child [data-student-selection-id]';
            await page.click(firstCheckbox);
            check(await page.evaluate(`document.querySelector(${JSON.stringify(firstCheckbox)}).checked`), true, 'Batch checkbox remains usable');
            check(await page.evaluate('document.querySelector("[data-preview-field=name]").textContent'), 'Bruno Lima & Souza', 'Batch selection does not change the preview');
            await page.click(firstCheckbox);
        }
        check(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true, `Page reflows at ${width}px`);
        if (width < 1050) {
            await page.click('[data-student-preview-return]');
            check(await page.evaluate(`document.activeElement === document.querySelector(${JSON.stringify(second)})`), true, 'Mobile return restores focus to the selected student');
        }
        if (process.env.GSE_BROWSER_EVIDENCE_DIR) {
            await mkdir(process.env.GSE_BROWSER_EVIDENCE_DIR, {recursive: true});
            await page.evaluate('document.activeElement?.blur(); document.querySelector(".table-scroll").scrollLeft = 0; window.scrollTo(0, 0)');
            await page.evaluate('new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)))');
            const {data} = await page.command('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true});
            await writeFile(join(process.env.GSE_BROWSER_EVIDENCE_DIR, `alunos-preview-${width}.png`), Buffer.from(data, 'base64'));
        }
    }
    await page.navigate(base + '/restricted');
    check(await page.evaluate('document.querySelector("[data-preview-dva]").textContent'), 'Vigente', 'Employees see the DVA status');
    check(await page.evaluate('document.querySelector("[data-preview-field=studentPhone]")'), null, 'Basic permission does not render contacts');
    check(await page.evaluate('[...document.querySelectorAll("[data-student-preview-row]")].some(row => { const data = JSON.parse(row.dataset.studentPreview); return Object.hasOwn(data, "studentPhone") || Object.hasOwn(data, "guardianPhone"); })'), false, 'Restricted contact properties are absent from every row');
    await page.navigate(base + '/empty');
    check(await page.evaluate('document.querySelectorAll("[data-student-preview-row]").length'), 0, 'Empty search has no stale student rows');
    check(await page.evaluate('document.querySelector("[data-preview-profile]")'), null, 'Empty search has no stale profile action');
    await page.navigate(base + '/no-preview-js');
    await page.click('[data-student-preview-row]:nth-child(2) [data-student-preview-name]');
    await page.until('location.pathname === "/aluno/perfil/2" && document.readyState === "complete"');
    check(await page.evaluate('location.pathname'), '/aluno/perfil/2', 'Name link opens the profile without the preview script');
    check(browser.errors, [], 'No JavaScript exceptions under the strict content policy');
    console.log(`Students detail preview: ${checks} checks passed, including permissions, keyboard, batch independence and responsive layouts.`);
} finally {
    await browser?.cleanup();
    await new Promise(done => server.close(done));
}
