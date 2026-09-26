const certWorkspace = document.getElementById('cert-workspace');
const certFullscreen = document.getElementById('cert-fullscreen');
if (certWorkspace && certFullscreen) {
    const status = document.getElementById('cert-fullscreen-status');
    certFullscreen.hidden = false;
    certFullscreen.addEventListener('click', async () => {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else if (certWorkspace.requestFullscreen) {
                await certWorkspace.requestFullscreen();
            } else {
                status.textContent = 'Tela cheia indisponível neste navegador.';
            }
        } catch (error) {
            status.textContent = 'O navegador não permitiu a tela cheia. A consulta continua disponível.';
        }
    });
    document.addEventListener('fullscreenchange', () => {
        const active = document.fullscreenElement === certWorkspace;
        certFullscreen.setAttribute('aria-pressed', String(active));
        certFullscreen.textContent = active ? 'Sair da tela cheia' : 'Tela cheia';
        status.textContent = active ? 'Pressione Esc para sair.' : '';
        certFullscreen.focus();
    });
}

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);

        if (!input) {
            return;
        }

        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        const svg = showing
            ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4"/></svg>'
            : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.6 21.6 0 0 1 5.76-5.94" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M1 1l22 22" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';

        button.innerHTML = svg;
        button.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
    });
});

document.querySelectorAll('[data-confirm-status]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirmStatus)) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-print-page]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});

const contractBuilder = document.querySelector('[data-contract-builder]');
if (contractBuilder) {
    const sheetContainer = contractBuilder.querySelector('[data-contract-sheets]');
    const money = (cents) => new Intl.NumberFormat('pt-BR', {style: 'currency', currency: 'BRL'}).format(cents / 100);
    const cents = (text) => /^(?:0|[1-9][0-9]{0,8})(?:,[0-9]{1,2})?$/.test(text)
        ? Math.round(Number(text.replace(',', '.')) * 100) : 0;
    const refresh = () => {
        let total = 0;
        sheetContainer.querySelectorAll('[data-contract-sheet]').forEach((sheet, sheetIndex) => {
            sheet.querySelector('[data-sheet-number]').textContent = String(sheetIndex + 1);
            sheet.querySelector('textarea').name = `folhas[${sheetIndex}][observacao]`;
            sheet.querySelectorAll('[data-contract-product]').forEach((product, productIndex) => {
                product.querySelectorAll('input').forEach((input) => {
                    const field = input.name.match(/\[(nome|marca|unidade|quantidade|preco)\]$/)?.[1];
                    if (field) input.name = `folhas[${sheetIndex}][produtos][${productIndex}][${field}]`;
                });
                const quantity = Number(product.querySelector('[name$="[quantidade]"]').value) || 0;
                total += quantity * cents(product.querySelector('[name$="[preco]"]').value);
            });
            sheet.querySelectorAll('[data-remove-product]').forEach((button) => {
                button.disabled = sheet.querySelectorAll('[data-contract-product]').length === 1;
            });
        });
        contractBuilder.querySelectorAll('[data-remove-sheet]').forEach((button) => {
            button.disabled = sheetContainer.querySelectorAll('[data-contract-sheet]').length === 1;
        });
        contractBuilder.querySelector('[data-items-total]').textContent = money(total);
        contractBuilder.querySelector('[data-budget-left]').textContent = money(cents(document.querySelector('#valor').value) - total);
    };
    contractBuilder.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button || !contractBuilder.contains(button)) return;
        const sheet = button.closest('[data-contract-sheet]');
        if (button.matches('[data-add-sheet]')) {
            if (sheetContainer.querySelectorAll('[data-contract-sheet]').length >= 20) return;
            const copy = sheetContainer.querySelector('[data-contract-sheet]').cloneNode(true);
            copy.querySelectorAll('[data-contract-product]').forEach((product, index) => { if (index > 0) product.remove(); });
            copy.querySelectorAll('input, textarea').forEach((input) => { input.value = input.name.endsWith('[unidade]') ? 'un' : ''; });
            sheetContainer.append(copy);
            copy.querySelector('textarea').focus();
        } else if (button.matches('[data-remove-sheet]') && sheetContainer.querySelectorAll('[data-contract-sheet]').length > 1) {
            sheet.remove();
        } else if (button.matches('[data-add-product]')) {
            if (contractBuilder.querySelectorAll('[data-contract-product]').length >= 100) return;
            const copy = sheet.querySelector('[data-contract-product]').cloneNode(true);
            copy.querySelectorAll('input').forEach((input) => { input.value = input.name.endsWith('[unidade]') ? 'un' : ''; });
            sheet.querySelector('[data-contract-products]').append(copy);
            copy.querySelector('input').focus();
        } else if (button.matches('[data-remove-product]') && sheet.querySelectorAll('[data-contract-product]').length > 1) {
            button.closest('[data-contract-product]').remove();
        }
        refresh();
    });
    contractBuilder.addEventListener('input', refresh);
    document.querySelector('#valor').addEventListener('input', refresh);
    refresh();
}

const contractTabs = document.querySelector('[data-contract-tabs]');
if (contractTabs) {
    const tabs = [...contractTabs.querySelectorAll('a')];
    const panels = tabs.map((tab) => document.querySelector(tab.getAttribute('href')));
    contractTabs.setAttribute('role', 'tablist');
    tabs.forEach((tab, index) => {
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panels[index].id);
        panels[index].setAttribute('role', 'tabpanel');
        panels[index].setAttribute('aria-labelledby', `contract-tab-${index}`);
        tab.id = `contract-tab-${index}`;
    });
    const select = (index, focus = false) => {
        tabs.forEach((tab, current) => {
            tab.setAttribute('aria-selected', String(current === index));
            tab.tabIndex = current === index ? 0 : -1;
            panels[current].hidden = current !== index;
        });
        if (focus) tabs[index].focus();
    };
    const fromHash = () => Math.max(0, tabs.findIndex((tab) => tab.getAttribute('href') === location.hash));
    select(fromHash());
    contractTabs.addEventListener('click', (event) => {
        const index = tabs.indexOf(event.target.closest('a'));
        if (index >= 0) select(index);
    });
    contractTabs.addEventListener('keydown', (event) => {
        const current = tabs.indexOf(document.activeElement);
        if (current < 0) return;
        const next = event.key === 'ArrowRight' ? (current + 1) % tabs.length
            : event.key === 'ArrowLeft' ? (current - 1 + tabs.length) % tabs.length
            : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : -1;
        if (next >= 0) { event.preventDefault(); location.hash = tabs[next].getAttribute('href'); select(next, true); }
    });
    window.addEventListener('hashchange', () => select(fromHash()));
    window.addEventListener('beforeprint', () => panels.forEach((panel) => { panel.hidden = false; }));
    window.addEventListener('afterprint', () => select(fromHash()));
}
