import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {createServer} from 'node:http';
import {readFile,mkdir,writeFile} from 'node:fs/promises';
import {resolve,join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {launchBrowser} from './browser-contract-tabs.mjs';

const root=resolve(fileURLToPath(new URL('..',import.meta.url)));
const php=process.env.PHP_BINARY || 'php';
const pages=new Map();
for(const [route,argument] of [['/dashboard','dashboard'],['/aluno/criar','aluno'],['/aluno/dva/1','dva-readonly']]) {
    const fixture=spawnSync(php,['tests/fixtures/usabilidade-browser.php',argument],{cwd:root,encoding:'utf8',windowsHide:true});
    if(fixture.status!==0) throw new Error(fixture.stderr || fixture.stdout);
    pages.set(route,fixture.stdout);
}
const server=createServer(async(req,res)=>{
    const path=new URL(req.url,'http://local').pathname;
    if(path==='/formulario/rascunho') {res.setHeader('Content-Type','application/json');res.end('{"draft":null,"context":"fixture"}');return;}
    if(path.startsWith('/assets/') && !path.includes('..')) {
        try {const data=await readFile(join(root,'public',path));res.setHeader('Content-Type',path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':path.endsWith('.png')?'image/png':'font/woff2');res.end(data);return;} catch {}
    }
    res.setHeader('Content-Type','text/html; charset=utf-8');res.end(pages.get(path) || pages.get('/dashboard'));
});
await new Promise(done=>server.listen(0,'127.0.0.1',done));
const base=`http://127.0.0.1:${server.address().port}`;
let browser;let checks=0;
const check=(actual,expected,message)=>{assert.deepEqual(actual,expected,message);checks++;};
try {
    browser=await launchBrowser();
    const page=await browser.newPage();
    for(const route of pages.keys()) {
        for(const width of [1366,375,320]) {
            await page.command('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
            await page.navigate(base+route);
            await page.until('document.fonts.status === "loaded"');
            if (!(await page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'))) {
                console.log(await page.evaluate(`JSON.stringify([...document.querySelectorAll('body *')].map(el=>({tag:el.tagName,cls:el.className,rect:el.getBoundingClientRect().toJSON()})).filter(item=>item.rect.right>innerWidth+1 || item.rect.left < -1).slice(0,15))`));
            }
            check(await page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'),true,`${route} reflows at ${width}px`);
            check(await page.evaluate('document.querySelector("main").id'),'conteudo-principal','Skip link has a content target');
            if(route==='/aluno/criar') {
                await page.evaluate('document.querySelector("#nome_completo").focus()');
                check(await page.evaluate('parseFloat(getComputedStyle(document.querySelector("#nome_completo")).outlineWidth) >= 2'),true,'Keyboard focus remains visible on form fields');
            }
            if(route==='/aluno/dva/1') {
                check(await page.evaluate('[...document.forms].filter(form=>new URL(form.action).pathname.startsWith("/aluno/dva/")).length'),0,'Health read permission does not expose a write form');
            }
            if(width===375) {
                await page.click('[data-sidebar-toggle]');
                check(await page.evaluate('document.querySelector("[data-sidebar-toggle]").getAttribute("aria-expanded")'),'true','Mobile menu opens');
                check(await page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'),true,'Open menu remains inside mobile viewport');
                await page.click('[data-sidebar-toggle]');
            }
            if(width===320) {
                await page.evaluate('document.querySelector(".skip-link").focus()');
                await page.command('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
                await page.command('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
                await page.until('document.activeElement.id === "conteudo-principal"');
                check(await page.evaluate('document.activeElement.id'),'conteudo-principal','Keyboard skip navigation works');
            }
            if(process.env.GSE_BROWSER_EVIDENCE_DIR) {
                await mkdir(process.env.GSE_BROWSER_EVIDENCE_DIR,{recursive:true});
                const screenshot=await page.command('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
                const evidencePage=route==='/aluno/dva/1'?'dva-consulta':route==='/aluno/criar'?'aluno':'painel';
                await writeFile(join(process.env.GSE_BROWSER_EVIDENCE_DIR,`${evidencePage}-${width}.png`),Buffer.from(screenshot.data,'base64'));
            }
        }
    }
    check(browser.errors,[],'No exceptions in rendered real views');
    console.log(`Real views usability: ${checks} checks passed at desktop, mobile and 320 CSS pixels, including keyboard and menu.`);
} finally {await browser?.cleanup();await new Promise(done=>server.close(done));}
