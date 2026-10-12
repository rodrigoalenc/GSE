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
let server, browser;
let checks = 0;
const check = (value, expected, message) => {assert.deepEqual(value, expected, message); checks++;};
try {
    fixture('seed');
    const reservation = createServer();
    await new Promise(done => reservation.listen(0, '127.0.0.1', done));
    const port = reservation.address().port;
    await new Promise(done => reservation.close(done));
    server = spawn(php, ['-d', `session.save_path=${sessions}`, '-d', `auto_prepend_file=${join(root,'tests/fixtures/password-runtime.php')}`,
        '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'],
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
    const page = await browser.newPage();
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
        await writeFile(modeFile, mode);
        await go('/senha/alterar');
        await fill('#senha_atual', temporaryPassword);
        await fill('#nova_senha', permanentPassword);
        await fill('#confirmar_senha', permanentPassword);
        await submit('/senha/alterar');
        check(await page.evaluate(`!!document.querySelector('[role="alert"]')`), true, 'Password failure returns a controlled form error');
        check(fixture(), before, 'Password failure preserves account and session version');
        const field = await page.evaluate(`document.querySelector('#nova_senha')?.value ?? null`);
        assert.notEqual(field, null, await page.evaluate('document.body.textContent'));
        check(field, '', 'Failure response clears password field');
        const body = await page.evaluate('document.body.textContent');
        assert.doesNotMatch(body, /Artificial private detail|ValueError|Argon2id|\$argon2id\$/);
        assert.ok(!body.includes(permanentPassword)); checks++;
    }
    await unlink(modeFile);
    await go('/senha/alterar');
    await fill('#senha_atual', temporaryPassword);
    await fill('#nova_senha', permanentPassword);
    await fill('#confirmar_senha', permanentPassword);
    await submit('/senha/alterar');
    check(await page.evaluate('location.pathname'), '/login', 'Successful change ends session');
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
    assert.doesNotMatch(log, /\$argon2id\$|\$2[aby]\$/); checks++;
    check(browser.errors, [], 'No browser exceptions');
    console.log(`Browser password security: ${checks} checks passed (login, mandatory change, runtime failures, session preservation and profile).`);
} finally {
    await browser?.cleanup();
    if (server && server.exitCode === null) {
        await new Promise(done => {server.once('exit',done); server.kill();});
    }
    await rm(temporary, {recursive:true, force:true, maxRetries:10, retryDelay:100});
}
