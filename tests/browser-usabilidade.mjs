import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {launchBrowser} from './browser-contract-tabs.mjs';

let stored = {fields:{nome_completo:'Pessoa recuperada',telefone_aluno:'65999990000'},revision:'3',updated:Math.floor(Date.now()/1000)};
let saved = null;
const script = await readFile(new URL('../public/assets/js/usabilidade.js', import.meta.url)).catch(()=>Buffer.from(''));
const stylesheet = await readFile(new URL('../public/assets/css/usabilidade.css', import.meta.url));
const html = `<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Usabilidade — dados fictícios</title>
<link rel="stylesheet" href="/usabilidade.css"><body data-draft-endpoint="/formulario/rascunho" data-list-context='{"/aluno":"/aluno?q=Pessoa&page=2"}'>
<aside class="sidebar"><button type="button" data-sidebar-toggle aria-controls="gse-main-navigation" aria-expanded="false" hidden>Menu</button><nav id="gse-main-navigation"><a href="/aluno">Alunos</a></nav></aside>
<main id="conteudo-principal"><form id="student" method="post" action="/aluno/editar/4">
<input type="hidden" name="_csrf_token" value="csrf-ficticio"><input type="hidden" name="revisao" value="4">
<label for="nome">Nome completo</label><input id="nome" name="nome_completo" required value="Pessoa atual">
<label for="telefone">Telefone (opcional)</label><input id="telefone" name="telefone_aluno">
<input name="senha" type="password" value="segredo-nao-persistir"><input type="file" name="arquivo_pdf">
<button>Salvar</button><a href="/aluno">Cancelar</a></form></main><script src="/usabilidade.js"></script></body></html>`;
const server = createServer(async (req,res)=>{
    if(req.url.startsWith('/formulario/rascunho')) {
        res.setHeader('Content-Type','application/json');
        if(req.method==='GET') { res.end(JSON.stringify({draft:stored,context:'context-ficticio'})); return; }
        let body=''; for await(const chunk of req) body+=chunk;
        const fields = new URLSearchParams(body);
        if(fields.get('operation')==='discard') stored=null;
        else { saved=JSON.parse(fields.get('fields')); stored={fields:saved,revision:fields.get('revision')}; }
        res.end('{"ok":true}'); return;
    }
    res.setHeader('Content-Type',req.url==='/usabilidade.js'?'text/javascript':req.url==='/usabilidade.css'?'text/css':'text/html');
    res.end(req.url==='/usabilidade.js'?script:req.url==='/usabilidade.css'?stylesheet:html);
});
await new Promise(done=>server.listen(0,'127.0.0.1',done));
let browser;
try {
    browser=await launchBrowser();
    const page=await browser.newPage();
    await page.navigate(`http://127.0.0.1:${server.address().port}/aluno/editar/4`);
    await page.until('document.querySelector("[data-draft-recover]") !== null');
    assert.equal(await page.evaluate('document.querySelector("#nome").value'),'Pessoa atual','Recovery is explicit');
    await page.click('[data-draft-recover]');
    assert.equal(await page.evaluate('document.querySelector("#nome").value'),'Pessoa recuperada');
    assert.equal(await page.evaluate('document.querySelector("[name=revisao]").value'),'4','Old revision is never restored');
    assert.equal(await page.evaluate('document.querySelector("[name=_draft_source_revision]")?.value'),'3','An old draft requires a server comparison before saving');
    assert.equal(await page.evaluate('document.querySelector("[type=password]").value'),'segredo-nao-persistir');
    await page.evaluate(`document.querySelector('#nome').value='Pessoa alterada';document.querySelector('#nome').dispatchEvent(new Event('input',{bubbles:true}))`);
    await page.until('document.querySelector("[data-draft-status]").textContent.includes("Rascunho salvo")');
    assert.equal(saved.nome_completo,'Pessoa alterada');
    assert.equal('senha' in saved,false); assert.equal('_csrf_token' in saved,false); assert.equal('revisao' in saved,false);
    assert.equal(await page.evaluate(`window.dispatchEvent(new Event('beforeunload',{cancelable:true}))`),false,'Leaving a changed form is guarded');
    assert.equal(await page.evaluate('localStorage.length+sessionStorage.length'),0,'No personal fields in browser storage');
    await page.click('[data-draft-discard]');
    await page.until('document.querySelector("[data-draft-status]").textContent.includes("descartado")');
    assert.equal(stored,null);
    assert.equal(await page.evaluate('document.querySelector("#nome").value'),'Pessoa alterada','Discarding a draft does not erase the open form');
    assert.equal(await page.evaluate('document.querySelector("#student a").getAttribute("href")'),'/aluno?q=Pessoa&page=2','Return retains filters');
    await page.command('Emulation.setDeviceMetricsOverride',{width:375,height:812,deviceScaleFactor:1,mobile:true});
    await page.until('innerWidth < 769');
    assert.equal(await page.evaluate('document.querySelector("[data-sidebar-toggle]").hidden'),false,'Mobile navigation can be discovered by its label');
    await page.click('[data-sidebar-toggle]');
    assert.equal(await page.evaluate('document.querySelector("[data-sidebar-toggle]").getAttribute("aria-expanded")'),'true');
    assert.equal(await page.evaluate('document.querySelector("#gse-main-navigation").getBoundingClientRect().height > 0'),true);
    assert.deepEqual(browser.errors,[]);
    console.log('Browser usability: explicit recovery, current revision, autosave, discard, leave guard and storage privacy passed.');
} finally { await browser?.cleanup(); await new Promise(done=>server.close(done)); }
