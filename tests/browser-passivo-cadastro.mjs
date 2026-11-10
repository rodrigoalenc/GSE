import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {mkdir, mkdtemp, rm, writeFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {basename, dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {launchBrowser} from './browser-contract-tabs.mjs';
import {testKeyring} from './browser-keyring.mjs';

// All writes use the existing synthetic fixture in a disposable directory.
const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const temporary = await mkdtemp(join(tmpdir(), 'gse-passivo-browser-'));
const sessions = join(temporary, 'sessions');
await mkdir(sessions);
const environment = {...process.env, GSE_KEYRING_PATH:await testKeyring(temporary), APP_ENV:'testing', APP_URL:'', APP_ALLOWED_HOSTS:'', FORCE_HTTPS:'false',
    DB_PATH:join(temporary,'fixture.sqlite'), LOG_PATH:join(temporary,'technical.log'),
    CERTIDAO_STORAGE_PATH:join(temporary,'certidoes'), CERTIDAO_MAIL_ENABLED:'false', MAIL_ENABLED:'false',
    LOGIN_DELAY_BASE_MS:'0', LOGIN_DELAY_MAX_MS:'0', TRUSTED_PROXIES:''};
const php = process.env.PHP_BINARY || 'php';
const fixture = mode => {
    const result = spawnSync(php, ['tests/fixtures/passivo-browser.php', mode], {cwd:root, env:environment, encoding:'utf8', windowsHide:true});
    if (result.status !== 0) throw new Error(result.error?.message || result.stderr || result.stdout || `PHP fixture exited with ${result.status}`);
    return JSON.parse(result.stdout);
};
let server, browser, serverErrors = '', checks = 0;
const check = (actual, expected, message) => {assert.deepEqual(actual, expected, message); checks++;};
try {
    const initial = fixture('seed');
    const reservation = createServer();
    await new Promise(done => reservation.listen(0, '127.0.0.1', done));
    const port = reservation.address().port;
    await new Promise(done => reservation.close(done));
    const base = `http://127.0.0.1:${port}`;
    environment.APP_URL = base;
    server = spawn(php, ['-d', `session.save_path=${sessions}`, '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'],
        {cwd:root,env:environment,windowsHide:true,stdio:['ignore','ignore','pipe']});
    server.stderr.on('data', chunk => {serverErrors += chunk;});
    let ready = false;
    for (let attempt=0; attempt<100; attempt++) {
        try {if ((await fetch(base+'/login')).status === 200) {ready=true;break;}} catch {}
        await new Promise(done=>setTimeout(done,50));
    }
    if (!ready) throw new Error('Temporary PHP server unavailable: '+serverErrors);
    browser = await launchBrowser();
    const page = await browser.newPage();
    const viewport = (tab,width,height) => tab.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    const go = async (tab,path,selector) => {
        await tab.navigate(base+path);
        await tab.until(`document.readyState==='complete' && document.querySelector(${JSON.stringify(selector)})!==null`);
    };
    const fill = async (tab,selector,text) => {
        await tab.click(selector);
        await tab.command('Input.dispatchKeyEvent',{type:'keyDown',key:'a',code:'KeyA',windowsVirtualKeyCode:65,modifiers:2});
        await tab.command('Input.dispatchKeyEvent',{type:'keyUp',key:'a',code:'KeyA',windowsVirtualKeyCode:65,modifiers:2});
        await tab.command('Input.insertText',{text});
    };
    const date = (tab,value) => tab.evaluate(`(() => {const field=document.querySelector('#data-nascimento');field.value=${JSON.stringify(value)};field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    const flow = tab => tab.evaluate(`document.querySelector('[name="fluxo"]').value`);
    const choice = async (tab,box=null) => {
        if (box !== null) {
            await tab.evaluate(`document.querySelector('#caixa-existente').value=${JSON.stringify(box)}`);
            await tab.click('form:has(#caixa-existente) button[type="submit"]');
        } else {
            await tab.click('form:has(input[name="tipo_caixa"][value="nova"]) button[type="submit"]');
        }
        await tab.until(`document.readyState==='complete' && document.querySelector('#nome-completo')!==null`);
    };
    const save = async tab => {
        await tab.click('.passivo-form .form-actions .btn-primary');
        await tab.until(`document.readyState==='complete' && (location.pathname.startsWith('/passivo/detalhes/') || document.querySelector('[data-form-errors]')!==null)`);
    };
    const shot = async (tab,name) => {
        if (!process.env.GSE_BROWSER_EVIDENCE_DIR) return;
        await mkdir(process.env.GSE_BROWSER_EVIDENCE_DIR,{recursive:true});
        const {data}=await tab.command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        await writeFile(join(process.env.GSE_BROWSER_EVIDENCE_DIR,name+'.png'),Buffer.from(data,'base64'));
    };
    await viewport(page,1366,768);
    await go(page,'/login','#email');
    await fill(page,'#email','browser@example.test');
    await fill(page,'#senha','Teste ficticio seguro 2026');
    await page.click('.btn-login');
    await page.until(`location.pathname==='/dashboard' && document.readyState==='complete'`);

    await go(page,'/passivo/criar','#caixa-existente');
    const firstFlow = await flow(page);
    check(/^[a-f0-9]{32}$/.test(firstFlow),true,'Box selection opens an isolated creation flow');
    check(await page.evaluate(`document.querySelector('[name="nome_completo"]')===null`),true,'Student details wait until a box is chosen');
    check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Desktop selection fits the viewport');
    check(fixture('inspect'),initial,'Opening selection does not change students, archive or DVA');
    await shot(page,'passivo-cadastro-escolha-1366');
    await choice(page,'2');
    check(await flow(page),firstFlow,'Selection and student form keep the same flow');
    check(await page.evaluate(`document.querySelector('[name="numero"]')===null && document.querySelector('#numero-automatico').readOnly`),true,'Record number is automatic rather than user editable');
    await fill(page,'#nome-completo','Ana Browser Automatica');
    await date(page,'2000-02-29');
    await page.click('button[name="escolher_caixa"]');
    await page.until(`document.readyState==='complete' && document.querySelector('#caixa-existente')!==null`);
    await choice(page,'10');
    check(await page.evaluate(`({name:document.querySelector('#nome-completo').value,date:document.querySelector('#data-nascimento').value,box:document.querySelector('[name="caixa"]').value})`),
        {name:'Ana Browser Automatica',date:'2000-02-29',box:'10'},'Changing box preserves entered name and birth date');
    await page.click('button[name="escolher_caixa"]');
    await page.until(`document.readyState==='complete' && document.querySelector('#caixa-existente')!==null`);
    await choice(page,'2');
    check(fixture('inspect'),initial,'Changing destinations creates no record or empty box');
    await date(page,'2999-01-01');
    await save(page);
    check(await page.evaluate(`document.body.textContent.includes('A data de nascimento não pode estar no futuro.')`),true,'Invalid birth date returns the validation message');
    check(await flow(page),firstFlow,'Validation feedback keeps its creation flow');
    check(await page.evaluate(`document.querySelector('#nome-completo').value`),'Ana Browser Automatica','Validation failure preserves the student name');
    check(fixture('inspect'),initial,'Validation failure does not renumber the archive');
    await date(page,'2000-02-29');
    await save(page);
    check(await page.evaluate(`document.body.textContent.includes('Ana Browser Automatica')`),true,'Saved student is displayed in archive details');
    const afterFirst = fixture('inspect');
    check(afterFirst.passivo.map(row=>row.numero),['2','3','4','1'],'Alphabetical insertion renumbers the selected and following boxes');
    check(afterFirst.alunos,initial.alunos,'School student registrations remain intact');
    check(afterFirst.dvas,initial.dvas,'School DVA history remains intact');
    await page.navigate(await page.evaluate('location.href'));
    check(fixture('inspect'),afterFirst,'Reloading saved details preserves numbering');

    // Alterar caixa must also work before the required name has been entered.
    await go(page,'/passivo/criar','#caixa-existente');
    await choice(page,'2');
    await date(page,'2001-01-01');
    await page.click('button[name="escolher_caixa"]');
    await page.until(`document.readyState==='complete' && document.querySelector('#caixa-existente')!==null`);
    await choice(page,'10');
    check(await page.evaluate(`({name:document.querySelector('#nome-completo').value,date:document.querySelector('#data-nascimento').value})`),
        {name:'',date:'2001-01-01'},'Change box bypasses required fields and preserves a partially completed form');
    check(fixture('inspect'),afterFirst,'Changing a partial form performs no business write');

    const second = await browser.newPage();
    await viewport(second,1366,768);
    await go(page,'/passivo/criar','#caixa-existente');
    await go(second,'/passivo/criar','#caixa-existente');
    const flowA=await flow(page), flowB=await flow(second);
    check(flowA!==flowB,true,'Separate tabs receive separate flow identifiers');
    await choice(page);
    await choice(second);
    await fill(page,'#nome-completo','Aba A Browser Automatica');
    await date(page,'2999-01-01');
    await page.evaluate(`fetch(document.querySelector('.passivo-form').action,{method:'POST',body:new URLSearchParams(new FormData(document.querySelector('.passivo-form'))),redirect:'manual'}).then(()=>true)`);
    await go(second,`/passivo/criar?tipo_caixa=nova&fluxo=${flowB}`,'#nome-completo');
    check(await second.evaluate(`document.querySelector('#nome-completo').value`),'','Opening another tab cannot consume pending validation data');
    await go(page,`/passivo/criar?fluxo=${flowA}`,'#nome-completo');
    check(await page.evaluate(`document.querySelector('#nome-completo').value`),'Aba A Browser Automatica','Original tab recovers its own validation data');
    check(await flow(page),flowA,'Original tab retains its own flow');
    await date(page,'2000-01-01');
    await save(page);
    await fill(second,'#nome-completo','Aba B Browser Automatica');
    await date(second,'2000-01-02');
    await save(second);
    const afterTabs=fixture('inspect');
    check(afterTabs.passivo.slice(-2).map(row=>row.caixa),['11','12'],'Two stale new-box previews resolve distinct sequential boxes on save');
    check(afterTabs.passivo.slice(-2).map(row=>row.numero),['5','6'],'New boxes continue the global record sequence');

    await viewport(page,390,844);
    await go(page,'/passivo/criar','#caixa-existente');
    check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Mobile selection fits the viewport');
    await shot(page,'passivo-cadastro-escolha-390');
    await choice(page,'2');
    check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Mobile student form fits the viewport');
    await shot(page,'passivo-cadastro-formulario-390');
    await page.click('.passivo-form .cancelar');
    await page.until(`location.pathname==='/passivo' && document.readyState==='complete'`);
    check(fixture('inspect'),afterTabs,'Canceling a new record creates neither students nor boxes');
    await page.evaluate('history.back()');
    await page.until(`location.pathname==='/passivo/criar' && document.readyState==='complete' && document.querySelector('#nome-completo')!==null`);
    check(fixture('inspect'),afterTabs,'Returning to a canceled form does not change archive numbering');
    check(browser.errors,[],'No JavaScript exceptions');
    check(/PHP (?:Warning|Fatal error|Parse error)/.test(serverErrors),false,'No PHP runtime warnings');
    console.log(`Browser automatic passive registration: ${checks} checks passed (fictitious database, desktop, mobile and separate tabs).`);
} finally {
    await browser?.cleanup();
    if (server && server.exitCode===null) {
        server.kill();
        await new Promise(done=>{server.once('exit',done);setTimeout(done,2000);});
    }
    if (dirname(resolve(temporary))!==resolve(tmpdir()) || !basename(temporary).startsWith('gse-passivo-browser-')) throw new Error('Unsafe fixture cleanup path');
    await rm(temporary,{recursive:true,force:true,maxRetries:5,retryDelay:200});
}
