const certWorkspace = document.getElementById('cert-workspace');
document.querySelectorAll('[data-cert-auto-submit]').forEach((select) => {
    select.addEventListener('change', () => select.form.requestSubmit());
});

const certIssueDate = document.getElementById('data_emissao');
const certPdfInput = document.querySelector('[data-pdf-input]');
if (certPdfInput) {
    const preview = document.querySelector('[data-pdf-preview]');
    const name = preview.querySelector('[data-pdf-name]');
    const meta = preview.querySelector('[data-pdf-meta]');
    const frame = preview.querySelector('[data-pdf-frame]');
    const open = preview.querySelector('[data-pdf-open]');
    let previewUrl = null;
    const clearPreview = () => {
        frame.removeAttribute('src');
        frame.hidden = true;
        open.removeAttribute('href');
        open.hidden = true;
        preview.hidden = true;
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
    };
    certPdfInput.addEventListener('change', () => {
        clearPreview();
        const file = certPdfInput.files?.[0];
        if (!file) return;
        name.textContent = file.name;
        meta.textContent = `${(file.size / 1048576).toFixed(2)} MiB`;
        preview.hidden = false;
        if (file.type === 'application/pdf' && /\.pdf$/i.test(file.name)) {
            previewUrl = URL.createObjectURL(file);
            frame.src = previewUrl;
            frame.hidden = false;
            open.href = previewUrl;
            open.hidden = false;
        } else {
            meta.textContent += ' · Não é possível pré-visualizar este arquivo como PDF.';
        }
    });
    preview.querySelector('[data-pdf-clear]').addEventListener('click', () => {
        certPdfInput.value = '';
        clearPreview();
        certPdfInput.focus();
    });
    window.addEventListener('pagehide', clearPreview);
}
const certExpiryDate = document.getElementById('data_vencimento');
const certValidityDays = document.getElementById('cert-validity-days');
if (certIssueDate && certExpiryDate && certValidityDays) {
    const refreshValidity = () => {
        const issue = Date.parse(`${certIssueDate.value}T00:00:00Z`);
        const expiry = Date.parse(`${certExpiryDate.value}T00:00:00Z`);
        const days = Math.round((expiry - issue) / 86400000);
        certValidityDays.value = Number.isFinite(days) ? (days < 0 ? 'Inválido' : `${days} dias`) : '...';
        certValidityDays.classList.toggle('is-invalid', Number.isFinite(days) && days < 0);
    };
    certIssueDate.addEventListener('change', refreshValidity);
    certExpiryDate.addEventListener('change', refreshValidity);
    refreshValidity();
}

const dashboardHome = document.querySelector('[data-dashboard-home]');
if (dashboardHome) {
    const search = dashboardHome.querySelector('[data-dashboard-search]');
    const feedback = dashboardHome.querySelector('[data-dashboard-search-feedback]');
    const sections = [...dashboardHome.querySelectorAll('[data-dashboard-section]')];
    const initialOpen = new Map(sections.filter((section) => section.tagName === 'DETAILS').map((section) => [section, section.open]));
    const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');

    search.addEventListener('input', () => {
        const query = normalize(search.value.trim());
        let matches = 0;

        sections.forEach((section) => {
            let sectionMatches = 0;
            section.querySelectorAll('[data-dashboard-item]').forEach((item) => {
                const name = item.querySelector('a, strong')?.textContent ?? item.textContent;
                const found = !query || normalize(name).includes(query);
                item.hidden = !found;
                if (found) sectionMatches += 1;
            });
            if (query && sectionMatches > 0) matches += sectionMatches;
            section.hidden = !!query && sectionMatches === 0;
            if (section.tagName === 'DETAILS') section.open = query ? sectionMatches > 0 : initialOpen.get(section);
        });

        feedback.hidden = !query;
        feedback.textContent = query ? (matches === 0 ? 'Nenhum aluno encontrado na tela.' : `${matches} ocorrência(s) encontrada(s) na tela.`) : '';
    });
}

const certFullscreen = document.getElementById('cert-fullscreen');
if (certWorkspace && certFullscreen) {
    const status = document.getElementById('cert-fullscreen-status');
    const fullscreenIcon = certFullscreen.querySelector('.cert-icon');
    const fullscreenLabel = document.createTextNode(' Tela cheia');
    certFullscreen.replaceChildren(...(fullscreenIcon ? [fullscreenIcon] : []), fullscreenLabel);
    const fullscreenBar = certWorkspace.querySelector('.cert-fullscreen-status-bar');
    const fullscreenFilters = [...fullscreenBar.querySelectorAll('[data-cert-fullscreen-filter]')];
    const cards = [...certWorkspace.querySelectorAll('.cert-card')];
    fullscreenFilters.forEach((button) => button.addEventListener('click', () => {
        const selected = button.dataset.certFullscreenFilter;
        fullscreenFilters.forEach((filter) => {
            const active = filter === button;
            filter.classList.toggle('is-active', active);
            filter.setAttribute('aria-pressed', String(active));
        });
        cards.forEach((card) => {
            const validity = [...card.classList].find((value) => value.startsWith('cert-') && value !== 'cert-card');
            card.hidden = selected !== 'all' && validity !== `cert-${selected}` && !(selected === 'a_vencer' && validity === 'cert-vence_hoje');
        });
        certWorkspace.querySelectorAll('.cert-matrix tbody tr').forEach((row) => {
            row.hidden = !row.querySelector('.cert-card:not([hidden])');
        });
    }));
    fullscreenBar.querySelector('[data-cert-fullscreen-exit]').addEventListener('click', () => document.exitFullscreen());
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
        fullscreenBar.hidden = !active;
        if (!active && fullscreenFilters[0]) fullscreenFilters[0].click();
        certFullscreen.setAttribute('aria-pressed', String(active));
        fullscreenLabel.data = active ? ' Sair da tela cheia' : ' Tela cheia';
        status.textContent = active ? 'Pressione Esc para sair.' : '';
        if (active) (fullscreenFilters[0] || fullscreenBar.querySelector('[data-cert-fullscreen-exit]')).focus();
        else certFullscreen.focus();
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
document.querySelectorAll('[data-contract-item-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const row = document.getElementById(button.dataset.contractItemToggle);
        if (!row) return;
        row.hidden = !row.hidden;
        button.setAttribute('aria-expanded', String(!row.hidden));
    });
});
// IDs are looked up directly: a malformed URL fragment must never become a CSS selector.
const fragmentTarget = (fragment) => {
    if (!fragment || !fragment.startsWith('#')) return null;
    try { return document.getElementById(decodeURIComponent(fragment.slice(1))); }
    catch { return null; }
};
const revealFragmentTarget = (target, focus = false) => {
    if (!target) return;
    for (let ancestor = target; ancestor; ancestor = ancestor.parentElement) {
        if (ancestor.tagName === 'DETAILS') ancestor.open = true;
        if (ancestor.matches('.contract-item-expanded')) {
            ancestor.hidden = false;
            document.querySelectorAll('[data-contract-item-toggle]').forEach((button) => {
                if (button.dataset.contractItemToggle === ancestor.id) button.setAttribute('aria-expanded', 'true');
            });
        }
    }
    if (focus) (target.querySelector('input:not([type="hidden"]), select, textarea') || target.querySelector('button, summary'))?.focus({preventScroll: true});
};
document.querySelectorAll('[data-open-details]').forEach((link) => {
    link.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        const target = fragmentTarget(link.getAttribute('href'));
        revealFragmentTarget(target);
        // The default anchor action moves focus to the fragment. Focus the input afterwards.
        requestAnimationFrame(() => revealFragmentTarget(target, true));
    });
});
document.querySelectorAll('[data-close-details]').forEach((button) => {
    button.addEventListener('click', () => {
        const details = document.getElementById(button.dataset.closeDetails);
        if (!details || details.tagName !== 'DETAILS') return;
        details.open = false;
        const panel = details.closest('[role="tabpanel"]');
        if (fragmentTarget(location.hash) && details.contains(fragmentTarget(location.hash))) {
            history.replaceState(history.state, '', panel ? `#${panel.id}` : location.pathname + location.search);
        }
        const opener = [...document.querySelectorAll('[data-open-details]')]
            .find((link) => fragmentTarget(link.getAttribute('href')) === details);
        opener?.focus({preventScroll: true});
    });
});
if (document.body.hasAttribute('data-auto-print')) {
    window.addEventListener('load', () => window.print());
}

document.addEventListener('change', (event) => {
    const select = event.target;
    if (!(select instanceof HTMLSelectElement) || !select.matches('[data-contract-unit]') || select.value === '') return;
    select.removeAttribute('aria-invalid');
    select.querySelectorAll('[data-unit-invalid]').forEach((option) => option.remove());
    select.closest('label')?.querySelectorAll('[data-unit-warning]').forEach((warning) => warning.remove());
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
                product.querySelectorAll('input, select').forEach((input) => {
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
    const resetProductFields = (element) => {
        element.querySelectorAll('input, textarea').forEach((input) => { input.value = ''; });
        element.querySelectorAll('[data-contract-unit]').forEach((select) => {
            select.querySelectorAll('[data-unit-invalid]').forEach((option) => option.remove());
            select.removeAttribute('aria-invalid');
            select.value = 'UN';
        });
        element.querySelectorAll('[data-unit-warning]').forEach((warning) => warning.remove());
    };
    contractBuilder.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button || !contractBuilder.contains(button)) return;
        const sheet = button.closest('[data-contract-sheet]');
        if (button.matches('[data-add-sheet]')) {
            if (sheetContainer.querySelectorAll('[data-contract-sheet]').length >= 20) return;
            const copy = sheetContainer.querySelector('[data-contract-sheet]').cloneNode(true);
            copy.querySelectorAll('[data-contract-product]').forEach((product, index) => { if (index > 0) product.remove(); });
            resetProductFields(copy);
            sheetContainer.append(copy);
            copy.querySelector('textarea').focus();
        } else if (button.matches('[data-remove-sheet]') && sheetContainer.querySelectorAll('[data-contract-sheet]').length > 1) {
            sheet.remove();
        } else if (button.matches('[data-add-product]')) {
            if (contractBuilder.querySelectorAll('[data-contract-product]').length >= 100) return;
            const copy = sheet.querySelector('[data-contract-product]').cloneNode(true);
            resetProductFields(copy);
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
    const tabs = [...contractTabs.querySelectorAll('a')].filter((tab) => fragmentTarget(tab.getAttribute('href')));
    const panels = tabs.map((tab) => fragmentTarget(tab.getAttribute('href')));
    let selectedIndex = 0;
    contractTabs.setAttribute('role', 'tablist');
    tabs.forEach((tab, index) => {
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panels[index].id);
        panels[index].setAttribute('role', 'tabpanel');
        panels[index].setAttribute('aria-labelledby', `contract-tab-${index}`);
        tab.id = `contract-tab-${index}`;
    });
    const select = (index, focus = false) => {
        if (index < 0 || index >= tabs.length) return;
        selectedIndex = index;
        tabs.forEach((tab, current) => {
            tab.setAttribute('aria-selected', String(current === index));
            tab.tabIndex = current === index ? 0 : -1;
            panels[current].hidden = current !== index;
        });
        if (focus) tabs[index].focus();
    };
    const fromHash = () => {
        if (!location.hash) return 0;
        const target = fragmentTarget(location.hash);
        return target ? panels.findIndex((panel) => panel === target || panel.contains(target)) : -1;
    };
    const followHash = (scroll = true) => {
        const index = fromHash();
        if (index >= 0) {
            const target = fragmentTarget(location.hash);
            select(index);
            revealFragmentTarget(target);
            if (scroll) target?.scrollIntoView({block: 'start'});
        }
    };
    select(0);
    followHash();
    // Select the ancestor before the browser scrolls to a form in a previously hidden note.
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        const target = fragmentTarget(link.getAttribute('href'));
        const index = target ? panels.findIndex((panel) => panel === target || panel.contains(target)) : -1;
        if (index >= 0) { select(index); revealFragmentTarget(target); }
    });
    contractTabs.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
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
    window.addEventListener('hashchange', () => followHash());
    window.addEventListener('pageshow', () => followHash());
    let printIndex = 0;
    let printScroll = 0;
    window.addEventListener('beforeprint', () => { printIndex = selectedIndex; printScroll = window.scrollY; panels.forEach((panel) => { panel.hidden = false; }); });
    window.addEventListener('afterprint', () => { select(printIndex); followHash(false); window.scrollTo(0, printScroll); });
}

const reportGenerator = document.querySelector('[data-report-generator]');
if (reportGenerator) {
    reportGenerator.addEventListener('submit', (event) => {
        if (event.submitter?.hasAttribute('data-report-csv')) return;
        const format = reportGenerator.querySelector('[name="formato"]:checked')?.value;
        reportGenerator.action = format === 'csv' ? reportGenerator.dataset.csvUrl : reportGenerator.dataset.pdfUrl;
    });
}
