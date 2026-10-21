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
const evidenceDirectory = process.env.GSE_BROWSER_EVIDENCE_DIR || join(root, '.local-qa', 'ano-letivo');
const temporary = await mkdtemp(join(tmpdir(), 'gse-movement-browser-'));
const sessions = join(temporary, 'sessions');
await mkdir(sessions);
// Emulate the stale CDN response observed after deploying the new action menu.
// Updated assets with a versioned URL must bypass this old, unversioned entry.
const router = join(temporary, 'cached-assets-router.php');
await writeFile(router, `<?php
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/assets/css/alunos-movimentacao.css' && !isset($_GET['v'])) {
    header('Content-Type: text/css');
    header('Cache-Control: public, max-age=14400');
    echo '.movement-panel { min-width: 0; } /* cached stylesheet before action cards */';
    return true;
}
return require ${JSON.stringify(join(root, 'public/index.php').replaceAll('\\', '/'))};
`);
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
    server = spawn(process.env.PHP_BINARY || 'php', ['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${port}`,'-t','public',router],
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
    const awaitMovement = () => page.until(`location.pathname==='/aluno/movimentar' && document.readyState==='complete' && !!document.querySelector('[data-movement-action]')`);
    const submit = async selector => {
        const origin = await page.evaluate('performance.timeOrigin');
        await page.click(selector);
        await page.until(`performance.timeOrigin!==${JSON.stringify(origin)} && document.readyState==='complete'`);
    };
    const shot = async name => {
        await mkdir(evidenceDirectory,{recursive:true});
        // Resizing from the full-width mobile menu animates the desktop sidebar.
        // Capture the settled layout, otherwise landscape evidence shows a transient overlay.
        await page.evaluate('new Promise(done=>requestAnimationFrame(()=>requestAnimationFrame(done)))');
        await page.evaluate('Promise.all(document.getAnimations().map(animation=>animation.finished.catch(()=>{})))');
        const {data} = await page.command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        await writeFile(join(evidenceDirectory,name+'.png'),Buffer.from(data,'base64'));
    };
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await go('/login');
    await fill('#email','movement@example.test');await fill('#senha','Teste ficticio seguro 2026');await page.click('.btn-login');
    await page.until(`location.pathname==='/dashboard'`);
    await go('/aluno');
    check(await page.evaluate(`!!document.querySelector('a[href$="/aluno/movimentar"]')`),true,'Administrator sees entry button');
    await go('/aluno/movimentar?ano=2027');
    check(await page.evaluate(`[...document.querySelectorAll('[data-movement-choice]')].map(link=>link.querySelector('strong').textContent)`),['Mandar para o passivo','Progredir turmas para novo ano letivo','Regredir alunos'],'Entry offers the three requested actions');
    check(await page.evaluate(`document.querySelector('.movement-controls, #movement-senior-title, #movement-selection-title, #movement-archive-title')===null`),true,'Entry shows only the action choice');
    for (const [width,height] of [[1366,900],[390,844],[844,390]]) {
        await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        check(await page.evaluate(`(() => {
            const cards=[...document.querySelectorAll('[data-movement-choice]')];
            return cards.every(card => {
                const [title,description,open]=[...card.children].map(child=>child.getBoundingClientRect());
                return title.bottom<=description.top && description.bottom<=open.top && card.scrollWidth<=card.clientWidth;
            }) && cards.every((card,index) => cards.slice(index+1).every(other => {
                const a=card.getBoundingClientRect(),b=other.getBoundingClientRect();
                return a.right<=b.left || b.right<=a.left || a.bottom<=b.top || b.bottom<=a.top;
            }));
        })()`),true,`Cards remain readable and separate with a stale CDN entry at ${width}x${height}`);
        check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`Action choices fit ${width}x${height}`);
        await shot(`movimentacao-escolher-${width}x${height}`);
    }
    const responsive = async (name, selector) => {
        for (const [width,height] of [[1366,900],[390,844],[844,390]]) {
            await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
            await page.evaluate(selector ? `document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'start',behavior:'instant'})` : 'scrollTo({top:0,behavior:"instant"})');
            check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`${name} fits ${width}x${height}`);
            await shot(`${name}-${width}x${height}`);
        }
        await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    };
    const replay = async (path, values) => page.evaluate(`(async()=>{
        const response=await fetch(${JSON.stringify(path)},{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:new URLSearchParams({...${JSON.stringify(values)},_csrf_token:document.querySelector('[name="_csrf_token"]').value}),redirect:'manual'});
        return response.status;
    })()`);
    await page.click('[data-movement-choice="progressao"]');await awaitMovement();
    check(await page.evaluate(`!!document.querySelector('#movement-series-title')`),true,'Missing grade configuration appears automatically');
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/preview"] button').disabled`),true,'Annual progression is blocked while grades are unidentified');
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/conferencia"], input[name="alunos[]"]')===null`),true,'Annual progression has no separate senior review or individual pupil selection');
    await responsive('movimentacao-series-pendentes','#movement-series-title');
    for (const [id,serie] of [[1,'10'],[2,'11'],[3,'12']]) await setValue(`#series-${id}`,serie);
    await submit('form[action$="/movimentar/series"] button[type="submit"]');await awaitMovement();
    check(fixture().students,initial.students,'Identifying grades does not move pupils');
    check(fixture().classes,initial.classes,'Identifying grades preserves existing class names and years');
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/preview"] button').disabled`),true,'Annual progression waits for archiving senior graduates');
    // Every mutation route rejects GET and an invalid CSRF token.
    for (const path of ['/aluno/movimentar/series','/aluno/movimentar/conferencia','/aluno/movimentar/preview','/aluno/movimentar/confirmar','/aluno/regredir']) {
        const statuses = await page.evaluate(`(async()=>{
            const path=${JSON.stringify(path)};
            return [(await fetch(path,{redirect:'manual'})).status,
                (await fetch(path,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'_csrf_token=invalid&ano=2027'})).status];
        })()`);
        check(statuses,[405,419],`${path}: method and CSRF protections`);
    }
    await go('/aluno/movimentar?ano=2027&tipo=passivo');
    check(await page.evaluate(`document.querySelector('#movement-series-title, #movement-selection-title, #movement-annual-title')===null`),true,'Configured archive screen contains only archiving');
    const beforeClassSelection = await page.evaluate('performance.timeOrigin');
    await setValue('#movement-archive-class','3');
    await page.evaluate(`document.querySelector('#movement-archive-class').dispatchEvent(new Event('change',{bubbles:true}))`);
    await page.until(`performance.timeOrigin!==${JSON.stringify(beforeClassSelection)} && document.readyState==='complete'`);
    check(await page.evaluate(`document.querySelectorAll('form[action$="/arquivar-lote/selecionar"] input[name="alunos[]"]:checked').length`),2,'The senior class starts with both pupils selected');
    check(await page.evaluate(`/^[a-f0-9]{64}$/.test(document.querySelector('[name="encerramento_fingerprint"]').value)`),true,'Senior archiving carries its class closure fingerprint');
    await responsive('passivo-por-turma','#movement-archive-title');
    check(await page.evaluate(`(()=>{const wrapper=document.querySelector('.movement-archive-table').parentElement;return wrapper.scrollWidth<=wrapper.clientWidth;})()`),true,'Archive pupil names fit without horizontal scrolling');
    await page.click('form[action$="/arquivar-lote/selecionar"] input[value="4"][name="alunos[]"]');
    await submit('form[action$="/arquivar-lote/selecionar"] button[type="submit"]');
    await page.until(`location.pathname==='/aluno/arquivar-lote' && !!document.querySelector('[name="tipo_caixa"]')`);
    await page.click('section[aria-labelledby="batch-selection-title"] a');await awaitMovement();
    check(await page.evaluate(`[...document.querySelectorAll('form[action$="/arquivar-lote/selecionar"] input[name="alunos[]"]:checked')].map(input=>input.value)`),['3'],'Returning from box selection preserves the repeater exclusion');
    check(await page.evaluate(`!!document.querySelector('[name="encerramento_fingerprint"]')`),true,'Returning to class selection retains closure context');
    await submit('form[action$="/arquivar-lote/selecionar"] button[type="submit"]');
    await page.until(`location.pathname==='/aluno/arquivar-lote' && !!document.querySelector('[name="tipo_caixa"]')`);
    await setValue('[name="tipo_caixa"]','nova');
    await page.evaluate(`document.querySelector('[name="tipo_caixa"]').dispatchEvent(new Event('change',{bubbles:true}))`);
    await fill('#archive-batch-new-box','CONCLUINTES 2027');
    const beforeArchivePreview=fixture();
    await submit('form[action$="/arquivar-lote/preview"] button[type="submit"]');
    await page.until(`!!document.querySelector('form[action$="/arquivar-lote/confirmar"]')`);
    check(fixture(),beforeArchivePreview,'Archive preview does not alter students, DVAs, classes, movements, or audit');
    await responsive('passivo-previa');
    const archiveValues=await page.evaluate(`Object.fromEntries(new FormData(document.querySelector('form[action$="/arquivar-lote/confirmar"]')))`);
    archiveValues.confirmar='1';
    await page.click('form[action$="/arquivar-lote/confirmar"] input[name="confirmar"]');
    await submit('form[action$="/arquivar-lote/confirmar"] button[type="submit"]');await awaitMovement();
    check(await page.evaluate(`new URL(location.href).searchParams.get('ano')`),'2027','Archive returns to the selected annual cycle');
    check(await page.evaluate(`document.querySelector('#movement-archive-class').value`),'3','Archive returns to the selected class');
    check(await page.evaluate(`document.querySelectorAll('form[action$="/arquivar-lote/selecionar"] input[name="alunos[]"]:checked').length`),0,'Remaining pupils stay unselected after archiving');
    check(fixture().students.map(s=>s.ativo),[1,1,0,1],'Graduate is archived and repeater stays active');
    check(fixture().audit.filter(event=>event.action==='student.senior_class_closed').length,1,'Archive confirmation also records the senior class closure');
    const afterArchive=fixture();
    await replay('/aluno/arquivar-lote/confirmar',archiveValues);
    check(fixture(),afterArchive,'Archive confirmation replay creates no duplicate passive record or closure');
    await go('/aluno/movimentar?ano=2027&tipo=progressao');
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/conferencia"], input[name="alunos[]"]')===null`),true,'Senior archiving releases annual progression without another review or pupil checklist');
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/preview"] button').disabled`),false,'Annual progression is available after the senior class closure');
    check(await page.evaluate(`document.querySelector('#annual-target-1').value`),'2','The unique next-grade class is preselected');
    await responsive('progressao-anual');
    const beforeAnnualPreview=fixture();
    await submit('form[action$="/movimentar/preview"] button[type="submit"]');
    await page.until(`!!document.querySelector('#movement-preview')`);
    check(fixture(),beforeAnnualPreview,'Annual preview remains read-only');
    check(await page.evaluate(`[...document.querySelectorAll('#movement-preview tbody tr')].map(row=>[...row.cells].map(cell=>cell.textContent.trim()))`),
        [['TURMA EXISTENTE A — 2026','TURMA EXISTENTE B — 2026','2']],'Annual preview summarizes the source class, target class and total pupils');
    check(await page.evaluate(`document.querySelector('#movement-preview').textContent.includes('Aprovado primeiro')`),false,'Annual preview does not require an individual pupil conference');
    await responsive('progressao-previa','#movement-preview');
    const token=await page.evaluate(`document.querySelector('[name="preview_token"]').value`);
    await page.click('form[action$="/movimentar/confirmar"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/confirmar"] button[type="submit"]');await awaitMovement();
    check(fixture().students.map(s=>s.id_turma),[2,2,3,3],'Annual progression advances both first-year pupils and keeps the senior repeater');
    check(fixture().movements,[{aluno_id:1,tipo:'progressao',ano:2027},{aluno_id:2,tipo:'progressao',ano:2027}],'Annual progression records each moved pupil');
    check(await page.evaluate(`document.body.textContent.includes('A progressão geral deste ano já foi realizada') && !document.querySelector('form[action$="/movimentar/preview"]')`),true,'The annual cycle is visibly completed after confirmation');
    await responsive('progressao-concluida');
    const afterAnnual=fixture();
    await replay('/aluno/movimentar/confirmar',{ano:'2027',tipo:'progressao',confirmar:'1',preview_token:token});
    check(fixture(),afterAnnual,'Annual confirmation replay does not duplicate movement history or audit');
    await go('/aluno/perfil/1');
    check(await page.evaluate(`document.body.textContent.includes('Histórico de movimentações de série')`),true,'Profile displays annual movement history');
    await go('/aluno');
    check(await page.evaluate(`document.querySelector('[data-student-regress-submit]').hidden`),true,'Regression action starts hidden until pupils are selected');
    await page.click('input[data-student-selection-id][value="2"]');
    await page.until(`!document.querySelector('[data-student-regress-submit]').hidden`);
    check(await page.evaluate(`new URL(document.querySelector('[data-student-regress-submit]').formAction).pathname`),'/aluno/regredir','Management regression button uses its protected POST route');
    await submit('[data-student-regress-submit]');await awaitMovement();
    check(await page.evaluate(`[...document.querySelectorAll('form[action$="/movimentar/preview"] input[name="alunos[]"]:checked')].map(input=>input.value)`),['2'],'Regression opens with only the selected pupil checked');
    check(await page.evaluate(`document.querySelector('#movement-annual-title, #movement-archive-title, form[action$="/movimentar/conferencia"]')===null`),true,'Regression shows the selected pupils without senior review or annual progression');
    await responsive('movimentacao-regredir','#movement-selection-title');
    const beforeRegressionPreview=fixture();
    await submit('form[action$="/movimentar/preview"] button[type="submit"]');
    await page.until(`!!document.querySelector('#movement-preview')`);
    check(fixture(),beforeRegressionPreview,'Regression preview remains read-only');
    check(await page.evaluate(`[...document.querySelectorAll('#movement-preview tbody tr')].map(row=>[...row.cells].map(cell=>cell.textContent.trim()))`),
        [['Reprovado primeiro','TURMA EXISTENTE A — 2026','TURMA EXISTENTE B — 2026']],'Regression resolves the former class from recorded history');
    await responsive('regressao-previa','#movement-preview');
    const regressionToken=await page.evaluate(`document.querySelector('[name="preview_token"]').value`);
    await page.click('form[action$="/movimentar/confirmar"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/confirmar"] button[type="submit"]');
    await page.until(`location.pathname==='/aluno' && document.readyState==='complete'`);
    const final=fixture();
    check(final.students.map(s=>s.id_turma),[2,1,3,3],'Only the selected pupil regresses; progressed and retained pupils keep their classes');
    check(final.students.map(s=>s.ativo),[1,1,0,1],'Regression keeps the selected pupil active');
    check(final.movements,[{aluno_id:1,tipo:'progressao',ano:2027},{aluno_id:2,tipo:'progressao',ano:2027},{aluno_id:2,tipo:'regressao',ano:2027}],'Regression adds history without replacing annual progression');
    check(final.classes,initial.classes,'Existing class names and school years are preserved');
    check(final.dvas,initial.dvas,'All DVA records are preserved');
    await replay('/aluno/movimentar/confirmar',{ano:'2027',tipo:'regressao',confirmar:'1',preview_token:regressionToken});
    check(fixture(),final,'Regression confirmation replay changes no pupil or historical record');
    await go('/aluno/movimentar?ano=2027&tipo=progressao');
    await replay('/aluno/movimentar/preview',{ano:'2027',tipo:'progressao','destinos[1]':'2','destinos[2]':'3'});
    check(fixture(),final,'A completed annual cycle cannot be applied again after a regression');
    await go('/auditoria');
    const auditText=await page.evaluate(`document.querySelector('#corpoLogs').textContent`);
    for (const text of ['Progredir Aluno','Regredir Aluno','Alunos - Progredir Ano Letivo','Aprovado primeiro','Reprovado primeiro','TURMA EXISTENTE A','TURMA EXISTENTE B']) {
        check(auditText.includes(text),true,`Audit table identifies ${text}`);
    }
    check(auditText.includes('Evento não identificado'),false,'Movement audit contains no unidentified action');
    await responsive('movimentacao-auditoria');
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
        statuses.push((await fetch('/aluno/regredir',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_csrf_token:csrf,'alunos[]':'2'}),redirect:'manual'})).status);
        return statuses;})()`),[403,403,403,403,403,403],'Employee cannot access any movement operation');
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
