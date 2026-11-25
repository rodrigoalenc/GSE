// Run: node tests/browser-etiquetas.mjs
import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {mkdir, mkdtemp, rm, writeFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {launchBrowser} from './browser-contract-tabs.mjs';
import {testKeyring} from './browser-keyring.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const temporary = await mkdtemp(join(tmpdir(), 'gse-passivo-browser-'));
const sessions = join(temporary, 'sessions');
await mkdir(sessions);
const evidence = process.env.GSE_BROWSER_EVIDENCE_DIR;
const php = process.env.PHP_BINARY || 'php';
const environment = {
    ...process.env, GSE_KEYRING_PATH: await testKeyring(temporary), APP_ENV: 'testing',
    APP_URL: '', APP_ALLOWED_HOSTS: '', FORCE_HTTPS: 'false',
    DB_PATH: join(temporary, 'fixture.sqlite'), LOG_PATH: join(temporary, 'technical.log'),
    CERTIDAO_STORAGE_PATH: join(temporary, 'certidoes'), CERTIDAO_MAIL_ENABLED: 'false',
    MAIL_ENABLED: 'false', LOGIN_DELAY_BASE_MS: '0', LOGIN_DELAY_MAX_MS: '0', TRUSTED_PROXIES: '',
};

let server, browser, serverErrors = '';
function fixture(mode) {
    const result = spawnSync(php, ['tests/fixtures/passivo-browser.php', mode], {
        cwd: root, env: environment, encoding: 'utf8', windowsHide: true,
    });
    assert.equal(result.status, 0, result.error?.message || result.stderr || result.stdout);
    return JSON.parse(result.stdout);
}

try {
    const initial = fixture('seed');
    const reservation = createServer();
    await new Promise(done => reservation.listen(0, '127.0.0.1', done));
    const port = reservation.address().port;
    await new Promise(done => reservation.close(done));
    const base = environment.APP_URL = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d', `session.save_path=${sessions}`, '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], {
        cwd: root, env: environment, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'],
    });
    server.stderr.on('data', value => { serverErrors += value; });
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
        try {
            if ((await fetch(base + '/login')).ok) {
                ready = true;
                break;
            }
        } catch {}
        await new Promise(done => setTimeout(done, 50));
    }
    assert.ok(ready, 'Temporary PHP server unavailable: ' + serverErrors);
    browser = await launchBrowser();
    const page = await browser.newPage();
    await page.command('Network.enable');

    const viewport = (width, height) => page.command('Emulation.setDeviceMetricsOverride', {
        width, height, deviceScaleFactor: 1, mobile: false,
    });
    const input = async (id, text) => {
        await page.click('#' + id);
        await page.command('Input.insertText', {text});
    };
    const values = ids => page.evaluate(`(${JSON.stringify(ids)}).map(id => document.getElementById(id).value)`);
    const upload = async (name, content) => {
        const path = join(temporary, name);
        await writeFile(path, content);
        const {root: {nodeId}} = await page.command('DOM.getDocument');
        const uploadInput = await page.command('DOM.querySelector', {nodeId, selector: '#etiquetas-arquivo'});
        await page.command('DOM.setFileInputFiles', {files: [path], nodeId: uploadInput.nodeId});
    };
    const printA4 = async (name, pages) => {
        const {data} = await page.command('Page.printToPDF', {preferCSSPageSize: true});
        const pdf = Buffer.from(data, 'base64');
        const mediaBoxes = [...pdf.toString('latin1').matchAll(/\/MediaBox\s+\[0 0 ([\d.]+) ([\d.]+)\]/g)];
        assert.equal(mediaBoxes.length, pages, name + ' has the expected number of pages');
        for (const [, width, height] of mediaBoxes) {
            assert.ok(Math.abs(Number(width) - 595.28) < 1, name + ' A4 print width');
            assert.ok(Math.abs(Number(height) - 841.89) < 1, name + ' A4 print height');
        }
        if (evidence) {
            await mkdir(evidence, {recursive: true});
            await writeFile(join(evidence, name + '.pdf'), pdf);
        }
    };

    await viewport(1366, 768);
    await page.navigate(base + '/login');
    await input('email', 'employee@example.test');
    await input('senha', 'Teste ficticio seguro 2026');
    await page.click('.btn-login');
    await page.until("location.pathname === '/dashboard'");
    await page.navigate(base + '/etiqueta');
    assert.deepEqual(await page.evaluate("[...document.querySelectorAll('main a')].map(a => new URL(a.href).pathname)"),
        ['/etiqueta/pasta', '/etiqueta/caixa'], 'The index links to both editors');

    await page.click('main a[href$="/etiqueta/pasta"]');
    await page.until("location.pathname === '/etiqueta/pasta' && document.readyState === 'complete'");
    assert.deepEqual(await page.evaluate("[...document.querySelectorAll('.etiquetas-historico tbody')].map(body => body.rows.length)"),
        [12, 12], 'Both student labels have a 12-row history');
    await input('ficha-1-estudante', 'Ana Local Um');
    await input('ficha-2-estudante', 'Bia Local Dois');
    await input('ficha-1-telefone', '67987654321');
    await input('ficha-2-telefone', '6733334444');
    await input('ficha-1-cpf', '12345678901');
    await input('ficha-1-sus', '123456789012345');
    await input('ficha-1-nascimento', '21052012');
    assert.deepEqual(await values(['ficha-1-telefone', 'ficha-2-telefone', 'ficha-1-cpf', 'ficha-1-sus', 'ficha-1-nascimento']),
        ['(67) 98765-4321', '(67) 3333-4444', '123.456.789-01', '123 4567 8901 2345', '21/05/2012'],
        'Phone, CPF, SUS and date masks match their printed formats');
    assert.deepEqual(await page.evaluate(`[1, 2].map(id =>
        document.querySelector('#ficha-' + id + '-estudante + [data-etiquetas-valor]').textContent)`),
        ['Ana Local Um', 'Bia Local Dois'], 'The two student print values remain independent');
    await printA4('etiquetas-pasta', 1);

    await page.evaluate("window.confirm = () => false; document.querySelector('[data-etiquetas-reset]').click()");
    assert.deepEqual(await values(['ficha-1-estudante', 'ficha-2-estudante']), ['Ana Local Um', 'Bia Local Dois'],
        'Cancelling reset preserves both student labels');
    await page.evaluate("window.confirm = () => true; document.querySelector('[data-etiquetas-reset]').click()");
    assert.deepEqual(await values(['ficha-1-estudante', 'ficha-2-estudante', 'ficha-1-telefone']), ['', '', ''],
        'Confirming reset clears both student labels');
    await viewport(390, 844);
    assert.ok(await page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Student editor fits the mobile viewport');

    await page.navigate(base + '/etiqueta/caixa');
    await viewport(1366, 768);
    await upload('Caixa_Ficticia.TXT', '  \r\n2024-2025 - Ana\r\n<img>Nome Literal</img>\r\nBia-Teste');
    await page.until("document.getElementById('caixa-titulo').value === 'CAIXA FICTICIA'");
    const txt = '2024.2025 - Ana\n<img>Nome Literal</img>\nBia.Teste';
    assert.deepEqual(await values(['caixa-registros', 'livro-registros', 'livro-titulo']), [txt, txt, 'CAIXA FICTICIA'],
        'TXT replaces the first hyphen and keeps markup literal for both labels');
    assert.equal(await page.evaluate("document.querySelector('[data-etiquetas-valor=caixa-registros] img')"), null,
        'Imported markup is rendered as text in the print copy');

    await upload('Caixa_Malformada.XML', '<invalid><record>');
    await page.until("document.querySelector('[data-etiquetas-status]').textContent.includes('inválido')");
    assert.deepEqual(await values(['caixa-registros', 'livro-registros', 'livro-titulo']), [txt, txt, 'CAIXA FICTICIA'],
        'A malformed import preserves the current labels');

    await upload('Caixa_Documentos.XML',
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" ' +
        'xmlns:x="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' +
        '<w:t> Ignore </w:t><w:t> 2022-2023 - José </w:t><x:t>2021-2022 - Maria</x:t></w:document>');
    await page.until("document.getElementById('caixa-titulo').value === 'CAIXA DOCUMENTOS'");
    const xml = '2022.2023 - José\n2021.2022 - Maria';
    assert.deepEqual(await values(['caixa-registros', 'livro-registros', 'livro-titulo']), [xml, xml, 'CAIXA DOCUMENTOS'],
        'Word XML imports year-prefixed text, including valid alternate namespace prefixes');
    await page.evaluate(`(() => {
        const livro = document.getElementById('livro-registros');
        livro.value = 'Livro independente';
        livro.dispatchEvent(new Event('input', {bubbles: true}));
    })()`);
    assert.deepEqual(await values(['caixa-registros', 'livro-registros']), [xml, 'Livro independente'],
        'Manual edits can differ across Caixa and Livro');
    await printA4('etiquetas-passivo', 2);

    const dense = Array.from({length: 65}, (_, index) => `2026-${index + 1} - REGISTRO TESTE ${index + 1} FIM`);
    await upload('Caixa_Extensa.txt', dense.join('\n'));
    await page.until("document.getElementById('caixa-titulo').value === 'CAIXA EXTENSA'");
    await printA4('etiquetas-passivo-extenso', 2);
    await page.command('Emulation.setEmulatedMedia', {media: 'print'});
    const contents = await page.evaluate(`[...document.querySelectorAll('.etiquetas-passivo-conteudo')].map(conteudo => {
        const registros = conteudo.querySelector('.etiquetas-passivo-registros');
        return {records: registros.childElementCount, fits: conteudo.scrollHeight <= conteudo.clientHeight};
    })`);
    assert.deepEqual(contents, [{records: 65, fits: true}, {records: 65, fits: true}],
        'An extensive import fits on one page per model with every record present');
    await page.command('Emulation.setEmulatedMedia', {media: ''});

    await viewport(390, 844);
    assert.ok(await page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Passive editor fits the mobile viewport');
    await page.evaluate("window.confirm = () => true; document.querySelector('[data-etiquetas-reset]').click()");
    assert.deepEqual(await values(['caixa-registros', 'livro-registros', 'caixa-titulo', 'livro-titulo']),
        ['', '', 'CAIXA __', 'CAIXA __'], 'Reset removes both imported lists and restores the default titles');
    assert.deepEqual(fixture('inspect'), initial, 'Editing and importing do not modify the school records');
    assert.deepEqual(browser.events
        .filter(({method, params}) => method === 'Network.requestWillBeSent' && params.request.method === 'POST')
        .map(({params}) => new URL(params.request.url).pathname), ['/login/entrar'], 'Only login submits a POST');
    assert.deepEqual(browser.errors, [], 'No uncaught browser errors');
    assert.doesNotMatch(serverErrors, /PHP (?:Warning|Fatal error|Parse error)/, 'No PHP runtime warnings');
    console.log('Browser etiquetas: editors, local imports, reset, mobile and A4 printing passed.');
} finally {
    await browser?.cleanup();
    if (server?.exitCode === null) {
        server.kill();
        await new Promise(done => { server.once('exit', done); setTimeout(done, 1000); });
    }
    await rm(temporary, {recursive: true, force: true});
}
