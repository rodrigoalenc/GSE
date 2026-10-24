(() => {
    'use strict';

    // Mesma gramática e mesmo limite de Contrato::cents; a conta usa inteiros.
    const amountPattern = /^(?:0|[1-9][0-9]{0,8}|[1-9][0-9]{0,2}(?:\.[0-9]{3}){1,2})(?:,[0-9]{1,2})?$/;
    const cents = (value) => {
        if (typeof value !== 'string' || !amountPattern.test(value) || value.endsWith('\n')) return null;
        const [whole, fraction = ''] = value.replaceAll('.', '').split(',');
        return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    };
    window.GseMoney = Object.freeze({cents});

    document.querySelectorAll('[data-pedido-wizard]').forEach((form) => {
        const steps = [...form.querySelectorAll('[data-pedido-step]')];
        const progress = form.querySelector('[data-pedido-progress]');
        const status = form.querySelector('[data-pedido-step-status]');
        const controls = form.querySelector('[data-pedido-controls]');
        const next = form.querySelector('[data-pedido-next]');
        const back = form.querySelector('[data-pedido-back]');
        const review = form.querySelector('[data-pedido-review]');
        if (steps.length !== 3 || !progress || !status || !controls || !next || !back || !review) return;
        const labels = ['Dados do pedido', 'Notas e produtos', 'Conferir e salvar'];
        const money = (amount) => new Intl.NumberFormat('pt-BR', {style:'currency',currency:'BRL'}).format(amount / 100);
        let active = 0;
        form.noValidate = true;
        progress.hidden = false;
        status.hidden = false;
        controls.hidden = false;

        const show = (index, focus = true) => {
            active = index;
            steps.forEach((step, current) => { step.hidden = current !== index; });
            progress.querySelectorAll('[data-pedido-progress-item]').forEach((item, current) => {
                if (current === index) item.setAttribute('aria-current', 'step');
                else item.removeAttribute('aria-current');
                item.classList.toggle('is-complete', current < index);
            });
            back.hidden = index === 0;
            next.hidden = index === steps.length - 1;
            status.textContent = `Etapa ${index + 1} de 3: ${labels[index]}`;
            if (focus) steps[index].querySelector('legend')?.focus();
            // Um erro corrigido em outra etapa não deve deixar links para campos ocultos.
            form.querySelectorAll('[data-client-errors] li').forEach((item) => {
                const href = item.querySelector('a')?.getAttribute('href');
                const field = href?.startsWith('#') ? document.getElementById(href.slice(1)) : null;
                item.hidden = !!field && !steps[index].contains(field);
            });
        };
        const validateMoney = () => {
            form.querySelectorAll('[name="valor"], [name$="[preco]"]').forEach((field) => {
                field.setCustomValidity(cents(field.value) === null ? 'Use 1250,00 ou 1.250,00, com até duas casas decimais.' : '');
            });
        };
        const validateStep = (index) => {
            validateMoney();
            const fields = [...steps[index].querySelectorAll('input, select, textarea')].filter((field) => field.willValidate);
            const invalid = fields.find((field) => !field.validity.valid);
            if (invalid) {
                show(index, false); invalid.reportValidity(); invalid.focus(); return false;
            }
            if (index === 1) {
                let total = 0;
                let badPrice = null;
                form.querySelectorAll('[data-contract-product]').forEach((product) => {
                    const price = product.querySelector('[name$="[preco]"]');
                    const quantity = Number(product.querySelector('[name$="[quantidade]"]').value);
                    const amount = cents(price.value);
                    const line = quantity * amount;
                    if (amount === null || !Number.isSafeInteger(line) || !Number.isSafeInteger(total + line)) badPrice ||= price;
                    else total += line;
                });
                if (!badPrice && total > cents(form.querySelector('[name="valor"]').value)) {
                    badPrice = form.querySelector('[name$="[preco]"]');
                }
                if (badPrice) {
                    badPrice.setCustomValidity('A soma dos produtos deve caber no valor total do pedido. Confira preços e quantidades.');
                    show(index, false); badPrice.reportValidity(); badPrice.focus(); return false;
                }
            }
            return true;
        };
        const addText = (parent, tag, text, className = '') => {
            const node = document.createElement(tag); node.textContent = text;
            if (className) node.className = className;
            parent.append(node); return node;
        };
        const renderReview = () => {
            review.replaceChildren();
            const overview = document.createElement('dl'); review.append(overview);
            const supplier = form.querySelector('[name="fornecedor"]');
            const pairs = [
                ['Título', form.querySelector('[name="titulo"]').value],
                ['Fornecedor', supplier.selectedOptions[0]?.textContent || 'Não informado'],
                ['Valor total do pedido', money(cents(form.querySelector('[name="valor"]').value))],
            ];
            pairs.forEach(([label, value]) => { addText(overview, 'dt', label); addText(overview, 'dd', value); });
            let total = 0;
            form.querySelectorAll('[data-contract-sheet]').forEach((sheet, index) => {
                const group = document.createElement('section'); group.className = 'pedido-review-note'; review.append(group);
                addText(group, 'h3', `Nota ${index + 1}`);
                const note = sheet.querySelector('textarea').value;
                if (note) addText(group, 'p', `Observações: ${note}`);
                const list = document.createElement('ul'); group.append(list);
                sheet.querySelectorAll('[data-contract-product]').forEach((product) => {
                    const field = (name) => product.querySelector(`[name$="[${name}]"]`).value;
                    const quantity = Number(field('quantidade'));
                    const price = cents(field('preco'));
                    const subtotal = quantity * price; total += subtotal;
                    const brand = field('marca');
                    addText(list, 'li', `${field('nome')}${brand ? ` · ${brand}` : ''}: ${quantity} ${field('unidade')} × ${money(price)} = ${money(subtotal)}`);
                });
            });
            addText(review, 'p', `Soma dos produtos: ${money(total)}`, 'pedido-review-total');
            addText(review, 'p', `Saldo do pedido: ${money(cents(form.querySelector('[name="valor"]').value) - total)}`);
            review.hidden = false;
        };
        next.addEventListener('click', () => {
            if (!validateStep(active)) return;
            if (active === 1) renderReview();
            show(Math.min(active + 1, 2));
        });
        back.addEventListener('click', () => show(Math.max(active - 1, 0)));
        form.addEventListener('input', validateMoney);
        form.addEventListener('change', validateMoney);
        form.addEventListener('submit', (event) => {
            if (active !== 2) {
                event.preventDefault(); next.click(); return;
            }
            for (let index = 0; index < 2; index++) {
                if (!validateStep(index)) { event.preventDefault(); return; }
            }
            renderReview();
        });
        // Erros de validação externa também revelam seu campo antes do foco.
        form.addEventListener('invalid', (event) => {
            const index = steps.findIndex((step) => step.contains(event.target));
            if (index >= 0 && index !== active) show(index, false);
        }, true);
        form.addEventListener('gse:restore-products', (event) => {
            const fields = event.detail?.fields || event.detail;
            if (!fields || typeof fields !== 'object' || Array.isArray(fields)) return;
            const desired = new Map();
            for (const name of Object.keys(fields)) {
                const note = name.match(/^folhas\[(0|[1-9][0-9]*)\]\[observacao\]$/);
                const product = name.match(/^folhas\[(0|[1-9][0-9]*)\]\[produtos\]\[(0|[1-9][0-9]*)\]\[(?:nome|marca|unidade|quantidade|preco)\]$/);
                if (!note && !product) continue;
                const sheetIndex = Number((product || note)[1]);
                const productCount = product ? Number(product[2]) + 1 : 1;
                if (sheetIndex >= 20 || productCount > 100) return;
                desired.set(sheetIndex, Math.max(desired.get(sheetIndex) || 1, productCount));
            }
            if (!desired.size) return;
            const sheetCount = Math.max(...desired.keys()) + 1;
            const existing = [...form.querySelectorAll('[data-contract-sheet]')];
            let productCount = 0;
            for (let index = 0; index < Math.max(sheetCount, existing.length); index++) {
                productCount += Math.max(desired.get(index) || 1, existing[index]?.querySelectorAll('[data-contract-product]').length || 1);
            }
            if (productCount > 100) return;
            while (form.querySelectorAll('[data-contract-sheet]').length < sheetCount) {
                const previous = form.querySelectorAll('[data-contract-sheet]').length;
                form.querySelector('[data-add-sheet]')?.click();
                if (form.querySelectorAll('[data-contract-sheet]').length === previous) return;
            }
            const sheets = [...form.querySelectorAll('[data-contract-sheet]')];
            desired.forEach((count, index) => {
                const sheet = sheets[index];
                while (sheet.querySelectorAll('[data-contract-product]').length < count) {
                    const previous = sheet.querySelectorAll('[data-contract-product]').length;
                    sheet.querySelector('[data-add-product]')?.click();
                    if (sheet.querySelectorAll('[data-contract-product]').length === previous) return;
                }
            });
        });
        show(0, false);
    });
})();
