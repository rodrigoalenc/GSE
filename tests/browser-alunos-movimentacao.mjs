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
    check(await page.evaluate(`[...document.querySelectorAll('[data-movement-choice]')].map(link=>link.querySelector('strong').textContent)`),['Mandar para o passivo','Progredir turma','Regredir turma'],'Entry offers the three requested actions');
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
    await page.click('[data-movement-choice="progressao"]');await awaitMovement();
    check(await page.evaluate(`document.querySelector('#movement-archive-title')===null`),true,'Progression contains no archive form');
    check(await page.evaluate(`[...document.querySelectorAll('select[id^="series-"]')].map(select=>select.id)`),['series-3'],'Only the ambiguous class needs manual identification, with the form already open');
    check(await page.evaluate(`document.querySelector('#movement-destination option[value="2"]').textContent.includes('2º ano do ensino médio')`),true,'Automatically recognized destination is immediately available');
    check(fixture().series,[],'Automatic recognition leaves stored class metadata untouched');
    check(await page.evaluate(`document.querySelector('.movement-controls button[type="submit"]').textContent.trim()`),'Abrir ano','Movement screen renders');
    check(await page.evaluate(`(() => {const section=document.querySelector('#movement-senior-title').closest('section');
        return section.textContent.includes('Identifique as séries antes de concluir a conferência') && !section.textContent.includes('Não há alunos ativos do 3º ano');})()`),true,'Unclassified classes never display a false absence of senior pupils');
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/conferencia"]')===null`),true,'Senior review cannot be attested before class identification');
    for (const [width,height] of [[1366,900],[390,844],[844,390]]) {
        await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await page.evaluate(`document.querySelector('#movement-senior-title').scrollIntoView({block:'start'})`);
        check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`Pending-series warning fits ${width}x${height}`);
        await shot(`movimentacao-series-pendentes-${width}x${height}`);
        await page.evaluate(`document.querySelector('#movement-series-title').scrollIntoView({block:'start'})`);
        check(await page.evaluate(`(()=>{const wrapper=document.querySelector('form[action$="/movimentar/series"] table').parentElement;return wrapper.scrollWidth<=wrapper.clientWidth;})()`),true,`Pending grade selectors fit without horizontal scrolling at ${width}x${height}`);
        await shot(`series-identificar-pendentes-${width}x${height}`);
    }
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await page.click('[data-movement-back]');
    await page.until(`!!document.querySelector('[data-movement-choice="passivo"]')`);
    await page.click('[data-movement-choice="passivo"]');await awaitMovement();
    check(await page.evaluate(`document.querySelector('#movement-senior-title, #movement-series-title, #movement-selection-title, #movement-year')===null`),true,'Archiving contains only the selected action');
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/series"]')===null`),true,'Grade configuration is not forced before class archiving');
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
    const beforeClassSelection = await page.evaluate('performance.timeOrigin');
    await setValue('#movement-archive-class','3');
    await page.evaluate(`document.querySelector('#movement-archive-class').dispatchEvent(new Event('change',{bubbles:true}))`);
    await page.until(`performance.timeOrigin!==${JSON.stringify(beforeClassSelection)} && document.readyState==='complete'`);
    check(await page.evaluate(`document.querySelectorAll('form[action$="/arquivar-lote/selecionar"] input[name="alunos[]"]:checked').length`),2,'Choosing an existing class lists its active pupils preselected without identifying any grade');
    for (const [width,height] of [[1366,900],[390,844],[844,390]]) {
        await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await page.evaluate(`document.querySelector('#movement-archive-title').scrollIntoView({block:'start'})`);
        check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`Class archiving fits ${width}x${height}`);
        check(await page.evaluate(`(()=>{const wrapper=document.querySelector('.movement-archive-table').parentElement;return wrapper.scrollWidth<=wrapper.clientWidth;})()`),true,`Archive pupil names fit without horizontal scrolling at ${width}x${height}`);
        await shot(`passivo-por-turma-${width}x${height}`);
    }
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    // Keep the repeater active by removing just that pupil from the preselected class.
    await page.click('form[action$="/arquivar-lote/selecionar"] input[value="4"][name="alunos[]"]');
    await submit('form[action$="/arquivar-lote/selecionar"] button[type="submit"]');
    await page.until(`location.pathname==='/aluno/arquivar-lote' && !!document.querySelector('[name="tipo_caixa"]')`);
    await page.click('section[aria-labelledby="batch-selection-title"] a');await awaitMovement();
    check(await page.evaluate(`[...document.querySelectorAll('form[action$="/arquivar-lote/selecionar"] input[name="alunos[]"]:checked')].map(input=>input.value)`),['3'],'Returning from box selection preserves the repeater exclusion');
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
    check(await page.evaluate(`document.querySelector('#movement-archive-class').value`),'3','Archive returns to the selected class');
    check(await page.evaluate(`document.querySelectorAll('form[action$="/arquivar-lote/selecionar"] input[name="alunos[]"]:checked').length`),0,'Remaining pupils are not preselected again after archiving');
    check(fixture().students.map(s=>s.ativo),[1,1,0,1],'Graduate is archived and repeater stays active');
    check(await page.evaluate(`new URL(location.href).searchParams.get('tipo')`),'passivo','Archive confirmation stays in the archive action');
    await go('/aluno/movimentar?ano=2027&tipo=progressao');
    await setValue('#series-3','12');
    await submit('form[action$="/movimentar/series"] button[type="submit"]');await awaitMovement();
    check(fixture().series,[{id_turma:3,serie:12}],'Only the ambiguous class receives a manual grade');
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/series"]')===null`),true,'Identifying the only pending class removes the setup form');
    await page.click('a[href*="configurar=1"]');await awaitMovement();
    check(await page.evaluate(`document.querySelector('#series-1').value`),'','Review keeps automatically identified classes automatic');
    check(await page.evaluate(`document.querySelector('#series-2').closest('tr').textContent.includes('Identificada automaticamente: 2º ano do ensino médio')`),true,'Review explains the recognized grade');
    for (const [width,height] of [[1366,900],[390,844],[844,390]]) {
        await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await page.evaluate(`document.querySelector('#movement-series-title').scrollIntoView({block:'start'})`);
        check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`Series review fits ${width}x${height}`);
        check(await page.evaluate(`(()=>{const wrapper=document.querySelector('form[action$="/movimentar/series"] table').parentElement;return wrapper.scrollWidth<=wrapper.clientWidth;})()`),true,`Series correction selectors fit without horizontal scrolling at ${width}x${height}`);
        await shot(`series-revisar-${width}x${height}`);
    }
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await submit('form[action$="/movimentar/series"] button[type="submit"]');await awaitMovement();
    check(fixture().series,[{id_turma:3,serie:12}],'Saving review preserves automatic identification without adding overrides');
    check(await page.evaluate(`document.querySelector('form[action$="/movimentar/preview"] button[type="submit"]').disabled`),true,'Progression is still blocked before senior review');
    await page.click('form[action$="/movimentar/conferencia"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/conferencia"] button[type="submit"]');await awaitMovement();
    await go('/aluno/movimentar?ano=2027&tipo=progressao&turma=1');
    await page.until(`!document.querySelector('[data-movement-select-all]').hidden`);
    await page.click('form[action$="/movimentar/preview"] [data-movement-select-all]');
    check(await page.evaluate(`document.querySelectorAll('form[action$="/movimentar/preview"] input[name="alunos[]"]:checked').length`),2,'Select whole displayed class');
    await page.click('form[action$="/movimentar/preview"] input[value="2"][name="alunos[]"]');
    check(await page.evaluate(`document.querySelector('[data-movement-automatic]').getAttribute('data-movement-automatic')`),'2','Single-class progression identifies the next class without a destination selector');
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
    check(await page.evaluate(`document.querySelector('#movement-senior-title, #movement-archive-title, #movement-type')===null`),true,'Regression has no senior review, archive form, or repeated operation selector');
    for (const [width,height] of [[1366,900],[390,844],[844,390]]) {
        await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await page.evaluate('scrollTo(0,0)');
        check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`Regression fits ${width}x${height}`);
        await shot(`movimentacao-regredir-${width}x${height}`);
    }
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await page.click('form[action$="/movimentar/preview"] input[name="alunos[]"]');
    await setValue('#movement-destination','1');
    await submit('form[action$="/movimentar/preview"] button[type="submit"]');
    await page.until(`!!document.querySelector('#movement-preview')`);
    await page.click('form[action$="/movimentar/confirmar"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/confirmar"] button[type="submit"]');await awaitMovement();
    check(await page.evaluate(`new URL(location.href).searchParams.get('tipo')`),'regressao','Regression confirmation stays in the regression action');
    const final = fixture();
    check(final.students.map(s=>s.id_turma),[1,1,3,3],'Regression works without senior review in the selected year');
    check(final.classes,initial.classes,'Existing class names and school years are preserved');
    check(final.dvas,initial.dvas,'All DVA records are preserved');
    // The explicit whole-class button must also include pupils outside a name search.
    await go('/aluno/movimentar?ano=2028&tipo=progressao');
    await page.click('form[action$="/movimentar/conferencia"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/conferencia"] button[type="submit"]');await awaitMovement();
    await go('/aluno/movimentar?ano=2028&tipo=progressao&turma=1&q=Aprovado');
    check(await page.evaluate(`document.querySelectorAll('form[action$="/movimentar/preview"] input[name="alunos[]"]').length`),1,'Name search narrows the visible class roster');
    check(await page.evaluate(`document.querySelector('#movement-destination')===null`),true,'Whole-class progression also needs no manual destination choice');
    await submit('button[name="turma_inteira"]');
    await page.until(`!!document.querySelector('#movement-preview')`);
    check(await page.evaluate(`document.querySelectorAll('#movement-preview tbody tr').length`),2,'Whole-class action previews all active pupils even outside search');
    check(fixture().movements.length,2,'Whole-class preview also remains read-only');
    let beforeBulk = fixture('bulk-seed');
    await go('/aluno/movimentar?ano=2029&tipo=progressao');
    await page.click('form[action$="/movimentar/conferencia"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/conferencia"] button[type="submit"]');await awaitMovement();
    await page.click('[data-movement-all]');await awaitMovement();
    check(await page.evaluate(`new URL(location.href).searchParams.get('todas')`),'1','All-classes option opens inside progression');
    check(await page.evaluate(`document.querySelectorAll('[data-bulk-class]').length`),2,'All eligible source classes appear together');
    check(await page.evaluate(`[...document.querySelectorAll('[data-bulk-automatic]')].map(element=>element.getAttribute('data-bulk-automatic'))`),['4','3'],'Corresponding section A is resolved among A/B choices and the unique next grade is automatic');
    check(await page.evaluate(`document.querySelector('select[data-bulk-destination], input[name^="destinos["]')===null`),true,'Resolved classes show ready destinations without asking or posting mapping fields');
    check(await page.evaluate(`document.querySelectorAll('input[name="manter[]"]:checked').length`),0,'All eligible pupils progress by default; only repeaters need marking');
    check(await page.evaluate(`document.querySelector('.movement-bulk-remaining').textContent.includes('Reprovado terceiro')`),true,'Existing senior repeater is explicitly retained');
    const partialStatus = await page.evaluate(`(async()=>{const csrf=document.querySelector('[name="_csrf_token"]').value;
        const response=await fetch('/aluno/movimentar/preview',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({_csrf_token:csrf,ano:'2029',tipo:'progressao',todas_turmas:'1','destinos[1]':'2','destinos[2]':'3'})});
        return [response.status,(await response.text()).includes('O formulário de todas as turmas está incompleto')];})()`);
    check(partialStatus,[200,true],'Truncated all-class forms fail closed before generating a preview');
    await page.click('input[name="manter[]"][value="2"]');
    await page.click('input[name="manter[]"][value="6"]');
    check(await page.evaluate(`document.querySelector('[data-bulk-total]').textContent`),'Total: 2 aluno(s) vão progredir.','Retaining repeaters updates the total');
    await submit('form[data-movement-bulk] button[type="submit"]');await awaitMovement();
    check(await page.evaluate(`document.querySelector('#movement-preview').textContent.includes('2º Ano A - EM')`),true,'Server resolves all destinations from the classes without mapping in the request');
    check(fixture().students,beforeBulk.students,'Automatic progression preview is read-only');
    await page.click('#movement-preview .form-actions a');await awaitMovement();
    beforeBulk = fixture('bulk-ambiguous');
    await go('/aluno/movimentar?ano=2029&tipo=progressao&todas=1');
    check(await page.evaluate(`document.querySelectorAll('select[data-bulk-destination]').length`),1,'Only the class without a corresponding section asks for resolution');
    check(await page.evaluate(`document.querySelector('#bulk-destination-1')===null`),true,'Unambiguous A-to-A destination stays automatic even while another class needs a choice');
    await submit('form[data-movement-bulk] button[type="submit"]');await awaitMovement();
    check(await page.evaluate(`!!document.querySelector('[data-movement-bulk]') && !document.querySelector('#movement-preview')`),true,'A missing destination rejects the whole preview');
    check(await page.evaluate(`[...document.querySelectorAll('input[name="manter[]"]:checked')].map(box=>box.value)`),['2','6'],'A rejected preview preserves the repeater choices');
    await setValue('#bulk-destination-2','3');
    for (const [width,height] of [[1366,900],[390,844],[844,390]]) {
        await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await page.command('Input.dispatchMouseEvent',{type:'mouseMoved',x:width-10,y:height-10});
        await page.until(`innerWidth<=768 || document.querySelector('.sidebar').getBoundingClientRect().width<=80`);
        await page.evaluate(`document.querySelector('#movement-all-title').scrollIntoView({block:'start'})`);
        check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`All-class form fits ${width}x${height}`);
        check(await page.evaluate(`(()=>{const panel=document.querySelector('#movement-all');return panel.scrollWidth<=panel.clientWidth;})()`),true,`All-class form content is not clipped at ${width}x${height}`);
        check(await page.evaluate(`(()=>{return [...document.querySelectorAll('[data-bulk-destination], [data-bulk-automatic]')].every(element=>{const r=element.getBoundingClientRect();return r.left>=0 && r.right<=innerWidth;});})()`),true,`Automatic destinations and exception controls fit ${width}x${height}`);
        await shot(`progressao-todas-${width}x${height}`);
    }
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await submit('form[data-movement-bulk] button[type="submit"]');await awaitMovement();
    check(await page.evaluate(`document.querySelector('#movement-preview').textContent.includes('2 aluno(s) vão progredir')`),true,'One preview covers pupils from every selected source class');
    check(fixture().students,beforeBulk.students,'The all-class preview does not change any pupil');
    for (const [width,height] of [[1366,900],[390,844],[844,390]]) {
        await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await page.command('Input.dispatchMouseEvent',{type:'mouseMoved',x:width-10,y:height-10});
        await page.until(`innerWidth<=768 || document.querySelector('.sidebar').getBoundingClientRect().width<=80`);
        await page.evaluate(`document.querySelector('#movement-preview').scrollIntoView({block:'start'})`);
        check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`Combined progression preview fits ${width}x${height}`);
        check(await page.evaluate(`(()=>{const panel=document.querySelector('#movement-preview');return panel.scrollWidth<=panel.clientWidth;})()`),true,`Combined preview content is not clipped at ${width}x${height}`);
        await shot(`progressao-todas-previa-${width}x${height}`);
    }
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await page.click('#movement-preview .form-actions a');await awaitMovement();
    check(await page.evaluate(`[...document.querySelectorAll('input[name="manter[]"]:checked')].map(box=>box.value)`),['2','6'],'Returning from the combined preview preserves the repeater choices');
    await submit('form[data-movement-bulk] button[type="submit"]');await awaitMovement();
    const bulkToken = await page.evaluate(`document.querySelector('[name="preview_token"]').value`);
    await page.click('form[action$="/movimentar/confirmar"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/confirmar"] button[type="submit"]');await awaitMovement();
    const afterBulk = fixture();
    check(afterBulk.students.map(s=>s.id_turma),[4,1,3,3,3,2],'One confirmation keeps section A in section A, advances all classes once and retains all repeaters');
    check(afterBulk.movements.length,4,'Combined progression records individual history');
    check(afterBulk.classes,beforeBulk.classes,'Combined progression preserves all class records');
    check(afterBulk.dvas,beforeBulk.dvas,'Combined progression preserves all DVA records');
    check(await page.evaluate(`new URL(location.href).searchParams.get('todas')`),'1','Confirmation returns to the combined progression view');
    check(await page.evaluate(`[...document.querySelectorAll('input[name="manter[]"]')].map(box=>box.value)`),['2','6'],'Already progressed pupils are not offered for another progression in the same year');
    await page.evaluate(`fetch('/aluno/movimentar/confirmar',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({_csrf_token:document.querySelector('[name="_csrf_token"]').value,ano:'2029',tipo:'progressao',todas_turmas:'1',confirmar:'1',preview_token:${JSON.stringify(bulkToken)}})})`);
    check(fixture().movements.length,4,'Resubmitting a combined confirmation does not duplicate history');
    const beforeStages = fixture('stage-names');
    await go('/aluno/movimentar?ano=2030&tipo=progressao&todas=1');
    check(await page.evaluate(`document.querySelector('[data-bulk-automatic="8"]').closest('[data-bulk-class]').textContent.includes('1º Ano - Ens. Fundamental')`),true,'The fundamental class resolves to second fundamental even with high-school classes present');
    check(await page.evaluate(`document.querySelector('[data-bulk-automatic="10"]').closest('[data-bulk-class]').textContent.includes('1º Ano - Ens. Médio')`),true,'The high-school class resolves to second high school even with fundamental classes present');
    await page.click('form[action$="/movimentar/conferencia"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/conferencia"] button[type="submit"]');await awaitMovement();
    const retainIds = await page.evaluate(`[...document.querySelectorAll('input[name="manter[]"]')].map(box=>box.value).filter(id=>!['7','8'].includes(id))`);
    for (const id of retainIds) await page.click(`input[name="manter[]"][value="${id}"]`);
    await submit('form[data-movement-bulk] button[type="submit"]');await awaitMovement();
    check(await page.evaluate(`document.querySelector('#movement-preview').textContent.includes('2 aluno(s) vão progredir')`),true,'Abbreviated stage names participate in a single combined preview');
    check(fixture().students,beforeStages.students,'Stage-name preview does not change any pupil');
    await page.click('form[action$="/movimentar/confirmar"] input[name="confirmar"]');
    await submit('form[action$="/movimentar/confirmar"] button[type="submit"]');await awaitMovement();
    const afterStages = fixture();
    check(afterStages.students.filter(s=>[7,8].includes(s.id)).map(s=>s.id_turma),[8,10],'Both first-year pupils progress within their own school stage');
    check(afterStages.students.filter(s=>![7,8].includes(s.id)),beforeStages.students.filter(s=>![7,8].includes(s.id)),'All other pupils remain untouched during the stage-name scenario');
    check(afterStages.classes,beforeStages.classes,'Abbreviated and full class names stay unchanged');
    check(afterStages.dvas,beforeStages.dvas,'Stage-name progression preserves every DVA');
    check(afterStages.movements.length,6,'Each stage-name progression records its individual history');
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
