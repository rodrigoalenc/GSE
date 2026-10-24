import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {readFile, mkdir, writeFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {launchBrowser} from './browser-contract-tabs.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const php = process.env.PHP_BINARY || 'php';
const fixture = spawnSync(php, ['tests/fixtures/pedido-form-browser.php'], {cwd:root, encoding:'utf8', windowsHide:true});
if (fixture.status !== 0) throw new Error(fixture.error?.message || fixture.stderr || fixture.stdout);
const assets = new Map();
for (const [url, path] of [['/app.js','public/assets/js/app.js'], ['/pedidos.js','public/assets/js/pedidos-usabilidade.js'],
    ['/bootstrap.css','public/assets/vendor/bootstrap/css/bootstrap.min.css'], ['/painel.css','public/assets/css/painel.css'], ['/modulo5.css','public/assets/css/modulo5.css'],
    ['/app.css','public/assets/css/contratos.css'], ['/pedidos.css','public/assets/css/pedidos-usabilidade.css']]) {
    assets.set(url, await readFile(join(root,path)).catch(() => ''));
}
const submissions = [];
const server = createServer(async (request,response) => {
    if (request.method === 'POST') {
        const chunks = []; for await (const chunk of request) chunks.push(chunk);
        submissions.push(Object.fromEntries(new URLSearchParams(Buffer.concat(chunks).toString('utf8'))));
        response.setHeader('Content-Type','text/html; charset=utf-8'); response.end('<p data-test-submitted>Pedido fictício recebido</p>'); return;
    }
    const path = new URL(request.url,'http://localhost').pathname;
    if (assets.has(path)) {
        response.setHeader('Content-Type', path.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'text/css; charset=utf-8');
        response.end(assets.get(path));
    } else {
        response.setHeader('Content-Type','text/html; charset=utf-8'); response.end(fixture.stdout);
    }
});
await new Promise(done=>server.listen(0,'127.0.0.1',done));
const base = `http://127.0.0.1:${server.address().port}/`;
let browser, checks = 0;
const check = (actual, expected, message) => { assert.deepEqual(actual,expected,message); checks++; };
try {
    browser = await launchBrowser();
    const page = await browser.newPage();
    await page.navigate(base);
    const state = () => page.evaluate(`[...document.querySelectorAll('[data-pedido-step]')].map(step=>!step.hidden)`);
    check(await state(),[true,false,false],'O assistente começa apenas nos dados gerais');
    await page.click('[data-pedido-next]');
    check(await page.evaluate('document.activeElement.name'),'titulo','Dados obrigatórios bloqueiam a etapa e recebem foco');
    const fill = (name,value) => page.evaluate(`(() => { const field=document.querySelector('[name='+CSS.escape(${JSON.stringify(name)})+']'); field.value=${JSON.stringify(value)}; field.dispatchEvent(new Event('input',{bubbles:true})); })()`);
    await fill('titulo','Pedido fictício <script>'); await fill('valor','2.500,02');
    await page.click('[data-pedido-next]');
    check(await state(),[false,true,false],'O formato agrupado permite avançar');
    await page.click('[data-pedido-next]');
    check(await page.evaluate('document.activeElement.name'),'folhas[0][produtos][0][nome]','Produto vazio recebe foco na etapa de produtos');
    await fill('folhas[0][produtos][0][nome]','Papel <b>fictício</b>');
    await fill('folhas[0][produtos][0][quantidade]','2'); await fill('folhas[0][produtos][0][preco]','1.250,02');
    await page.click('[data-pedido-next]');
    check(await state(),[false,true,false],'Soma acima do orçamento bloqueia conferência');
    check(await page.evaluate('document.activeElement.name'),'folhas[0][produtos][0][preco]','Excesso de orçamento indica um preço visível');
    await fill('folhas[0][produtos][0][preco]','1.250,01');
    await page.click('[data-pedido-next]');
    check(await state(),[false,false,true],'Valores válidos mostram a conferência');
    check(await page.evaluate(`document.querySelector('[data-pedido-review]').textContent.includes('R$ 2.500,02')`),true,'Resumo usa a soma exata em centavos');
    check(await page.evaluate(`document.querySelector('[data-pedido-review]').textContent.includes('Papel <b>fictício</b>')`),true,'Resumo preserva o texto digitado');
    check(await page.evaluate(`document.querySelector('[data-pedido-review] b')===null`),true,'Texto do resumo não é interpretado como HTML');
    check(await page.evaluate(`document.querySelector('[data-pedido-progress-item][aria-current="step"]').textContent.startsWith('3.')`),true,'A etapa atual é anunciada');
    await page.click('[data-pedido-back]'); await page.click('[data-pedido-back]');
    check(await page.evaluate(`document.querySelector('[name="valor"]').value`),'2.500,02','Voltar preserva dados');
    await page.click('[data-pedido-next]'); await page.click('[data-pedido-next]');
    // A alteração concorrente de um controle oculto deve revelar a etapa antes do erro.
    await fill('titulo','');
    await page.click('[data-contract-create-submit]');
    check(await state(),[true,false,false],'Salvar revela erro que surgiu em uma etapa anterior');
    check(await page.evaluate('document.activeElement.name'),'titulo','O erro oculto recebe foco visível');
    check(await page.evaluate(`window.GseMoney.cents('999.999.999,99')`),99999999999,'Parser cliente mantém o limite de centavos');
    for (const [amount,expected] of [['1250,00',125000],['1.250,5',125050],['1.234.567,89',123456789],['0,01',1]]) {
        check(await page.evaluate(`window.GseMoney.cents(${JSON.stringify(amount)})`),expected,`Parser cliente converte ${amount} em centavos exatos`);
    }
    for (const amount of ['1.25','1,250','1,250.00','-1,00','1.000.000.000,00','99999999999999999999999,99','1,00\r','1,00\u2028']) {
        check(await page.evaluate(`window.GseMoney.cents(${JSON.stringify(amount)})`),null,`Parser cliente rejeita ${amount}`);
    }
    // Teclado: botão Continuar funciona por Enter com foco na ação.
    await fill('titulo','Pedido pelo teclado');
    await page.evaluate(`document.querySelector('[data-pedido-next]').focus()`);
    await page.command('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,text:'\r'});
    await page.command('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    check(await state(),[false,true,false],'Continuar funciona com teclado');
    await fill('valor','999.999.999,99');
    await fill('folhas[0][produtos][0][preco]','999.999.999,99'); await fill('folhas[0][produtos][0][quantidade]','1000000');
    await page.click('[data-pedido-next]');
    check(await state(),[false,true,false],'Multiplicação acima do inteiro seguro bloqueia a conferência');
    check(await page.evaluate('document.activeElement.name'),'folhas[0][produtos][0][preco]','Estouro indica o valor que deve ser revisto');
    await fill('valor','2.500,02'); await fill('folhas[0][produtos][0][preco]','1.250,01'); await fill('folhas[0][produtos][0][quantidade]','2');
    await page.click('[data-pedido-next]');
    const restoredFields = {
        'folhas[0][produtos][1][nome]':'Produto recuperado',
        'folhas[0][produtos][1][quantidade]':'1',
        'folhas[0][produtos][1][preco]':'0,00',
        'folhas[1][observacao]':'Nota recuperada',
        'folhas[1][produtos][0][nome]':'Outra nota recuperada',
        'folhas[1][produtos][0][quantidade]':'1',
        'folhas[1][produtos][0][preco]':'0,00',
    };
    const restoreProducts = () => page.evaluate(`document.querySelector('[data-pedido-wizard]').dispatchEvent(new CustomEvent('gse:restore-products',{detail:{fields:${JSON.stringify(restoredFields)}}}))`);
    await restoreProducts();
    check(await page.evaluate(`document.querySelectorAll('[data-contract-sheet]').length`),2,'Recuperar rascunho recria a segunda nota');
    check(await page.evaluate(`document.querySelectorAll('[data-contract-product]').length`),3,'Recuperar rascunho recria todos os produtos adicionais');
    for (const [name,value] of Object.entries(restoredFields)) await fill(name,value);
    await restoreProducts();
    check(await page.evaluate(`document.querySelectorAll('[data-contract-product]').length`),3,'Recuperar novamente não duplica os campos');
    check(await page.evaluate(`document.getElementsByName('folhas[0][produtos][0][nome]')[0].value`),'Papel <b>fictício</b>','A expansão preserva produtos existentes');
    await page.click('[data-pedido-back]'); await page.click('[data-pedido-next]');
    const noJs = await browser.newPage();
    await noJs.command('Emulation.setScriptExecutionDisabled',{value:true}); await noJs.navigate(base);
    check(await noJs.evaluate(`[...document.querySelectorAll('[data-pedido-step]')].map(step=>step.getClientRects().length>0)`),[true,true,true],'Sem JS todas as etapas permanecem visíveis');
    check(await noJs.evaluate(`document.querySelector('[data-contract-create-submit]').getClientRects().length>0`),true,'Sem JS o envio continua disponível');
    await noJs.evaluate(`Object.entries({'titulo':'Pedido sem JavaScript','valor':'2.500,02','folhas[0][produtos][0][nome]':'Papel fictício','folhas[0][produtos][0][quantidade]':'2','folhas[0][produtos][0][preco]':'1.250,01'}).forEach(([name,value])=>document.getElementsByName(name)[0].value=value)`);
    await noJs.click('[data-contract-create-submit]'); await noJs.until(`!!document.querySelector('[data-test-submitted]')`);
    check(submissions[0].valor,'2.500,02','Sem JS o navegador aceita e envia o orçamento agrupado');
    check(submissions[0]['folhas[0][produtos][0][preco]'],'1.250,01','Sem JS o navegador envia o preço agrupado');
    check(submissions[0]['folhas[0][produtos][0][quantidade]'],'2','Sem JS o formulário envia os produtos');
    await page.command('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    check(await page.evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'O formulário cabe na largura de celular');
    if (process.env.GSE_BROWSER_EVIDENCE_DIR) {
        await mkdir(process.env.GSE_BROWSER_EVIDENCE_DIR,{recursive:true});
        const screenshot = await page.command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        await writeFile(join(process.env.GSE_BROWSER_EVIDENCE_DIR,'pedidos-mobile.png'),Buffer.from(screenshot.data,'base64'));
    }
    await page.click('[data-contract-create-submit]'); await page.until(`!!document.querySelector('[data-test-submitted]')`);
    check(submissions[1].titulo,'Pedido pelo teclado','Após conferir, o assistente envia o título');
    check(submissions[1].valor,'2.500,02','Após conferir, o assistente envia o orçamento sem perder centavos');
    check(submissions[1]['folhas[0][produtos][0][preco]'],'1.250,01','Após conferir, o assistente mantém o preço original');
    check(submissions[1]['folhas[0][produtos][1][nome]'],'Produto recuperado','O envio mantém o produto extra recuperado');
    check(submissions[1]['folhas[1][produtos][0][nome]'],'Outra nota recuperada','O envio mantém a nota extra recuperada');
    check(!!submissions[1]._csrf_token && !!submissions[1]._request_token,true,'O assistente preserva as proteções de envio');
    check(browser.errors,[],'Nenhuma exceção JavaScript');
    console.log(`Pedidos usabilidade: ${checks} verificações passaram em Chrome real.`);
} finally {
    await browser?.cleanup(); await new Promise(done=>server.close(done));
}
