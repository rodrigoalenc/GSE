// Run: node tests/browser-passivo-batch.mjs (PHP and Chrome/Chromium required).
// All database writes use fictitious data in a generated temporary directory.
import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {mkdtemp, mkdir, rm, writeFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {basename, dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {launchBrowser} from './browser-contract-tabs.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const temporary = await mkdtemp(join(tmpdir(), 'gse-passivo-browser-'));
const sessions = join(temporary, 'sessions');
await mkdir(sessions);
const environment = {...process.env, APP_ENV:'testing', APP_URL:'', APP_ALLOWED_HOSTS:'', FORCE_HTTPS:'false',
    DB_PATH:join(temporary,'fixture.sqlite'), LOG_PATH:join(temporary,'technical.log'),
    CERTIDAO_STORAGE_PATH:join(temporary,'certidoes'), CERTIDAO_MAIL_ENABLED:'false', MAIL_ENABLED:'false',
    LOGIN_DELAY_BASE_MS:'0', LOGIN_DELAY_MAX_MS:'0', TRUSTED_PROXIES:''};
const php = process.env.PHP_BINARY || 'php';
const fixture = (mode) => {
    const result = spawnSync(php, ['tests/fixtures/passivo-browser.php', mode], {cwd:root, env:environment, encoding:'utf8', windowsHide:true});
    if (result.status !== 0) throw new Error(result.error?.message || result.stderr || result.stdout || `PHP fixture exited with ${result.status}`);
    return JSON.parse(result.stdout);
};
let server, browser;
let checks = 0;
let serverErrors = '';
const check = (actual, expected, message) => { assert.deepEqual(actual, expected, message); checks++; };
try {
    const initial = fixture('seed');
    const portReservation = createServer();
    await new Promise(done => portReservation.listen(0, '127.0.0.1', done));
    const port = portReservation.address().port;
    await new Promise(done => portReservation.close(done));
    server = spawn(php, ['-d', `session.save_path=${sessions}`, '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'],
        {cwd:root, env:environment, windowsHide:true, stdio:['ignore','ignore','pipe']});
    server.stderr.on('data', chunk => {serverErrors += chunk;});
    const base = `http://127.0.0.1:${port}`;
    let ready = false;
    for (let attempt=0; attempt<100; attempt++) {
        try { if ((await fetch(base+'/login')).status === 200) {ready=true; break;} } catch {}
        await new Promise(done => setTimeout(done, 50));
    }
    if (!ready) throw new Error('Temporary PHP server unavailable: '+serverErrors);
    browser = await launchBrowser();
    const page = await browser.newPage();
    const viewport = (width,height) => page.command('Emulation.setDeviceMetricsOverride', {width,height,deviceScaleFactor:1,mobile:false});
    const go = async (path, selector) => {
        await page.navigate(base+path);
        await page.until(`location.href === ${JSON.stringify(base+path)} && document.readyState === 'complete' && document.querySelector(${JSON.stringify(selector)}) !== null`);
    };
    const selectionReady = () => page.until(`document.readyState === 'complete' && document.querySelector('[data-student-select-page]')?.hidden === false`);
    const fill = async (selector, text) => {
        await page.click(selector);
        await page.command('Input.insertText', {text});
    };
    const ids = () => page.evaluate(`[...new FormData(document.querySelector('[data-student-archive-selection]')).getAll('alunos[]')].sort((a,b)=>Number(a)-Number(b))`);
    const noOverflow = async () => check(await page.evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Page fits viewport');
    const shot = async (name) => {
        if (!process.env.GSE_BROWSER_EVIDENCE_DIR) return;
        await mkdir(process.env.GSE_BROWSER_EVIDENCE_DIR, {recursive:true});
        const {data} = await page.command('Page.captureScreenshot', {format:'png', captureBeyondViewport:false});
        await writeFile(join(process.env.GSE_BROWSER_EVIDENCE_DIR, name+'.png'), Buffer.from(data,'base64'));
    };
    await viewport(1366,768);
    await go('/login', '#email');
    await fill('#email', 'browser@example.test');
    await fill('#senha', 'Teste ficticio seguro 2026');
    await page.click('.btn-login');
    await page.until(`location.pathname === '/dashboard'`);

    // The synthetic tab fixture also runs against the authenticated PHP application.
    const noteState = () => page.evaluate(`[...document.querySelectorAll('[role="tabpanel"]')].map(panel=>({id:panel.id,hidden:panel.hidden,visible:panel.getBoundingClientRect().height>0}))`);
    const noteSelected = id => [1,2,3].map(number=>({id:`folha-${number}`,hidden:number!==id,visible:number===id}));
    await go('/contrato/detalhes/1', '[data-contract-tabs]');
    await page.until(`document.querySelectorAll('[role="tabpanel"]').length===3`);
    check(await noteState(), noteSelected(1), 'Authenticated request starts with three notes and the first selected');
    for (const id of [2,3]) {
        await page.click(`[data-contract-tabs] a[href="#folha-${id}"]`);
        await page.until(`location.hash==='#folha-${id}' && !document.getElementById('folha-${id}').hidden`);
        for (const operation of ['add-product','billing-note']) {
            await page.click(`#folha-${id} a[href="#${operation}-${id}"]`);
            await page.until(`location.hash==='#${operation}-${id}' && document.getElementById('${operation}-${id}').open && document.activeElement.closest('details')?.id==='${operation}-${id}'`);
            check(await noteState(), noteSelected(id), 'Authenticated form keeps its corresponding note visible');
            check(await page.evaluate(`document.getElementById('${operation}-${id}').getBoundingClientRect().height>0`), true, 'Authenticated form is visible');
            await shot(`contrato-nota-${id}-${operation}-1366`);
        }
    }
    await page.click('#folha-3 a[href="#add-product-3"]');
    await page.until(`location.hash==='#add-product-3'`);
    const historyLength = await page.evaluate('history.length');
    await page.click('[data-close-details="add-product-3"]');
    check(await page.evaluate(`document.getElementById('add-product-3').open`), false, 'Authenticated close collapses the product form');
    check(await page.evaluate('location.hash'), '#folha-3', 'Authenticated close replaces the form hash with its note');
    check(await page.evaluate('history.length'), historyLength, 'Authenticated close replaces the history entry');
    check(await page.evaluate('document.activeElement.getAttribute("href")'), '#add-product-3', 'Authenticated close returns focus to the product opener');
    await page.click('#folha-3 a[href="#add-product-3"]');
    await page.until(`document.getElementById('add-product-3').open`);
    check(await noteState(), noteSelected(3), 'Authenticated product form reopens on note three');
    await go('/contrato/detalhes/1#add-product-2', '#add-product-2');
    await page.until(`document.getElementById('add-product-2').open && !document.getElementById('folha-2').hidden`);
    check(await noteState(), noteSelected(2), 'Authenticated direct form URL selects note two');
    await page.evaluate(`location.hash='#%E0%A4%A'`);
    await page.until(`location.hash==='#%E0%A4%A'`);
    check(await noteState(), noteSelected(2), 'Authenticated malformed fragment retains its note');
    await page.evaluate(`location.hash='#missing'`);
    await page.until(`location.hash==='#missing'`);
    check(await noteState(), noteSelected(2), 'Authenticated unknown fragment retains its note');
    await page.click('[data-contract-tabs] a[href="#folha-3"]');
    await page.until(`location.hash==='#folha-3'`);
    await page.click('#folha-3 a[href="#add-product-3"]');
    await page.until(`location.hash==='#add-product-3'`);
    await page.click('[data-contract-tabs] a[href="#folha-2"]');
    await page.until(`location.hash==='#folha-2'`);
    await page.evaluate('history.back()');
    await page.until(`location.hash==='#add-product-3' && !document.getElementById('folha-3').hidden`);
    check(await noteState(), noteSelected(3), 'Authenticated history back restores the internal form and note');
    await page.evaluate('history.forward()');
    await page.until(`location.hash==='#folha-2' && !document.getElementById('folha-2').hidden`);
    check(await noteState(), noteSelected(2), 'Authenticated history forward restores note two');
    await page.click('[data-contract-tabs] a[href="#folha-3"]', 2);
    check(await noteState(), noteSelected(2), 'Authenticated Ctrl click keeps the current note');
    await page.evaluate(`document.querySelector('[role="tab"][aria-selected="true"]').focus()`);
    for (const [key,id] of [['ArrowRight',3],['Home',1],['End',3],['ArrowLeft',2]]) {
        await page.command('Input.dispatchKeyEvent', {type:'keyDown',key,code:key});
        await page.command('Input.dispatchKeyEvent', {type:'keyUp',key,code:key});
        await page.until(`location.hash==='#folha-${id}' && !document.getElementById('folha-${id}').hidden`);
        check(await noteState(), noteSelected(id), 'Authenticated keyboard selects the note');
        check(await page.evaluate('document.activeElement.getAttribute("aria-selected")'), 'true', 'Authenticated keyboard keeps focus on the selected tab');
    }
    await page.command('Page.printToPDF', {printBackground:true});
    check(await noteState(), noteSelected(2), 'Authenticated browser printing restores the selected note');
    await go('/contrato/imprimir/1?folha=2', '.print-toolbar');
    check(await page.evaluate(`document.querySelector('.print-toolbar a').getAttribute('href').endsWith('#folha-2')`), true, 'Authenticated single-note print returns to its selected note');
    await page.click('.print-toolbar a');
    await page.until(`location.pathname==='/contrato/detalhes/1' && location.hash==='#folha-2' && document.readyState==='complete' && document.querySelectorAll('[role="tabpanel"]').length===3 && !document.getElementById('folha-2').hidden`);
    check(await noteState(), noteSelected(2), 'Authenticated print return displays note two');

    await go('/aluno', '[data-student-archive-selection]');
    check(await page.evaluate(`document.querySelector('[data-student-selection-submit]').disabled`), true, 'Empty selection cannot submit');
    await page.click('[data-student-selection-id][value="1"]');
    check(await ids(), ['1'], 'First student selected');
    await shot('passivo-lote-selecao-1366');
    await page.click('.pagination a[aria-label="Página 2"]');
    await page.until(`new URL(location.href).searchParams.get('page') === '2' && document.querySelector('[data-student-selection-id][value="31"]') !== null`);
    await page.until(`document.readyState === 'complete' && !document.querySelector('[data-student-select-page]').hidden`);
    await page.click('[data-student-selection-id][value="31"]');
    await shot('passivo-lote-pagina-2-1366');
    check(await ids(), ['1','31'], 'Selections on different pages submit once each');
    await fill('#q', 'Aluno ficticio 03');
    await page.click('.student-filters button[type="submit"]');
    await page.until(`new URL(location.href).searchParams.get('q') === 'Aluno ficticio 03'`);
    await selectionReady();
    check(await ids(), ['1','31'], 'Filter preserves selected students outside page');
    check(await page.evaluate(`document.querySelector('[data-student-selection-summary]').textContent.includes('2 fora desta página')`), true, 'Off-page selection count visible');
    await noOverflow();
    await page.click('[data-student-selection-submit]');
    await page.until(`location.pathname === '/aluno/arquivar-lote' && document.querySelector('[data-archive-batch-box]') !== null`);
    await page.until(`document.readyState === 'complete' && document.querySelector('#archive-batch-new-box').disabled`);
    check(await page.evaluate(`document.querySelectorAll('.passivo-batch-table tbody tr').length`), 2, 'Both selected students reach box choice');
    check(await page.evaluate(`[...document.querySelector('#archive-batch-existing-box').options].map(o=>o.value)`), ['','2','10'], 'Boxes in ascending numeric order');
    check(await page.evaluate(`document.querySelector('#archive-batch-new-box').disabled`), true, 'Unchosen box field excluded');
    await page.click('#archive-batch-existing-box');
    await page.command('Input.dispatchKeyEvent',{type:'keyDown',key:'ArrowDown',code:'ArrowDown',windowsVirtualKeyCode:40});
    await page.command('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    await page.until(`document.querySelector('#archive-batch-existing-box').value === '2'`);
    await page.click('[data-archive-batch-box] button[type="submit"]');
    await page.until(`document.querySelector('.passivo-batch-confirm-form') !== null`);
    await page.until(`document.readyState === 'complete'`);
    check(await page.evaluate(`[...document.querySelectorAll('.passivo-preview tbody tr td:first-child')].map(n=>n.textContent.trim())`), ['16','17'], 'Numbering follows highest position including inactive folders');
    check(fixture('inspect'), initial, 'Preview writes nothing');
    await page.evaluate(`document.querySelector('#batch-preview-title').scrollIntoView({block:'start'})`);
    await shot('passivo-lote-previa-1366');
    await viewport(390,844);
    await noOverflow();
    await page.click('.passivo-batch-confirm-form input[name="confirmar"]');
    await shot('passivo-lote-previa-390');
    await page.click('.passivo-batch-confirm-form button[type="submit"]');
    await page.until(`location.pathname === '/passivo'`);
    check(await page.evaluate(`new URL(location.href).searchParams.get('caixa')`), '2', 'Success opens destination box');
    const firstResult = fixture('inspect');
    check(firstResult.passivo.slice(-2).map(p=>[p.aluno_origem_id,p.caixa,p.numero]), [[1,'2','16'],[31,'2','17']], 'Correct origins and folders persisted');
    check(firstResult.alunos.filter(a=>[1,31].includes(a.id)).map(a=>a.ativo), [0,0], 'Active students inactivated');
    check(firstResult.dvas, initial.dvas, 'Full DVA history unchanged');
    await go('/aluno?ativo=todos', '[data-student-archive-selection]');
    check(await ids(), [], 'Success clears selection');
    check(await page.evaluate(`document.querySelector('[data-student-selection-id][value="1"]').disabled`), true, 'Already archived student cannot be selected');
    await page.click('[data-student-selection-id][value="2"]');
    await go('/aluno?ativo=todos&page=2', '[data-student-archive-selection]');
    check(await page.evaluate(`document.querySelector('[data-student-selection-id][value="32"]').disabled`), true, 'Previously archived inactive student disabled');
    await page.click('[data-student-selection-id][value="33"]');
    check(await ids(), ['2','33'], 'Active and inactive selection across pages');
    await noOverflow();
    await shot('passivo-lote-selecao-390');
    await page.click('[data-student-selection-submit]');
    await page.until(`location.pathname === '/aluno/arquivar-lote'`);
    await page.until(`document.readyState === 'complete' && document.querySelector('#archive-batch-new-box')?.disabled`);
    await page.click('#archive-batch-box-mode');
    await page.command('Input.dispatchKeyEvent',{type:'keyDown',key:'ArrowDown',code:'ArrowDown',windowsVirtualKeyCode:40});
    await page.command('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    await page.until(`document.querySelector('#archive-batch-box-mode').value === 'nova'`);
    check(await page.evaluate(`document.querySelector('#archive-batch-existing-box').disabled && document.querySelector('#archive-batch-new-box').required`), true, 'New box enables only its input');
    await fill('#archive-batch-new-box', '25');
    await noOverflow();
    await page.click('[data-archive-batch-box] button[type="submit"]');
    await page.until(`document.querySelector('.passivo-batch-confirm-form') !== null`);
    await page.until(`document.readyState === 'complete'`);
    check(await page.evaluate(`[...document.querySelectorAll('.passivo-preview tbody tr td:first-child')].map(n=>n.textContent.trim())`), ['1','2'], 'New box starts at one');
    await page.click('.passivo-batch-confirm-form input[name="confirmar"]');
    await page.click('.passivo-batch-confirm-form button[type="submit"]');
    await page.until(`location.pathname === '/passivo'`);
    const finalResult = fixture('inspect');
    check(finalResult.passivo.slice(-2).map(p=>[p.aluno_origem_id,p.caixa,p.numero]), [[2,'25','1'],[33,'25','2']], 'New box persists both origins');
    check(finalResult.alunos.find(a=>a.id===33), initial.alunos.find(a=>a.id===33), 'Existing inactivation metadata preserved');
    check(finalResult.dvas, initial.dvas, 'DVAs unchanged after both batches');
    await go('/aluno', '[data-student-archive-selection]');
    await page.click('[data-student-select-page]');
    check((await ids()).length, 20, 'Select current page');
    await page.click('[data-student-select-page]');
    check(await ids(), [], 'Unselect current page');
    await page.click('[data-student-selection-id][value="3"]');
    await page.click('[data-student-clear-selection]');
    check(await ids(), [], 'Clear selection');

    await viewport(1366,768);
    await go('/contrato/criar', '[data-contract-builder]');
    const builderSheet = index => `[data-contract-sheets] > [data-contract-sheet]:nth-child(${index+1})`;
    const builderProduct = (sheet,index) => `${builderSheet(sheet)} [data-contract-products] > [data-contract-product]:nth-child(${index+1})`;
    const fillProduct = async (selector,name,unit) => {
        for (const [field,value] of [['nome',name],['quantidade','1'],['preco','2,00']]) await fill(`${selector} input[name$="[${field}]"]`,value);
        await page.click(`${selector} select`);
        for (const key of ['Home',...Array(unit==='Litros'?2:unit==='K'?1:0).fill('ArrowDown'),'Enter']) {
            const keyCode={Home:36,ArrowDown:40,Enter:13}[key];
            await page.command('Input.dispatchKeyEvent', {type:'keyDown',key,code:key,windowsVirtualKeyCode:keyCode});
            await page.command('Input.dispatchKeyEvent', {type:'keyUp',key,code:key,windowsVirtualKeyCode:keyCode});
        }
        await page.until(`document.querySelector(${JSON.stringify(selector+' select')}).value===${JSON.stringify(unit)}`);
    };
    await fill('#titulo','Pedido criado no navegador');
    await fill('#valor','1000,00');
    await fillProduct(builderProduct(0,0),'Produto removido','UN');
    await page.click(`${builderSheet(0)} [data-add-product]`);
    await fillProduct(builderProduct(0,1),'Nota removida','K');
    await page.click('[data-add-sheet]');
    check(await page.evaluate(`document.querySelector('${builderProduct(1,0)} select').value`), 'UN', 'Authenticated cloned note resets the product unit');
    await fillProduct(builderProduct(1,0),'Produto em litros','Litros');
    await page.click('[data-add-sheet]');
    await fillProduct(builderProduct(2,0),'Produto em unidades','UN');
    await page.click(`${builderProduct(0,0)} [data-remove-product]`);
    check(await page.evaluate(`document.querySelector('${builderProduct(0,0)} select').value`), 'K', 'Authenticated product removal preserves the surviving unit');
    await page.click(`${builderSheet(0)} [data-remove-sheet]`);
    check(await page.evaluate(`document.querySelector('${builderProduct(0,0)} select').value`), 'Litros', 'Authenticated note removal preserves the surviving unit');
    await page.click('[data-add-sheet]');
    await fillProduct(builderProduct(2,0),'Produto em quilos','K');
    const submittedEntries = await page.evaluate(`[...new FormData(document.querySelector('[data-contract-builder]').closest('form'))]`);
    check(new Set(submittedEntries.map(([name])=>name)).size, submittedEntries.length, 'Authenticated creation sends unique field names');
    check(submittedEntries.filter(([name])=>/\[(nome|unidade)\]$/.test(name)), [
        ['folhas[0][produtos][0][nome]','Produto em litros'],['folhas[0][produtos][0][unidade]','Litros'],
        ['folhas[1][produtos][0][nome]','Produto em unidades'],['folhas[1][produtos][0][unidade]','UN'],
        ['folhas[2][produtos][0][nome]','Produto em quilos'],['folhas[2][produtos][0][unidade]','K'],
    ], 'Authenticated creation reindexes removed notes and products before submission');
    await page.click('[data-contract-builder] ~ button[type="submit"]');
    await page.until(`location.pathname==='/contrato/detalhes/2' && document.querySelectorAll('[role="tabpanel"]').length===3`);
    check(await page.evaluate(`[...document.querySelectorAll('[role="tabpanel"] .contract-unit-badge')].map(unit=>unit.textContent.trim())`), ['Litros','UN','K'], 'Authenticated PHP creation persists the three submitted product units');
    await shot('contrato-cadastro-tres-notas-1366');
    check(browser.errors, [], 'No JavaScript exceptions');
    check(/PHP (?:Warning|Fatal error|Parse error)/.test(serverErrors), false, 'No PHP runtime warnings');
    console.log(`Browser authenticated contracts and passive batch: ${checks} checks passed (fictitious database, desktop and mobile).`);
} finally {
    await browser?.cleanup();
    if (server && server.exitCode === null) {
        server.kill();
        await new Promise(done => {server.once('exit', done); setTimeout(done,2000);});
    }
    if (dirname(resolve(temporary)) !== resolve(tmpdir()) || !basename(temporary).startsWith('gse-passivo-browser-')) throw new Error('Unsafe fixture cleanup path');
    await rm(temporary, {recursive:true,force:true,maxRetries:5,retryDelay:200});
}
