import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {mkdtemp, mkdir, rm, writeFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {basename, dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {launchBrowser} from './browser-contract-tabs.mjs';
import {testKeyring} from './browser-keyring.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const temporary = await mkdtemp(join(tmpdir(), 'gse-movement-browser-'));
const sessions = join(temporary, 'sessions');
await mkdir(sessions);
const environment = {...process.env, GSE_KEYRING_PATH:await testKeyring(temporary), APP_ENV:'testing', APP_URL:'', APP_ALLOWED_HOSTS:'', FORCE_HTTPS:'false',
    DB_PATH:join(temporary,'fixture.sqlite'), LOG_PATH:join(temporary,'technical.log'),
    CERTIDAO_STORAGE_PATH:join(temporary,'certidoes'), CERTIDAO_MAIL_ENABLED:'false', MAIL_ENABLED:'false',
    LOGIN_DELAY_BASE_MS:'0', LOGIN_DELAY_MAX_MS:'0', TRUSTED_PROXIES:''};
const fixture = (mode = 'inspect') => {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/movimentacao-browser.php', mode], {cwd:root, env:environment, encoding:'utf8', windowsHide:true});
    if (result.status !== 0) throw new Error(result.error?.message || result.stderr || result.stdout);
    return JSON.parse(result.stdout);
};
let server, browser, checks = 0, serverErrors = '';
const check = (actual, expected, message) => { assert.deepEqual(actual, expected, message); checks++; };
try {
    const initial = fixture('seed');
    const reservation = createServer();
    await new Promise(done => reservation.listen(0,'127.0.0.1',done));
    const port = reservation.address().port;
    await new Promise(done => reservation.close(done));
    server = spawn(process.env.PHP_BINARY || 'php', ['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],
        {cwd:root,env:environment,windowsHide:true,stdio:['ignore','ignore','pipe']});
    server.stderr.on('data', chunk => { serverErrors += chunk; });
    const base = `http://127.0.0.1:${port}`;
    let ready = false;
    for (let i=0;i<100;i++) {
        try { if ((await fetch(base+'/login')).status === 200) { ready=true;break; } } catch {}
        await new Promise(done=>setTimeout(done,50));
    }
    if (!ready) throw new Error('Temporary server unavailable: '+serverErrors);
    browser = await launchBrowser();
    const page = await browser.newPage();
    const go = async path => { await page.navigate(base+path); await page.until(`document.readyState==='complete'`); };
    const fill = async (selector, text) => { await page.click(selector);await page.command('Input.insertText',{text}); };
    const setValue = async (selector,value) => page.evaluate(`document.querySelector(${JSON.stringify(selector)}).value=${JSON.stringify(value)}`);
    const awaitMovement = () => page.until(`location.pathname==='/aluno/movimentar' && document.readyState==='complete' && !!document.querySelector('#movement-senior-title')`);
    const submit = async selector => {
        const origin = await page.evaluate('performance.timeOrigin');
        await page.click(selector);
        await page.until(`performance.timeOrigin!==${JSON.stringify(origin)} && document.readyState==='complete'`);
    };
    const shot = async name => {
        if (!process.env.GSE_BROWSER_EVIDENCE_DIR) return;
        await mkdir(process.env.GSE_BROWSER_EVIDENCE_DIR,{recursive:true});
        const {data} = await page.command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        await writeFile(join(process.env.GSE_BROWSER_EVIDENCE_DIR,name+'.png'),Buffer.from(data,'base64'));
    };
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await go('/login');
    await fill('#email','movement@example.test');await fill('#senha','Teste ficticio seguro 2026');await page.click('.btn-login');
    await page.until(`location.pathname==='/dashboard'`);
    await go('/aluno');
    check(await page.evaluate(`!!document.querySelector('a[href$="/aluno/movimentar"]')`),true,'Administrator sees entry button');
    await go('/aluno/movimentar?ano=2027');
    check(await page.evaluate(`document.querySelector('.movement-controls button[type="submit"]').textContent.trim()`),'Abrir ano','Movement screen renders');
    for (const [id,serie] of [[1,'10'],[2,'11'],[3,'12']]) await setValue(`#series-${id}`,serie);
    await submit('form[action$="/movimentar/series"] button[type="submit"]');await awaitMovement();
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/preview"] button[type="submit"]').disabled`),true,'Progression is blocked before senior review');
    // Every new mutating route rejects GET and invalid CSRF.
    for (const action of ['series','conferencia','preview','confirmar']) {
        const status = await page.evaluate(`(async()=>{
            const path='/aluno/movimentar/${action}';
            return [(await fetch(path,{redirect:'manual'})).status,
                (await fetch(path,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'_csrf_token=invalid&ano=2027'})).status];
        })()`);
        check(status,[405,419],`${action}: method and CSRF protections`);
    }
    for (const [width,height] of [[1366,900],[390,844],[844,390],[1920,1080]]) {
        await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`No page overflow at ${width}x${height}`);
        await page.evaluate('scrollTo(0,0)');await shot(`movimentacao-${width}x${height}`);
    }
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await page.click('form[action$="/arquivar-lote/selecionar"] input[value="3"][name="alunos[]"]');
    await submit('form[action$="/arquivar-lote/selecionar"] button[type="submit"]');
    await page.until(`location.pathname==='/aluno/arquivar-lote' && !!document.querySelector('[name="tipo_caixa"]')`);
    await setValue('[name="tipo_caixa"]','nova');
    await page.evaluate(`document.querySelector('[name="tipo_caixa"]').dispatchEvent(new Event('change',{bubbles:true}))`);
    await fill('#archive-batch-new-box','CONCLUINTES 2027');
    await submit('form[action$="/arquivar-lote/preview"] button[type="submit"]');
    await page.until(`!!document.querySelector('form[action$="/arquivar-lote/confirmar"]')`);
    check(fixture().passive.length,0,'Archive preview does not mutate');
    await page.click('form[action$="/arquivar-lote/confirmar"] input[name="confirmar"]');
    await submit('form[action$="/arquivar-lote/confirmar"] button[type="submit"]');await awaitMovement();
    check(await page.evaluate(`new URL(location.href).searchParams.get('ano')`),'2027','Archive returns to the selected progression cycle');
    check(fixture().students.map(s=>s.ativo),[1,1,0,1],'Graduate is archived and repeater stays active');
    await page.click('form[action$="/movimentar/conferencia"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/conferencia"] button[type="submit"]');await awaitMovement();
    await go('/aluno/movimentar?ano=2027&turma=1');
    await page.until(`!document.querySelector('[data-movement-select-all]').hidden`);
    await page.click('form[action$="/movimentar/preview"] [data-movement-select-all]');
    check(await page.evaluate(`document.querySelectorAll('form[action$="/movimentar/preview"] input[name="alunos[]"]:checked').length`),2,'Select whole displayed class');
    await page.click('form[action$="/movimentar/preview"] input[value="2"][name="alunos[]"]');
    await setValue('#movement-destination','2');
    await submit('form[action$="/movimentar/preview"] button[type="submit"]');
    await page.until(`!!document.querySelector('#movement-preview')`);
    check(fixture().movements.length,0,'Movement preview does not mutate');
    check(await page.evaluate(`document.querySelector('#movement-preview').textContent.includes('Aprovado primeiro') && !document.querySelector('#movement-preview').textContent.includes('Reprovado primeiro')`),true,'Preview includes only the selected pupil');
    await shot('movimentacao-previa');
    const token = await page.evaluate(`document.querySelector('[name="preview_token"]').value`);
    await page.click('form[action$="/movimentar/confirmar"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/confirmar"] button[type="submit"]');await awaitMovement();
    check(fixture().students.map(s=>s.id_turma),[2,1,3,3],'Only selected pupil progresses');
    check(fixture().movements,[{aluno_id:1,tipo:'progressao',ano:2027}],'Progression history is recorded');
    await page.evaluate(`fetch('/aluno/movimentar/confirmar',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({_csrf_token:document.querySelector('[name="_csrf_token"]').value,ano:'2027',confirmar:'1',preview_token:${JSON.stringify(token)}})})`);
    check(fixture().movements.length,1,'Resubmitting confirmation does not duplicate movement');
    await go('/aluno/perfil/1');
    check(await page.evaluate(`document.body.textContent.includes('Histórico de movimentações de série')`),true,'Profile displays movement history');
    await go('/aluno/movimentar?ano=2028&turma=2&tipo=regressao');
    await page.click('form[action$="/movimentar/preview"] input[name="alunos[]"]');
    await setValue('#movement-destination','1');
    await submit('form[action$="/movimentar/preview"] button[type="submit"]');
    await page.until(`!!document.querySelector('#movement-preview')`);
    await page.click('form[action$="/movimentar/confirmar"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/confirmar"] button[type="submit"]');await awaitMovement();
    const final = fixture();
    check(final.students.map(s=>s.id_turma),[1,1,3,3],'Regression works without senior review in the selected year');
    check(final.classes,initial.classes,'Existing class names and school years are preserved');
    check(final.dvas,initial.dvas,'All DVA records are preserved');
    // The explicit whole-class button must also include pupils outside a name search.
    await page.click('form[action$="/movimentar/conferencia"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/conferencia"] button[type="submit"]');await awaitMovement();
    await go('/aluno/movimentar?ano=2028&turma=1&q=Aprovado');
    check(await page.evaluate(`document.querySelectorAll('form[action$="/movimentar/preview"] input[name="alunos[]"]').length`),1,'Name search narrows the visible class roster');
    await setValue('#movement-destination','2');
    await submit('button[name="turma_inteira"]');
    await page.until(`!!document.querySelector('#movement-preview')`);
    check(await page.evaluate(`document.querySelectorAll('#movement-preview tbody tr').length`),2,'Whole-class action previews all active pupils even outside search');
    check(fixture().movements.length,2,'Whole-class preview also remains read-only');
    // Switch identity to exercise authorization at the HTTP boundary as well as in integration tests.
    await page.click('.logout-form button');await page.until(`location.pathname==='/login'`);
    await fill('#email','employee@example.test');await fill('#senha','Teste ficticio seguro 2026');await page.click('.btn-login');
    await page.until(`location.pathname==='/dashboard'`);
    await go('/aluno');
    check(await page.evaluate(`!!document.querySelector('a[href$="/aluno/movimentar"]')`),false,'Employee does not see entry button');
    check(await page.evaluate(`(async()=>{const csrf=document.querySelector('[name="_csrf_token"]').value;
        const paths=['','/series','/conferencia','/preview','/confirmar']; const statuses=[];
        for(const path of paths) statuses.push((await fetch('/aluno/movimentar'+path,{method:path?'POST':'GET',
            ...(path?{headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_csrf_token:csrf,ano:'2027'})}:{}),redirect:'manual'})).status);
        return statuses;})()`),[403,403,403,403,403],'Employee cannot access any movement operation');
    check(browser.errors,[],'No browser JavaScript exceptions');
    check(/PHP (?:Warning|Fatal error|Parse error)/.test(serverErrors),false,'No PHP runtime warnings');
    console.log(`Browser student movements: ${checks} checks passed (fictitious data, desktop/mobile/landscape).`);
} finally {
    await browser?.cleanup();
    if (server && server.exitCode === null) {
        server.kill();await new Promise(done=>{server.once('exit',done);setTimeout(done,2000);});
    }
    if (dirname(resolve(temporary)) !== resolve(tmpdir()) || !basename(temporary).startsWith('gse-movement-browser-')) throw new Error('Unsafe fixture cleanup path');
    await rm(temporary,{recursive:true,force:true,maxRetries:5,retryDelay:200});
}
