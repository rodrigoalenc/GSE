// Real forms against a disposable database; no institutional data or credentials.
import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {mkdtemp, mkdir, rm, writeFile, readFile, unlink} from 'node:fs/promises';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {launchBrowser} from './browser-contract-tabs.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const temporary = await mkdtemp(join(tmpdir(), 'gse-password-browser-'));
const database = join(temporary, 'fixture.sqlite');
const modeFile = database + '.password-runtime';
const sessions = join(temporary, 'sessions');
await mkdir(sessions);
const environment = {...process.env, APP_ENV:'testing', APP_URL:'', APP_ALLOWED_HOSTS:'', FORCE_HTTPS:'false',
    DB_PATH:database, LOG_PATH:join(temporary,'technical.log'), MAIL_ENABLED:'false',
    CERTIDAO_MAIL_ENABLED:'false', LOGIN_DELAY_BASE_MS:'0', LOGIN_DELAY_MAX_MS:'0', TRUSTED_PROXIES:''};
const php = process.env.PHP_BINARY || 'php';
const fixture = (mode = 'state') => {
    const result = spawnSync(php, ['tests/fixtures/password-browser.php', mode], {cwd:root, env:environment, encoding:'utf8', windowsHide:true});
    assert.equal(result.status, 0, result.stderr);
    return JSON.parse(result.stdout);
};
const temporaryPassword = 'á'.repeat(40) + ' frase temporária';
const permanentPassword = '界'.repeat(40) + ' frase definitiva';
let server, browser, page, originalFailure, activeMode;
let checks = 0;
const check = (value, expected, message) => {assert.deepEqual(value, expected, message); checks++;};
const redact = (value, sensitiveValues = []) => {
    let text = String(value);
    for (const secret of [temporaryPassword, permanentPassword, 'L'.repeat(72), ...sensitiveValues].filter(Boolean).sort((a,b)=>b.length-a.length)) {
        text = text.split(secret).join('[REDACTED]');
    }
    return text.replace(/\$(?:argon2(?:id|i)\$[^\s"<>]+|2[aby]\$[^\s"<>]+)/g, '[REDACTED HASH]')
        .replace(/\b(?:cookie|token|authorization|secret|password|senha)\s*[:=]\s*[^\s,;<>]+/gi, '[REDACTED]');
};
let diagnosticsCaptured = false;
const captureDiagnostics = async (label) => {
    if (!process.env.GSE_BROWSER_EVIDENCE_DIR || !page || diagnosticsCaptured) return;
    diagnosticsCaptured = true;
    try {
        await mkdir(process.env.GSE_BROWSER_EVIDENCE_DIR, {recursive:true});
        const prefix = join(process.env.GSE_BROWSER_EVIDENCE_DIR, `password-security-${label}-${Date.now()}`);
        const state = await page.evaluate(`(() => {
            const fields = [...document.querySelectorAll('input, textarea, select')];
            const sensitiveValues = fields.map(field => field.value).filter(Boolean);
            const clone = document.documentElement.cloneNode(true);
            clone.querySelectorAll('input, textarea, select').forEach(field => {
                field.removeAttribute('value');
                field.value = '';
                if (field.tagName === 'TEXTAREA') field.textContent = '';
                field.querySelectorAll('option').forEach(option => {option.removeAttribute('value'); option.removeAttribute('selected');});
            });
            clone.querySelectorAll('script').forEach(script => script.remove());
            clone.querySelectorAll('meta').forEach(meta => meta.removeAttribute('content'));
            clone.querySelectorAll('*').forEach(element => {
                for (const attribute of [...element.attributes]) {
                    if (/value|token|cookie|secret|nonce|authorization|^data-/i.test(attribute.name)) element.removeAttribute(attribute.name);
                    else if (/^(href|src|action)$/i.test(attribute.name)) {
                        try { const url = new URL(attribute.value, location.href); url.search = ''; url.hash = ''; element.setAttribute(attribute.name,url.href); } catch {}
                    }
                }
            });
            return {url:location.origin+location.pathname, title:document.title,
                viewport:{width:innerWidth,height:innerHeight},
                status:performance.getEntriesByType('navigation')[0]?.responseStatus,
                fields:fields.map(field => ({id:field.id,type:field.type,empty:field.value===''})),
                dom:clone.outerHTML, sensitiveValues};
        })()`);
        const {sensitiveValues, ...safeState} = state;
        await writeFile(prefix+'.json', redact(JSON.stringify({...safeState, mode:activeMode ?? null,
            errors:browser?.errors ?? [], console:browser?.consoleMessages ?? []}, null, 2), sensitiveValues));
        // Redact the live page before capturing pixels, including revealed password inputs.
        await page.evaluate(`(() => {
            const secrets = ${JSON.stringify([temporaryPassword, permanentPassword, 'L'.repeat(72), ...sensitiveValues])};
            document.querySelectorAll('input, textarea, select').forEach(field => {field.value=''; field.removeAttribute('value');});
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            while (walker.nextNode()) {
                let text = walker.currentNode.textContent;
                for (const secret of secrets.filter(Boolean).sort((a,b)=>b.length-a.length)) text = text.split(secret).join('[REDACTED]');
                walker.currentNode.textContent = text.replace(/\\$(?:argon2(?:id|i)\\$[^\\s"<>]+|2[aby]\\$[^\\s"<>]+)/g, '[REDACTED HASH]');
            }
        })()`);
        const screenshot = await page.command('Page.captureScreenshot', {format:'png', captureBeyondViewport:false});
        await writeFile(prefix+'.png', Buffer.from(screenshot.data,'base64'));
    } catch {
        // Evidence collection must never replace the original failure or print sensitive state.
        console.error('Password security diagnostic capture failed.');
    }
};
try {
    fixture('seed');
    await writeFile(environment.LOG_PATH, '', {flag:'a'});
    const reservation = createServer();
    await new Promise(done => reservation.listen(0, '127.0.0.1', done));
    const port = reservation.address().port;
    await new Promise(done => reservation.close(done));
    server = spawn(php, ['-d', `session.save_path=${sessions}`,
        '-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/fixtures/password-router.php'],
        {cwd:root, env:environment, windowsHide:true, stdio:['ignore','ignore','pipe']});
    let errors = '';
    server.stderr.on('data', chunk => {errors += chunk;});
    const base = `http://127.0.0.1:${port}`;
    let ready = false;
    for (let attempt=0; attempt<100; attempt++) {
        try {if ((await fetch(base+'/login')).status === 200) {ready=true; break;}} catch {}
        await new Promise(done => setTimeout(done, 50));
    }
    assert.ok(ready, errors);
    browser = await launchBrowser();
    page = await browser.newPage({captureFailure: captureDiagnostics});
    const go = path => page.navigate(base+path);
    const fill = async (selector, value) => {
        await page.evaluate(`document.querySelector(${JSON.stringify(selector)}).value = ''`);
        await page.click(selector);
        await page.command('Input.insertText', {text:value});
    };
    const submit = async (action = '/login/entrar') => {
        const previous = await page.evaluate('performance.timeOrigin');
        await page.click(`form[action$="${action}"] button[type="submit"]`);
        await page.until(`performance.timeOrigin !== ${previous} && document.readyState === 'complete'`);
    };
    const login = async (email, password) => {
        await go('/login');
        await fill('#email', email);
        await fill('#senha', password);
        await submit();
    };
    await login('legacy@example.test', 'L'.repeat(72)+'x');
    check(await page.evaluate('location.pathname'), '/login/entrar', 'Ambiguous bcrypt stays unauthenticated');
    check(fixture().algorithms[1], 'bcrypt', 'Ambiguous login does not rehash');
    await go('/dashboard');
    check(await page.evaluate('location.pathname'), '/login', 'Ambiguous login has no authenticated session');

    await login('admin@example.test', temporaryPassword);
    check(await page.evaluate('location.pathname'), '/senha/alterar', 'Long Unicode temporary password authenticates and requires replacement');
    const before = fixture();
    for (const mode of ['unavailable','failure']) {
        activeMode = mode;
        await writeFile(modeFile, mode);
        await go('/senha/alterar');
        await fill('#senha_atual', temporaryPassword);
        await fill('#nova_senha', permanentPassword);
        await fill('#confirmar_senha', permanentPassword);
        const previousLog = await readFile(environment.LOG_PATH, 'utf8');
        await submit('/senha/alterar');
        const newLog = (await readFile(environment.LOG_PATH, 'utf8')).slice(previousLog.length);
        const expectedCause = mode === 'unavailable' ? 'argon2id_unavailable' : 'argon2id_generation_failed';
        const events = newLog.trim().split(/\r?\n/).filter(Boolean).map(line => {
            try { return JSON.parse(line); } catch { throw new Error('Technical log entry is not valid JSON.'); }
        });
        check(events.some(event => event.event === 'password_hash_failed' && event.cause === expectedCause), true,
            `HTTP request activated ${mode} simulation and logged its specific cause`);
        check(await page.evaluate(`performance.getEntriesByType('navigation')[0]?.responseStatus`), 422, 'Password failure returns HTTP 422');
        check(await page.evaluate('location.pathname'), '/senha/alterar', 'Password failure remains on the change form');
        check(await page.evaluate(`!!document.querySelector('form[action$="/senha/alterar"]')`), true, 'Password change form remains available');
        check(await page.evaluate(`[...document.querySelectorAll('.error-message li')].map(item=>item.textContent.trim())`),
            ['Não foi possível alterar a senha.'], 'Password failure returns the specific controlled error');
        check(fixture(), before, 'Password failure preserves account and session version');
        check(fixture().original_hashes_preserved, [true,true], 'Password failure preserves the original hashes');
        check(await page.evaluate(`['senha_atual','nova_senha','confirmar_senha'].every(id=>document.getElementById(id)?.value==='')`),
            true, 'Failure response clears all password fields');
        const response = await page.evaluate('document.documentElement.outerHTML');
        check(/Artificial private detail|ValueError|Argon2id|\$argon2id\$|\$2[aby]\$|Stack trace|PDOException/.test(response), false,
            'Failure response contains no hashes or internal details');
        check([temporaryPassword,permanentPassword].some(secret=>response.includes(secret)), false, 'Failure response contains no passwords');
    }
    await unlink(modeFile);
    activeMode = null;
    await go('/senha/alterar');
    await fill('#senha_atual', temporaryPassword);
    await fill('#nova_senha', permanentPassword);
    await fill('#confirmar_senha', permanentPassword);
    await submit('/senha/alterar');
    check(await page.evaluate('location.pathname'), '/login', 'Successful change ends session');
    const changed = fixture();
    check(changed.users[0].session_version, before.users[0].session_version + 1, 'Successful change increments session version');
    check(changed.users[0].deve_alterar_senha, 0, 'Successful change clears mandatory replacement');
    check(changed.original_hashes_preserved, [false,true], 'Successful change replaces only the intended password hash');
    await go('/dashboard');
    check(await page.evaluate('location.pathname'), '/login', 'Successful change leaves no authenticated session');
    await login('admin@example.test', permanentPassword);
    check(await page.evaluate('location.pathname'), '/dashboard', 'Long new Argon2id password authenticates');
    await go('/usuario/perfil');
    await fill('#perfil-nome', 'Admin atualizado');
    await fill('#perfil-senha-atual', permanentPassword);
    await submit('/usuario/perfil');
    check(fixture().users[0].nome, 'Admin atualizado', 'Profile confirms full long Unicode password');
    check(await page.evaluate('location.pathname'), '/usuario/perfil', 'Name change preserves session');
    check(fixture().algorithms[0], 'argon2id', 'New password remains Argon2id');
    const log = await readFile(environment.LOG_PATH,'utf8');
    assert.ok(log.includes('argon2id_unavailable') && log.includes('argon2id_generation_failed'), 'Both runtime failures reached the application');
    for (const secret of [temporaryPassword, permanentPassword, 'Artificial private detail']) assert.ok(!log.includes(secret));
    check(/\$argon2id\$|\$2[aby]\$/.test(log), false, 'Technical log contains no password hashes');
    check(browser.errors.length, 0, 'No browser exceptions; failure evidence redacts their details');
    console.log(`Browser password security: ${checks} checks passed (login, mandatory change, runtime failures, session preservation and profile).`);
} catch (error) {
    originalFailure = error;
    await captureDiagnostics('failure');
    throw error;
} finally {
    const cleanupErrors = [];
    try { await browser?.cleanup(); } catch (error) { cleanupErrors.push(error); }
    try {
        if (server && server.exitCode === null) {
            await new Promise(done => {server.once('exit',done); server.kill();});
        }
    } catch (error) { cleanupErrors.push(error); }
    try { await rm(temporary, {recursive:true, force:true, maxRetries:10, retryDelay:100}); } catch (error) { cleanupErrors.push(error); }
    if (cleanupErrors.length) {
        if (!originalFailure) throw cleanupErrors[0];
        console.error('Password security cleanup failed after the original test failure.');
    }
}
