(() => {
    'use strict';

    const editor = document.querySelector('[data-etiquetas-editor]');
    if (!editor) return;

    const campos = editor.querySelectorAll('[data-etiquetas-campo]');
    const valoresImpressos = new Map(Array.from(
        editor.querySelectorAll('[data-etiquetas-valor]:not([data-etiquetas-valor=""])'),
        valor => [valor.dataset.etiquetasValor, valor]
    ));
    const arquivo = editor.querySelector('#etiquetas-arquivo');
    const status = editor.querySelector('[data-etiquetas-status]');
    let revisaoImportacao = 0;

    function formatarTelefone(digitos) {
        const telefone = digitos.slice(0, 11);
        if (telefone.length <= 2) return telefone;

        const ddd = telefone.slice(0, 2);
        const numero = telefone.slice(2);
        const grupo = numero.length === 9 ? 5 : 4;
        const sufixo = numero.slice(grupo);
        return `(${ddd}) ${numero.slice(0, grupo)}${sufixo ? '-' + sufixo : ''}`;
    }

    const mascaras = {
        tel: formatarTelefone,
        cpf: digitos => digitos.slice(0, 11)
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\.\d{3})(\d)/, '$1.$2')
            .replace(/(\.\d{3})(\d)/, '$1-$2'),
        sus: digitos => digitos.slice(0, 15)
            .replace(/(\d{3})(\d)/, '$1 $2')
            .replace(/( \d{4})(\d)/, '$1 $2')
            .replace(/( \d{4} \d{4})(\d)/, '$1 $2'),
        data: digitos => digitos.slice(0, 8)
            .replace(/(\d{2})(\d)/, '$1/$2')
            .replace(/(\/\d{2})(\d)/, '$1/$2')
    };

    function aplicarMascara(campo) {
        const formatar = mascaras[campo.dataset.etiquetasMascara];
        if (!formatar) return;

        const valor = campo.value;
        const formatado = formatar(valor.replace(/\D/g, ''));
        if (valor === formatado) return;

        let digitosAntesDoCursor = valor.slice(0, campo.selectionStart).replace(/\D/g, '').length;
        let posicao = 0;
        while (posicao < formatado.length && digitosAntesDoCursor > 0) {
            if (/\d/.test(formatado[posicao])) digitosAntesDoCursor--;
            posicao++;
        }
        campo.value = formatado;
        campo.setSelectionRange(posicao, posicao);
    }

    function atualizarImpressao(campo) {
        const valor = valoresImpressos.get(campo.id) ?? campo.nextElementSibling;
        if (!campo.hasAttribute('data-etiquetas-registros')) {
            valor.textContent = campo.value;
            return;
        }

        const registros = document.createDocumentFragment();
        if (campo.value) {
            for (const linha of campo.value.split('\n')) {
                const registro = document.createElement('div');
                registro.textContent = linha;
                registros.append(registro);
            }
        }
        valor.replaceChildren(registros);
    }

    function anunciar(mensagem, erro = false) {
        status.classList.toggle('erro', erro);
        status.textContent = mensagem;
    }

    function lerComoTexto(selecionado) {
        return new Promise((resolve, reject) => {
            const leitor = new FileReader();
            leitor.addEventListener('load', () => resolve(String(leitor.result)));
            leitor.addEventListener('error', reject);
            leitor.readAsText(selecionado);
        });
    }

    function extrairRegistros(texto, formato) {
        if (formato === 'txt') return texto.split(/\r\n?|\n/).filter(linha => linha.trim());

        // Os documentos Word XML usam elementos w:t para armazenar texto.
        if (/<!DOCTYPE\b/i.test(texto)) throw new Error('O arquivo XML é inválido.');
        const documento = new DOMParser().parseFromString(texto, 'application/xml');
        if (documento.querySelector('parsererror')) throw new Error('O arquivo XML é inválido.');

        const namespacesWord = [
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            'http://purl.oclc.org/ooxml/wordprocessingml/main',
            'http://schemas.microsoft.com/office/word/2003/wordml'
        ];
        return Array.from(documento.getElementsByTagNameNS('*', 't'))
            .filter(no => no.prefix === 'w' || namespacesWord.includes(no.namespaceURI))
            .map(no => no.textContent.trim())
            .filter(linha => /^\d{4}/.test(linha));
    }

    async function importar(selecionado) {
        const revisao = ++revisaoImportacao;
        const extensao = selecionado.name.match(/\.(xml|txt)$/i);
        if (!extensao) {
            anunciar('Selecione um arquivo XML ou TXT.', true);
            return;
        }

        anunciar('Lendo arquivo…');
        let texto;
        try {
            texto = await lerComoTexto(selecionado);
        } catch {
            if (revisao === revisaoImportacao) anunciar('Não foi possível ler o arquivo.', true);
            return;
        }
        if (revisao !== revisaoImportacao) return;

        let registros;
        try {
            registros = extrairRegistros(texto, extensao[1].toLowerCase());
        } catch (erro) {
            anunciar(erro.message, true);
            return;
        }
        if (!registros.length) {
            anunciar('Nenhum registro encontrado no arquivo.', true);
            return;
        }

        const titulo = selecionado.name.slice(0, -extensao[0].length).replace(/_/g, ' ').toLocaleUpperCase('pt-BR');
        const lista = registros.map(linha => linha.replace('-', '.')).join('\n');
        for (const campo of editor.querySelectorAll('[data-etiquetas-titulo], [data-etiquetas-registros]')) {
            campo.value = campo.hasAttribute('data-etiquetas-titulo') ? titulo : lista;
            atualizarImpressao(campo);
        }
        anunciar(registros.length === 1
            ? '1 registro importado para Caixa e Livro.'
            : `${registros.length} registros importados para Caixa e Livro.`);
    }

    function ajustarRegistrosImpressos() {
        // Também mede as folhas quando o navegador dispara beforeprint ainda em modo de tela.
        editor.classList.add('etiquetas-preparando-impressao');
        for (const conteudo of editor.querySelectorAll('.etiquetas-passivo-conteudo')) {
            const registros = conteudo.querySelector('.etiquetas-passivo-registros');
            registros.classList.remove('etiquetas-registros-colunas');
            conteudo.style.removeProperty('--etiquetas-escala');

            const cabe = () => conteudo.scrollHeight <= conteudo.clientHeight;
            if (cabe()) continue;

            registros.classList.toggle('etiquetas-registros-colunas', registros.childElementCount > 1);
            if (cabe()) continue;

            let minimo = 0;
            let maximo = 1;
            for (let tentativa = 0; tentativa < 10; tentativa++) {
                const escala = (minimo + maximo) / 2;
                conteudo.style.setProperty('--etiquetas-escala', String(escala));
                if (cabe()) minimo = escala;
                else maximo = escala;
            }
            conteudo.style.setProperty('--etiquetas-escala', String(minimo));
        }
        editor.classList.remove('etiquetas-preparando-impressao');
    }

    editor.addEventListener('input', evento => {
        const campo = evento.target.closest('[data-etiquetas-campo]');
        if (!campo || evento.isComposing) return;
        aplicarMascara(campo);
        atualizarImpressao(campo);
    });

    editor.querySelector('[data-etiquetas-imprimir]').addEventListener('click', () => window.print());

    editor.querySelector('[data-etiquetas-reset]').addEventListener('click', () => {
        if (!window.confirm('Deseja apagar os dados preenchidos?')) return;
        revisaoImportacao++;
        for (const campo of campos) {
            campo.value = campo.defaultValue;
            atualizarImpressao(campo);
        }
        anunciar(arquivo ? 'Modelos limpos.' : 'Fichas limpas.');
    });

    if (arquivo) {
        editor.querySelector('[data-etiquetas-selecionar]').addEventListener('click', () => arquivo.click());
        arquivo.addEventListener('change', () => {
            const selecionado = arquivo.files[0];
            arquivo.value = '';
            if (selecionado) void importar(selecionado);
        });
    }

    window.addEventListener('beforeprint', () => {
        for (const campo of campos) atualizarImpressao(campo);
        if (arquivo) ajustarRegistrosImpressos();
    });
})();
