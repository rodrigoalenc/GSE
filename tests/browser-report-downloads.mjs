// Real authenticated forms and download bytes, using only disposable synthetic data.
import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {mkdtemp, mkdir, readFile, rm, writeFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {basename, dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {testKeyring} from './browser-keyring.mjs';
import {launchBrowser} from './browser-contract-tabs.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const temporary = await mkdtemp(join(tmpdir(), 'gse-passivo-browser-'));
const sessions = join(temporary, 'sessions'), downloads = join(temporary, 'downloads');
await mkdir(sessions); await mkdir(downloads);
const environment = {...process.env, GSE_KEYRING_PATH:await testKeyring(temporary), APP_ENV:'testing', APP_URL:'', APP_ALLOWED_HOSTS:'', FORCE_HTTPS:'false',
    DB_PATH:join(temporary,'fixture.sqlite'), LOG_PATH:join(temporary,'technical.log'), CERTIDAO_STORAGE_PATH:join(temporary,'certidoes'),
    CERTIDAO_MAIL_ENABLED:'false', MAIL_ENABLED:'false', LOGIN_DELAY_BASE_MS:'0', LOGIN_DELAY_MAX_MS:'0', TRUSTED_PROXIES:''};
const php = process.env.PHP_BINARY || 'php';
let server, browser, serverErrors = '', checks = 0;
const check = (actual, expected, message) => {assert.deepEqual(actual, expected, message); checks++;};
try {
    const seed = spawnSync(php, ['tests/fixtures/passivo-browser.php', 'seed-reports'], {cwd:root, env:environment, encoding:'utf8', windowsHide:true});
    if (seed.status !== 0) throw new Error(seed.stderr || seed.stdout);
    const reservation = createServer();
    await new Promise(done=>reservation.listen(0,'127.0.0.1',done));
    const port = reservation.address().port;
    await new Promise(done=>reservation.close(done));
    const base = `http://127.0.0.1:${port}`;
    environment.APP_URL = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],
        {cwd:root,env:environment,windowsHide:true,stdio:['ignore','ignore','pipe']});
    server.stderr.on('data',chunk=>{serverErrors+=chunk;});
    let ready = false;
    for (let i=0;i<100;i++) {
        try {if ((await fetch(base+'/login')).status===200) {ready=true;break;}} catch {}
        await new Promise(done=>setTimeout(done,50));
    }
    if (!ready) throw new Error(serverErrors);
    browser = await launchBrowser();
    await browser.command('Browser.setDownloadBehavior', {behavior:'allowAndName',downloadPath:downloads,eventsEnabled:true});
    const page = await browser.newPage();
    await page.command('Network.enable');
    await page.navigate(base+'/login');
    for (const [selector,text] of [['#email','browser@example.test'],['#senha','Teste ficticio seguro 2026']]) {
        await page.click(selector); await page.command('Input.insertText',{text});
    }
    await page.click('.btn-login');
    await page.until(`location.pathname==='/dashboard' && document.readyState==='complete'`);
    await page.navigate(base+'/relatorio');
    await page.command('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Report actions fit a mobile screen');
    if (process.env.GSE_BROWSER_EVIDENCE_DIR) {
        await mkdir(process.env.GSE_BROWSER_EVIDENCE_DIR,{recursive:true});
        await page.evaluate(`document.querySelector('.btn-gerar').scrollIntoView({block:'center'})`);
        await page.evaluate('new Promise(done=>requestAnimationFrame(()=>requestAnimationFrame(done)))');
        const {data} = await page.command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        await writeFile(join(process.env.GSE_BROWSER_EVIDENCE_DIR,'report-preview-mobile.png'),Buffer.from(data,'base64'));
    }
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    const form = '[data-report-generator]', main = form+' .btn-gerar', pdfDownload = '[data-report-pdf-download]';
    const idle = async () => {
        await page.until(`!document.querySelector('${form}').hasAttribute('aria-busy') && !document.querySelector('[data-submit-status]')`);
        check(await page.evaluate(`document.body.textContent.includes('Processando…')`),false,'No permanent processing message');
    };
    const began = () => browser.events.filter(e=>e.method==='Browser.downloadWillBegin');
    const waitDownload = async count => {
        for (let i=0;i<300;i++) {
            const start = began()[count-1];
            if (start && browser.events.some(e=>e.method==='Browser.downloadProgress' && e.params.guid===start.params.guid && e.params.state==='completed')) {
                return {event:start.params,bytes:await readFile(join(downloads,start.params.guid))};
            }
            await new Promise(done=>setTimeout(done,50));
        }
        await page.captureFailure('report-download',{count,events:browser.events.filter(e=>e.method.startsWith('Browser.download'))});
        throw new Error('Report download did not complete: '+JSON.stringify({count,downloads:began().map(e=>e.params),
            requests:browser.events.filter(e=>e.method==='Network.requestWillBeSent').slice(-5).map(e=>e.params.request.url),
            state:await page.evaluate(`({url:location.href,busy:document.querySelector('[aria-busy]')?.outerHTML,format:document.querySelector('[name="formato"]:checked')?.value})`)}));
    };
    const reportRequests = () => browser.events.filter(e=>e.method==='Network.requestWillBeSent' && /\/relatorio\/(pdf|csv)\?/.test(e.params.request.url));
    const origin = await page.evaluate('performance.timeOrigin');
    await page.evaluate(`document.querySelector('#turma').value='1'; document.querySelector('#dva').value='vigente';
        document.querySelector('${main}').name='acao'; document.querySelector('${main}').value='gerar';`);
    check(await page.evaluate(`document.querySelector('${main}').textContent`),'Visualizar PDF','PDF is presented as a preview action');
    await page.click(main);
    await page.click(main);
    let previewTarget;
    for (let i=0;i<100;i++) {
        const {targetInfos} = await browser.command('Target.getTargets');
        previewTarget = targetInfos.find(target=>target.type==='page' && target.url.startsWith(base+'/relatorio/pdf?'));
        if (previewTarget) break;
        await new Promise(done=>setTimeout(done,50));
    }
    assert.ok(previewTarget,'PDF opens in a new browser tab'); checks++;
    check(Object.fromEntries(new URL(previewTarget.url).searchParams),{turma:'1',dva:'vigente',ativo:'1',formato:'pdf',acao:'gerar'},'PDF preview preserves filters and submitter name/value');
    const {sessionId:previewSession} = await browser.command('Target.attachToTarget',{targetId:previewTarget.targetId,flatten:true});
    let previewReady = false;
    for (let i=0;i<100;i++) {
        const state = await browser.command('Runtime.evaluate',{expression:'document.contentType',returnByValue:true},previewSession);
        if (state.result.value==='application/pdf') {previewReady=true;break;}
        await new Promise(done=>setTimeout(done,50));
    }
    check(previewReady,true,'New tab displays a PDF document');
    await idle();
    check(began().length,0,'Visualizing a PDF does not download a file');
    check(await page.evaluate('performance.timeOrigin'),origin,'PDF preview keeps the generator page open');
    const {targetInfos:afterPreview} = await browser.command('Target.getTargets');
    check(afterPreview.filter(target=>target.type==='page' && target.url.startsWith(base+'/relatorio/pdf?')).length,1,'Immediate repeated preview click opens one tab');
    await browser.command('Target.closeTarget',{targetId:previewTarget.targetId});

    await page.click(pdfDownload);
    check(await page.evaluate(`document.querySelector('${form}').getAttribute('aria-busy')`),'true','PDF locks the form immediately');
    await page.click(pdfDownload);
    const pdf = await waitDownload(1);
    check(pdf.bytes.subarray(0,5).toString(),'%PDF-','Explicit PDF download button downloads a real PDF');
    await new Promise(done=>setTimeout(done,250));
    check(began().length,1,'Immediate repeated PDF click produces one download');
    check(reportRequests().length,1,'Immediate repeated PDF click sends one request');
    check(Object.fromEntries(new URL(pdf.event.url).searchParams),{turma:'1',dva:'vigente',ativo:'1',formato:'pdf',baixar:'1'},'Explicit PDF download preserves filters and requests attachment');
    await idle();
    check(await page.evaluate('performance.timeOrigin'),origin,'PDF releases without a reload');

    await page.click(`${form} [name="formato"][value="csv"]`);
    check(await page.evaluate(`document.querySelector('${main}').textContent`),'Baixar CSV','Selecting CSV updates the main action');
    check(await page.evaluate(`document.querySelector('${form}').target`),'_self','CSV does not open an empty tab');
    await page.evaluate(`document.querySelector('#turma').value='2'; document.querySelector('#ativo').value='0';`);
    await page.click(main); await page.click(main);
    const csv = await waitDownload(2);
    check(new URL(csv.event.url).pathname,'/relatorio/csv','Main CSV button uses the changed form action');
    check(Object.fromEntries(new URL(csv.event.url).searchParams),{turma:'2',dva:'vigente',ativo:'0',formato:'csv',acao:'gerar'},'New export preserves changed filters');
    check(csv.bytes.toString('utf8').includes('Aluno ficticio 32'),true,'CSV contains the newly selected inactive class');
    check(csv.bytes.toString('utf8').includes('Aluno ficticio 01'),false,'CSV excludes the prior filter results');
    await new Promise(done=>setTimeout(done,250));
    check(began().length,2,'Immediate repeated CSV click produces one new download');
    check(reportRequests().length,2,'Immediate repeated CSV click sends one new request');
    await idle();
    check(await page.evaluate('performance.timeOrigin'),origin,'CSV releases on the original page');

    await page.click(`${form} [name="formato"][value="pdf"]`);
    await page.click('[data-report-csv]');
    const specific = await waitDownload(3);
    check(new URL(specific.event.url).pathname,'/relatorio/csv','Specific CSV button overrides PDF selection');
    check(new URL(specific.event.url).searchParams.get('formato'),'pdf','Native form parameters are retained with the override');
    await idle();
    await page.click(`${form} button[data-submit-download="false"]`);
    await page.until(`location.pathname==='/relatorio' && location.search.includes('turma=2') && document.readyState==='complete'`);
    check(await page.evaluate(`document.querySelector('.report-preview').textContent.includes('2 resultado(s)')`),true,'Preview works after all downloads with the current filters');
    check(began().length,3,'Preview navigates instead of downloading');
    await idle();
    await page.evaluate('history.back()');
    await page.until(`location.pathname==='/relatorio' && location.search==='' && document.readyState==='complete'`);
    await idle();
    await page.click(pdfDownload);
    check((await waitDownload(4)).bytes.subarray(0,5).toString(),'%PDF-','Back navigation leaves the form reusable');
    await idle();
    await page.click(`${form} [name="formato"][value="csv"]`);
    await page.evaluate(`document.querySelector('${main}').focus()`);
    await page.command('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,text:'\r',unmodifiedText:'\r'});
    await page.command('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    check(new URL((await waitDownload(5)).event.url).pathname,'/relatorio/csv','Keyboard Enter uses the selected format');
    await idle();
    await page.evaluate(`document.querySelector('${form}').requestSubmit()`);
    check(new URL((await waitDownload(6)).event.url).pathname,'/relatorio/csv','Submission without a submitter uses the form action');
    await idle();

    await page.navigate(base+'/passivo');
    const exportForm = 'form[data-submit-download="true"]';
    check(await page.evaluate(`document.querySelector('${exportForm}')===null`),true,'Export appears after choosing a box');
    await page.navigate(base+'/passivo?caixa=2&ordem=numero');
    check(await page.evaluate(`!document.querySelector('a[href$="/passivo/ferramentas"]') && document.querySelector('#exportar-caixa').value==='2'`),true,'Selected box offers direct TXT export without tools');
    for (const [width,height] of [[1366,900],[390,844],[844,390]]) {
        await page.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`Direct box export fits ${width}x${height}`);
        if (process.env.GSE_BROWSER_EVIDENCE_DIR) {
            await page.evaluate(`document.querySelector('#passivo-export-form').scrollIntoView({block:'center'})`);
            const {data} = await page.command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            await writeFile(join(process.env.GSE_BROWSER_EVIDENCE_DIR,`passivo-exportar-${width}x${height}.png`),Buffer.from(data,'base64'));
        }
    }
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await page.click(exportForm+' button'); await page.click(exportForm+' button');
    const txt = await waitDownload(7);
    check(new URL(txt.event.url).pathname,'/passivo/exportar','Other annotated download form keeps its action');
    check(txt.bytes.toString().includes('Pasta ficticia'),true,'Other form downloads real TXT content');
    await page.until(`!document.querySelector('${exportForm}').hasAttribute('aria-busy') && !document.querySelector('[data-submit-status]')`);
    const posts = browser.events.filter(e=>e.method==='Network.requestWillBeSent' && new URL(e.params.request.url).pathname==='/passivo/exportar');
    check(posts.length,1,'Other download also blocks immediate repeats');
    check(posts[0].params.request.method,'POST','Other download preserves POST');
    const body = new URLSearchParams(posts[0].params.request.postData);
    check(body.get('caixa'),'2','Other download preserves its selected box');
    check(Boolean(body.get('_csrf_token')),true,'Other download preserves CSRF');
    await page.click(exportForm+' button');
    await waitDownload(8);

    // Exercise bfcache's lifecycle deterministically as well as actual history above.
    // Releasing an older timer must never clear the guard for a later submission.
    await page.navigate(base+'/relatorio');
    await page.click(`${form} [name="formato"][value="csv"]`);
    await page.evaluate(`window.submissionTimes=[]; document.addEventListener('submit',()=>submissionTimes.push(performance.now()))`);
    await page.click(main);
    await waitDownload(9);
    await page.evaluate(`window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}))`);
    await idle();
    await new Promise(done=>setTimeout(done,1000));
    await page.click(main);
    await waitDownload(10);
    await page.evaluate(`new Promise(done=>setTimeout(done,Math.max(0,submissionTimes[0]+3100-performance.now())))`);
    check(await page.evaluate(`document.querySelector('${form}').getAttribute('aria-busy')`),'true','pageshow cancels the older timer without releasing a newer guard');
    await idle();

    const viewDocument = async (selector, type = 'application/pdf') => {
        const href = await page.evaluate(`document.querySelector(${JSON.stringify(selector)}).href`);
        const previousDownloads = began().length;
        await page.click(selector);
        let target;
        for (let i=0;i<100;i++) {
            const {targetInfos} = await browser.command('Target.getTargets');
            target = targetInfos.find(info=>info.type==='page' && info.targetId!==page.targetId && info.url===href);
            if (target) break;
            await new Promise(done=>setTimeout(done,50));
        }
        assert.ok(target,`Document opens in a new tab: ${selector}`); checks++;
        const {sessionId} = await browser.command('Target.attachToTarget',{targetId:target.targetId,flatten:true});
        let contentType;
        for (let i=0;i<100;i++) {
            const {result} = await browser.command('Runtime.evaluate',{expression:'document.contentType',returnByValue:true},sessionId);
            contentType = result.value;
            if (contentType===type) break;
            await new Promise(done=>setTimeout(done,50));
        }
        check(contentType,type,'Document is displayed by the browser');
        check(began().length,previousDownloads,'Viewing a document does not start a download');
        await browser.command('Target.closeTarget',{targetId:target.targetId});
    };
    for (const [path, selector] of [
        ['/certidao','.cert-link-pdf'],
        ['/certidao/detalhes/1','.cert-detail-pdf'],
        ['/certidao/editar/1','.cert-existing-pdf a[target="_blank"]'],
        ['/certidao/arquivadas?ano=todos','.cert-archive-pdf[target="_blank"]'],
        ['/certidao/excluidas','.cert-archive-pdf[target="_blank"]'],
    ]) {
        await page.navigate(base+path);
        await viewDocument(selector);
    }
    await page.navigate(base+'/certidao/detalhes/1');
    await page.click('a[href$="?baixar=1"]');
    check((await waitDownload(11)).bytes,await readFile(join(temporary,'preview.pdf')),'Explicit certificate download preserves the original PDF bytes');
    await page.command('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Certificate preview/download actions fit mobile');
    await page.navigate(base+'/certidao/editar/1');
    check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Existing PDF actions fit the mobile edit form');
    await page.command('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await page.navigate(base+'/certidao/cadastrar');
    await page.command('DOM.setFileInputFiles',{files:[join(temporary,'preview.pdf')],backendNodeId:(await page.command('DOM.describeNode',{
        objectId:(await page.command('Runtime.evaluate',{expression:'document.querySelector("[data-pdf-input]")'})).result.objectId,
    })).node.backendNodeId});
    await page.until(`!document.querySelector('[data-pdf-open]').hidden && document.querySelector('[data-pdf-frame]').src.startsWith('blob:')`);
    await viewDocument('[data-pdf-open]');
    await page.navigate(base+'/contrato/detalhes/1');
    await viewDocument('.contract-print-link','text/html');
    await viewDocument('a[href*="/contrato/imprimir/1?folha="]','text/html');
    check(began().length,11,'Only explicit downloads save files across reports, certificates, attachments and print views');
    check(browser.errors,[],'No JavaScript errors');
    check(/PHP (?:Warning|Fatal error|Parse error)/.test(serverErrors),false,'No PHP warnings');
    console.log(`Browser document preview/downloads: ${checks} checks passed (reports, certificate matrix/details/edit/history, attachment preview, order/note printing and explicit downloads).`);
} finally {
    await browser?.cleanup();
    if (server && server.exitCode===null) {
        server.kill(); await new Promise(done=>{server.once('exit',done);setTimeout(done,2000);});
    }
    if (dirname(resolve(temporary))!==resolve(tmpdir()) || !basename(temporary).startsWith('gse-passivo-browser-')) throw new Error('Unsafe fixture cleanup path');
    await rm(temporary,{recursive:true,force:true,maxRetries:5,retryDelay:200});
}
