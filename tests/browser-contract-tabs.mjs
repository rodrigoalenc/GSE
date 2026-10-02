// Browser regression tests using a disposable headless Chrome profile and no npm dependencies.
// Run: node tests/browser-contract-tabs.mjs
// Set BROWSER_BINARY if Chrome/Chromium is installed elsewhere.
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {access, mkdtemp, readFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {basename, dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

export async function launchBrowser() {
    const candidates = [process.env.BROWSER_BINARY, 'C:/Program Files/Google/Chrome/Application/chrome.exe',
        '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'].filter(Boolean);
    let binary;
    for (const candidate of candidates) { try { await access(candidate); binary = candidate; break; } catch {} }
    if (!binary) throw new Error('Chrome/Chromium unavailable; set BROWSER_BINARY to run browser tests.');
    const profile = await mkdtemp(join(tmpdir(), 'gse-browser-'));
    const child = spawn(binary, ['--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
        '--remote-debugging-port=0', '--remote-allow-origins=*', `--user-data-dir=${profile}`, 'about:blank'], {stdio: 'ignore', windowsHide: true});
    let socket;
    const cleanup = async () => {
        socket?.close();
        child.kill();
        await new Promise((done) => { if (child.exitCode !== null) done(); else { child.once('exit', done); setTimeout(done, 2000); } });
        if (dirname(resolve(profile)) !== resolve(tmpdir()) || !basename(profile).startsWith('gse-browser-')) {
            throw new Error('Refusing cleanup outside the generated browser profile directory.');
        }
        await rm(profile, {recursive: true, force: true, maxRetries: 5, retryDelay: 200}).catch(() => {});
    };
    try {
        let portFile;
        for (let attempt = 0; attempt < 100; attempt++) {
            try { portFile = await readFile(join(profile, 'DevToolsActivePort'), 'utf8'); break; } catch {}
            await new Promise((done) => setTimeout(done, 100));
        }
        if (!portFile) throw new Error('The isolated headless browser did not start.');
        const [port, path] = portFile.trim().split(/\r?\n/);
        socket = new WebSocket(`ws://127.0.0.1:${port}${path}`);
        await new Promise((done, reject) => { socket.addEventListener('open', done, {once:true}); socket.addEventListener('error', reject, {once:true}); });
        const pending = new Map();
        const errors = [];
        let sequence = 0;
        socket.addEventListener('message', ({data}) => {
            const message = JSON.parse(data);
            if (message.id && pending.has(message.id)) {
                const {resolve, reject, timer} = pending.get(message.id); pending.delete(message.id); clearTimeout(timer);
                if (message.error) reject(new Error(JSON.stringify(message.error))); else resolve(message.result);
            }
            if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails);
        });
        const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Timed out: ${method}`)); }, 15000);
            pending.set(id, {resolve, reject, timer});
            socket.send(JSON.stringify({id, method, params, ...(sessionId ? {sessionId} : {})}));
        });
        const newPage = async () => {
            const {targetId} = await send('Target.createTarget', {url:'about:blank'});
            const {sessionId} = await send('Target.attachToTarget', {targetId, flatten:true});
            const command = (method, params) => send(method, params, sessionId);
            await command('Page.enable'); await command('Runtime.enable');
            const evaluate = async (expression) => {
                const result = await command('Runtime.evaluate', {expression, returnByValue:true, awaitPromise:true, userGesture:true});
                if (result.exceptionDetails) throw new Error(result.exceptionDetails.text + ': ' + result.exceptionDetails.exception?.description);
                return result.result.value;
            };
            const until = async (expression) => {
                for (let attempt=0; attempt<100; attempt++) { if (await evaluate(expression)) return; await new Promise((done)=>setTimeout(done,50)); }
                throw new Error(`Browser condition not satisfied: ${expression}`);
            };
            const navigate = async (url) => { await command('Page.navigate', {url}); await until('document.readyState === "complete"'); };
            const click = async (selector, modifiers = 0) => {
                await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center'})`);
                await evaluate('new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)))');
                const rect = await evaluate(`(() => { const r = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect(); return {x:r.x+r.width/2,y:r.y+r.height/2}; })()`);
                await command('Input.dispatchMouseEvent', {type:'mousePressed', button:'left', clickCount:1, modifiers, ...rect});
                await command('Input.dispatchMouseEvent', {type:'mouseReleased', button:'left', clickCount:1, modifiers, ...rect});
            };
            return {command, evaluate, until, navigate, click, targetId};
        };
        return {newPage, errors, cleanup};
    } catch (error) { await cleanup(); throw error; }
}

async function run() {
    const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
    const appScript = await readFile(process.env.GSE_TEST_APP_JS || join(root, 'public/assets/js/app.js'));
    const html = `<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Contract tab regression</title>
        <style>section{min-height:1800px}h2{padding-top:700px}details{margin:100px 0}</style>
        <script src="/app.js" defer></script><nav data-contract-tabs>${[1,2,3].map(id=>`<a href="#folha-${id}">Nota ${id}</a>`).join('')}</nav>
        ${[1,2,3].map(id=>`<section id="folha-${id}"><h2>Nota ${id}</h2><a href="#add-product-${id}" data-open-details>Adicionar Produto</a>
        <a href="#billing-note-${id}" data-open-details>Faturamento</a><a href="#%E0%A4%A" data-open-details>Invalid fragment</a>
        <details id="add-product-${id}"><summary>Produto</summary><input name="nome"><button data-close-details="add-product-${id}">Fechar</button></details>
        <details id="billing-note-${id}"><summary>Faturamento</summary><input name="data"></details>
        <table><tbody><tr id="item-actions-${id}" class="contract-item-expanded" hidden><td><details id="move-item-${id}"><summary>Movimento</summary><input name="quantidade"></details></td></tr></tbody></table>
        <button data-contract-item-toggle="item-actions-${id}" aria-expanded="false">Editar</button></section>`).join('')}</html>`;
    const server = createServer((request, response) => { response.setHeader('Content-Type', request.url === '/app.js' ? 'text/javascript; charset=utf-8':'text/html; charset=utf-8'); response.end(request.url === '/app.js' ? appScript : html); });
    await new Promise(done=>server.listen(0,'127.0.0.1',done));
    const base = `http://127.0.0.1:${server.address().port}/`;
    let browser;
    let checks=0;
    const check=(value, expected, message)=>{assert.deepEqual(value,expected,message); checks++;};
    try {
        browser=await launchBrowser();
        const page=await browser.newPage();
        const state=()=>page.evaluate(`[...document.querySelectorAll('[role="tabpanel"]')].map(p=>({hidden:p.hidden,visible:p.getBoundingClientRect().height>0}))`);
        const selected=id=>[{hidden:id!==1,visible:id===1},{hidden:id!==2,visible:id===2},{hidden:id!==3,visible:id===3}];
        await page.navigate(base);
        check(await state(), selected(1), 'Initial note');
        await page.click(`[data-contract-tabs] a[href="#folha-2"]`); await page.until(`location.hash === '#folha-2'`);
        await page.evaluate('history.back()'); await page.until(`location.hash === '' && !document.getElementById('folha-1').hidden`);
        check(await state(),selected(1),'Back to base URL restores initial note');
        await page.evaluate('history.forward()'); await page.until(`location.hash === '#folha-2' && !document.getElementById('folha-2').hidden`);
        check(await state(),selected(2),'Forward from base URL restores second note');
        for (const id of [2,3]) {
            await page.click(`[data-contract-tabs] a[href="#folha-${id}"]`);
            await page.until(`location.hash === '#folha-${id}'`);
            for (const form of ['add-product','billing-note']) {
                await page.click(`#folha-${id} a[href="#${form}-${id}"]`);
                await page.until(`location.hash === '#${form}-${id}'`);
                await page.evaluate('new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)))');
                check(await state(), selected(id), 'Internal link retains its ancestor note');
                check(await page.evaluate(`document.getElementById('${form}-${id}').open`),true,'Internal form is open');
                await page.until(`document.activeElement.closest('details')?.id === '${form}-${id}'`);
                check(await page.evaluate(`document.activeElement.closest('details')?.id`),`${form}-${id}`,'Input receives focus');
            }
        }
        await page.click('#folha-3 a[href="#add-product-3"]');
        await page.until(`location.hash === '#add-product-3'`);
        const historyLength = await page.evaluate('history.length');
        await page.click('[data-close-details="add-product-3"]');
        check(await page.evaluate(`document.getElementById('add-product-3').open`),false,'Close button collapses product form');
        check(await state(),selected(3),'Closing form keeps third note');
        check(await page.evaluate('location.hash'),'#folha-3','Closing form removes stale form fragment');
        check(await page.evaluate('history.length'),historyLength,'Closing form replaces existing history entry');
        check(await page.evaluate(`document.activeElement.getAttribute('href')`),'#add-product-3','Close returns focus to opener');
        await page.navigate(base+'#billing-note-2');
        check(await state(),selected(2),'Direct form URL selects its note');
        check(await page.evaluate(`document.getElementById('billing-note-2').open`),true,'Direct form URL opens form');
        check(await page.evaluate(`Math.abs(document.getElementById('billing-note-2').getBoundingClientRect().top)<2`),true,'Direct form URL scrolls to visible form');
        await page.click(`#folha-2 a[href='#%E0%A4%A']`);
        await page.until(`location.hash === '#%E0%A4%A'`);
        check(await state(),selected(2),'Malformed fragment retains current note');
        await page.evaluate(`location.hash='#missing'`); await page.until(`location.hash==='#missing'`);
        check(await state(),selected(2),'Missing target retains note');
        await page.evaluate(`location.hash='#move-item-3'`); await page.until(`document.getElementById('move-item-3').open`);
        check(await state(),selected(3),'Nested editor URL selects note');
        check(await page.evaluate(`document.getElementById('item-actions-3').hidden`),false,'Nested editor row is visible');
        check(await page.evaluate(`document.querySelector('[data-contract-item-toggle="item-actions-3"]').getAttribute('aria-expanded')`),'true','Editor button state');
        await page.click(`[data-contract-tabs] a[href='#folha-2']`); await page.until(`location.hash==='#folha-2'`);
        await page.evaluate('history.back()'); await page.until(`location.hash==='#move-item-3'`);
        check(await state(),selected(3),'History back restores note');
        check(await page.evaluate(`Math.abs(document.getElementById('move-item-3').getBoundingClientRect().top)<2`),true,'History back scrolls to visible nested form');
        await page.evaluate('history.forward()'); await page.until(`location.hash==='#folha-2'`);
        check(await state(),selected(2),'History forward restores note');
        await page.click(`[data-contract-tabs] a[href='#folha-3']`,2);
        await page.evaluate('new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)))');
        check(await state(),selected(2),'Ctrl+click opens a separate tab without changing current note');
        await page.evaluate(`document.querySelector('[aria-selected="true"]').focus()`);
        for (const [key,id] of [['ArrowRight',3],['Home',1],['End',3],['ArrowLeft',2]]) {
            await page.command('Input.dispatchKeyEvent',{type:'keyDown',key,code:key});
            await page.command('Input.dispatchKeyEvent',{type:'keyUp',key,code:key});
            await page.until(`location.hash==='#folha-${id}'`);
            check(await state(),selected(id),'Keyboard selects note');
            check(await page.evaluate(`document.activeElement.getAttribute('aria-selected')`),'true','Keyboard focus stays on selected tab');
        }
        await page.evaluate(`location.hash='#billing-note-2'`); await page.until(`location.hash==='#billing-note-2'`);
        await page.evaluate('new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)))');
        await page.command('Page.printToPDF',{printBackground:true});
        check(await state(),selected(2),'Actual browser PDF print restores selected note');
        await page.evaluate(`window.dispatchEvent(new Event('beforeprint'))`);
        check(await state(),selected(0).map(()=>({hidden:false,visible:true})),'All notes visible while printing');
        await page.evaluate(`window.dispatchEvent(new Event('afterprint'))`);
        check(await state(),selected(2),'After printing selected note is restored');
        await page.evaluate(`location.hash='#invalid'; window.dispatchEvent(new Event('beforeprint')); window.dispatchEvent(new Event('afterprint'))`);
        check(await state(),selected(2),'Print with invalid hash retains note');
        check(browser.errors,[],'No browser JavaScript exceptions');
        console.log(`Browser contract navigation: ${checks} checks passed (real Chrome clicks, history, keyboard, visibility and print lifecycle).`);
    } finally { await browser?.cleanup(); await new Promise(done=>server.close(done)); }
}

if (process.argv[1] && resolve(process.argv[1])===fileURLToPath(import.meta.url)) {
    run().catch(error=>{console.error(error); process.exitCode=1;});
}
