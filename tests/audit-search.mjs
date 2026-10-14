import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';

const listeners = new Map();
const search = {value: '', addEventListener: (event, handler) => listeners.set(event, handler)};
const empty = {hidden: true};
const rows = [
    ['Ana Álvaro', 'Cadastrar Aluno', 'Novo aluno: João (ID: 10)'],
    ['Bruno', 'Editar Aluno', 'Editou: Érica (ID: 20)'],
].map((values) => ({hidden: false, querySelectorAll: () => values.map((textContent) => ({textContent}))}));
const source = await readFile(new URL('../public/assets/js/auditoria.js', import.meta.url), 'utf8');
runInNewContext(source, {document: {
    getElementById: (id) => ({filtroPainel: search, corpoLogs: {}, 'logs-sem-resultados': empty})[id],
    querySelectorAll: () => rows,
}});

const filter = (value, event = 'input') => { search.value = value; listeners.get(event)(); };
const visible = () => rows.map((row) => !row.hidden);
assert.deepEqual(visible(), [true, true]);
filter('JOAO');
assert.deepEqual(visible(), [true, false], 'Pesquisa por detalhe ignora acentos e maiúsculas');
filter('álVARO', 'keyup');
assert.deepEqual(visible(), [true, false], 'Pesquisa por usuário ignora acentos e maiúsculas');
filter('editar');
assert.deepEqual(visible(), [false, true], 'Pesquisa por ação encontra o registro');
filter('nao encontrado');
assert.deepEqual(visible(), [false, false]);
assert.equal(empty.hidden, false, 'Pesquisa sem correspondência mostra estado vazio');
filter('Ana Álvaro Cadastrar Aluno');
assert.deepEqual(visible(), [false, false], 'Pesquisa compara os campos individualmente');
filter('');
assert.deepEqual(visible(), [true, true], 'Limpar a pesquisa restaura todas as linhas');
assert.equal(empty.hidden, true);
runInNewContext(source, {document: {
    getElementById: (id) => ({filtroPainel: search, corpoLogs: {}, 'logs-sem-resultados': empty})[id],
    querySelectorAll: () => [],
}});
filter('pesquisa sem registros');
assert.equal(empty.hidden, true, 'Tabela vazia mantém somente uma mensagem de ausência de registros');
console.log('Pesquisa de logs: 10 verificações passaram.');
