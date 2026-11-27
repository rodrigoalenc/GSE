document.querySelector('[data-form-errors]')?.focus();
document.querySelectorAll('[data-form-errors] a').forEach((link) => {
    link.addEventListener('click', () => document.getElementById(link.hash.slice(1))?.focus());
});
let fieldErrorSequence = 0;
const pendingErrorFocus = new WeakSet();
const validationText = (input) => {
    const state = input.validity;
    const date = (value) => value.split('-').reverse().join('/');
    if (state.customError) return input.validationMessage;
    if (state.badInput) return input.type === 'date' ? 'Informe uma data válida.' : 'Informe um número válido.';
    if (state.valueMissing) {
        if (input.type === 'checkbox') return 'Marque esta opção para continuar.';
        if (input.type === 'file') return 'Selecione um arquivo.';
        if (input.tagName === 'SELECT' || input.type === 'radio') return 'Selecione uma opção.';
        return 'Preencha este campo.';
    }
    if (state.typeMismatch && input.type === 'email') return 'Informe um e-mail válido. Inclua o @, como em nome@escola.com.br.';
    if (state.typeMismatch && input.type === 'url') return 'Informe um endereço válido, como https://exemplo.com.br.';
    if (state.rangeUnderflow) return input.type === 'date' ? `Informe uma data a partir de ${date(input.min)}.` : `Informe um número maior ou igual a ${input.min}.`;
    if (state.rangeOverflow) return input.type === 'date' ? `Informe uma data até ${date(input.max)}.` : `Informe um número menor ou igual a ${input.max}.`;
    if (state.stepMismatch) return input.type === 'number' && (!input.step || input.step === '1') ? 'Informe um número inteiro.' : 'Informe um valor que corresponda ao intervalo indicado no campo.';
    if (state.tooShort) return `Use pelo menos ${input.minLength} caracteres.`;
    if (state.tooLong) return `Use no máximo ${input.maxLength} caracteres.`;
    if (state.patternMismatch && input.dataset.formatError) return input.dataset.formatError;
    return 'Confira o formato indicado para este campo.';
};
const showFieldError = (input) => {
    if (!input.id || document.getElementById(input.id) !== input) input.id = `validation-field-${++fieldErrorSequence}`;
    const errorId = `${input.id}-client-error`;
    const text = validationText(input);
    if (document.getElementById(`${input.id}-error`)?.textContent.trim() === text) return;
    let message = document.getElementById(errorId);
    if (!message) {
        message = document.createElement('small');
        message.id = errorId;
        message.className = 'field-error';
        input.insertAdjacentElement('afterend', message);
    }
    message.textContent = text;
    input.setAttribute('aria-invalid', 'true');
    const descriptions = new Set((input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
    descriptions.add(errorId);
    input.setAttribute('aria-describedby', [...descriptions].join(' '));
    let summary = input.form.querySelector('[data-client-errors]');
    if (!summary) {
        summary = document.createElement('div');
        summary.className = 'error-message';
        summary.dataset.clientErrors = '';
        summary.setAttribute('role', 'alert');
        summary.append(document.createTextNode('Revise os campos indicados: '), document.createElement('ul'));
        input.form.prepend(summary);
    }
    let item = document.getElementById(`${errorId}-link`);
    if (!item) {
        item = document.createElement('li');
        item.id = `${errorId}-link`;
        const link = document.createElement('a');
        link.href = `#${input.id}`;
        link.addEventListener('click', () => input.focus());
        item.append(link);
        summary.querySelector('ul').append(item);
    }
    const label = input.labels?.[0]?.cloneNode(true);
    label?.querySelectorAll('.field-error, [data-field-hint]').forEach((part) => part.remove());
    item.firstElementChild.textContent = `${input.getAttribute('aria-label') || label?.textContent.trim() || 'Campo'}: ${text}`;
};
const clearServerFieldError = (input) => {
    const id = `${input.id}-error`;
    const error = document.getElementById(id);
    if (!error) return;
    error.remove();
    const descriptions = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter((description) => description && description !== id);
    if (descriptions.length) input.setAttribute('aria-describedby', descriptions.join(' ')); else input.removeAttribute('aria-describedby');
    if (!document.getElementById(`${input.id}-client-error`)) input.removeAttribute('aria-invalid');
    document.querySelectorAll('[data-form-errors] a').forEach((link) => {
        if (link.getAttribute('href') !== `#${input.id}`) return;
        const summary = link.closest('[data-form-errors]');
        link.closest('li')?.remove();
        if (summary && !summary.querySelector('li')) summary.remove();
    });
};
const updateVisibleFieldError = (input) => {
    if (!input.id || !input.validity) return;
    const errorId = `${input.id}-client-error`;
    const message = document.getElementById(errorId);
    if (!message) return;
    if (!input.validity.valid) {
        showFieldError(input);
        return;
    }
    message.remove();
    document.getElementById(`${errorId}-link`)?.remove();
    const descriptions = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && id !== errorId);
    if (descriptions.length) input.setAttribute('aria-describedby', descriptions.join(' ')); else input.removeAttribute('aria-describedby');
    if (!document.getElementById(`${input.id}-error`)) input.removeAttribute('aria-invalid');
    const summary = input.form?.querySelector('[data-client-errors]');
    if (summary && !summary.querySelector('li')) summary.remove();
};
document.addEventListener('invalid', (event) => {
    const input = event.target;
    if (!input.form || !input.validity) return;
    event.preventDefault();
    showFieldError(input);
    const form = input.form;
    if (form.noValidate || pendingErrorFocus.has(form)) return;
    pendingErrorFocus.add(form);
    window.setTimeout(() => {
        pendingErrorFocus.delete(form);
        const firstInvalid = [...form.elements].find((field) => field.willValidate && !field.validity.valid && field.getClientRects().length);
        firstInvalid?.focus();
    }, 0);
}, true);
document.addEventListener('input', (event) => updateVisibleFieldError(event.target));
document.addEventListener('change', (event) => updateVisibleFieldError(event.target));

document.querySelectorAll('[data-upload-max]').forEach((input) => {
    const refresh = () => {
        clearServerFieldError(input);
        const tooLarge = input.files[0]?.size > Number(input.dataset.uploadMax);
        input.setCustomValidity(tooLarge ? input.dataset.uploadSizeError : '');
        if (tooLarge) showFieldError(input);
        else updateVisibleFieldError(input);
    };
    input.addEventListener('change', refresh);
    input.addEventListener('gse:file-cleared', refresh);
});

if (window.GseMoney) {
    document.querySelectorAll('[data-money-input]').forEach((input) => {
        const refresh = () => input.setCustomValidity(input.value !== '' && window.GseMoney.cents(input.value) === null
            ? 'Use 1250,00 ou 1.250,00, com até duas casas decimais e valor máximo de R$ 999.999.999,99.' : '');
        input.addEventListener('input', refresh);
        refresh();
    });
}

document.querySelectorAll('[data-staff-grants]').forEach((fieldset) => {
    const role = fieldset.form.elements.namedItem('tipo');
    const updateRole = () => {
        fieldset.hidden = fieldset.disabled = role.value !== 'funcionario';
    };
    role.addEventListener('change', updateRole);
    updateRole();

    fieldset.querySelectorAll('[data-staff-grant]').forEach((grant) => {
        const toggle = grant.querySelector('input[type="checkbox"]');
        const fields = grant.querySelector('[data-staff-grant-fields]');
        const updateGrant = () => {
            fields.hidden = !toggle.checked;
            fields.querySelectorAll('input').forEach((input) => {
                input.disabled = !toggle.checked;
                input.required = toggle.checked;
            });
        };
        toggle.addEventListener('change', updateGrant);
        updateGrant();
    });
});

document.querySelectorAll('[data-contract-certificate-form]').forEach((form) => {
    const supplier = form.querySelector('[name="fornecedor"]');
    const alert = form.querySelector('[data-contract-certificate-alert][data-certificate-report]');
    const submit = form.querySelector('[data-contract-create-submit]');
    if (!supplier || !alert || !submit) return;
    let report;
    try { report = JSON.parse(alert.dataset.certificateReport); } catch { return; }
    const list = alert.querySelector('[data-contract-certificate-list]');
    const update = () => {
        const entry = report[supplier.value];
        const expired = Array.isArray(entry) ? entry : [];
        const blocked = entry === true || expired.length > 0;
        list?.replaceChildren();
        if (list) expired.forEach((certificate) => {
            const item = document.createElement('li');
            const date = certificate.data_vencimento.split('-').reverse().join('/');
            const link = document.createElement('a');
            link.href = `${alert.dataset.certificateDetailsBase}${certificate.id}`;
            link.textContent = certificate.tipo_certidao;
            item.append(link, document.createTextNode(` — ${certificate.fornecedor} — vencida em ${date}.`));
            list.append(item);
        });
        alert.hidden = !blocked;
        submit.disabled = blocked;
        return !blocked;
    };
    supplier.addEventListener('change', update);
    form.addEventListener('submit', (event) => {
        if (!update()) { event.preventDefault(); alert.scrollIntoView({block: 'center'}); }
    });
    update();
});

document.addEventListener('change', (event) => {
    const select = event.target.closest?.('[data-cert-auto-submit]');
    if (select?.form) select.form.requestSubmit();
});

const initCertOptionDialogs = (root = document) => root.querySelectorAll('[data-cert-option-dialog]').forEach((content) => {
    if (typeof HTMLDialogElement === 'undefined') return;
    const item = content.closest('details');
    const opener = item.querySelector('summary');
    const dialog = document.createElement('dialog');
    dialog.className = content.className;
    dialog.setAttribute('aria-labelledby', content.getAttribute('aria-labelledby'));
    dialog.append(...content.childNodes);
    content.replaceWith(dialog);
    const cancel = dialog.querySelector('[data-cert-option-cancel]');
    cancel.hidden = false;
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => {
        item.open = false;
        document.body.classList.remove('cert-option-modal-open');
        opener.focus();
    });
    item.addEventListener('toggle', () => {
        if (item.open && !dialog.open) {
            dialog.showModal();
            document.body.classList.add('cert-option-modal-open');
            dialog.querySelector('input[name="nome"]').focus();
        } else if (!item.open && dialog.open) {
            dialog.close();
        }
    });
});
initCertOptionDialogs();
document.addEventListener('gse:list-state-restored', () => initCertOptionDialogs());

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
        if (certPdfInput.validity.valid && file.type === 'application/pdf' && /\.pdf$/i.test(file.name)) {
            previewUrl = URL.createObjectURL(file);
            frame.src = previewUrl;
            frame.hidden = false;
            open.href = previewUrl;
            open.hidden = false;
        } else {
            meta.textContent += ' · Prévia indisponível para este arquivo.';
        }
    });
    preview.querySelector('[data-pdf-clear]').addEventListener('click', () => {
        certPdfInput.value = '';
        certPdfInput.dispatchEvent(new Event('gse:file-cleared'));
        clearPreview();
        certPdfInput.focus();
    });
    window.addEventListener('pagehide', clearPreview);
}
const certExpiryDate = document.getElementById('data_vencimento');
const certValidityDays = document.getElementById('cert-validity-days');
if (certIssueDate && certExpiryDate && certValidityDays) {
    const expiryOrderError = 'Informe um vencimento igual ou posterior à emissão.';
    const refreshValidity = (event) => {
        const issue = Date.parse(`${certIssueDate.value}T00:00:00Z`);
        const expiry = Date.parse(`${certExpiryDate.value}T00:00:00Z`);
        const days = Math.round((expiry - issue) / 86400000);
        certValidityDays.value = Number.isFinite(days) ? (days < 0 ? 'Inválido' : `${days} dias`) : '...';
        const reversed = Number.isFinite(days) && days < 0;
        certValidityDays.classList.toggle('is-invalid', reversed);
        certExpiryDate.setCustomValidity(reversed ? expiryOrderError : '');
        if (event) {
            const editedField = event.currentTarget;
            if (editedField.validity.valid) clearServerFieldError(editedField);
            if (!reversed && document.getElementById('data_vencimento-error')?.textContent.trim() === expiryOrderError) {
                clearServerFieldError(certExpiryDate);
            }
        }
        if (reversed) showFieldError(certExpiryDate);
        else updateVisibleFieldError(certExpiryDate);
    };
    for (const dateField of [certIssueDate, certExpiryDate]) {
        dateField.addEventListener('input', refreshValidity);
        dateField.addEventListener('change', refreshValidity);
    }
    refreshValidity();
}

let certFullscreenListener = null;
const initCertFullscreen = () => {
    const certWorkspace = document.getElementById('cert-workspace');
    const certFullscreen = document.getElementById('cert-fullscreen');
    if (!certWorkspace || !certFullscreen || certFullscreen.dataset.fullscreenReady === '1') return;
    certFullscreen.dataset.fullscreenReady = '1';
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
    if (certFullscreenListener) document.removeEventListener('fullscreenchange', certFullscreenListener);
    certFullscreenListener = () => {
        const active = document.fullscreenElement === certWorkspace;
        fullscreenBar.hidden = !active;
        if (!active && fullscreenFilters[0]) fullscreenFilters[0].click();
        certFullscreen.setAttribute('aria-pressed', String(active));
        fullscreenLabel.data = active ? ' Sair da tela cheia' : ' Tela cheia';
        status.textContent = active ? 'Pressione Esc para sair.' : '';
        if (active) (fullscreenFilters[0] || fullscreenBar.querySelector('[data-cert-fullscreen-exit]')).focus();
        else certFullscreen.focus();
    };
    document.addEventListener('fullscreenchange', certFullscreenListener);
};
initCertFullscreen();
document.addEventListener('gse:list-state-restored', initCertFullscreen);

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
        button.setAttribute('title', showing ? 'Mostrar senha' : 'Ocultar senha');
    });
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest?.('[data-confirm-status]');
    if (form && !window.confirm(form.dataset.confirmStatus)) event.preventDefault();
});

document.addEventListener('click', (event) => {
    if (event.target.closest?.('[data-print-page]')) window.print();
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
    const cents = window.GseMoney.cents;
    const refresh = () => {
        let total = 0;
        let invalidPrice = false;
        sheetContainer.querySelectorAll('[data-contract-sheet]').forEach((sheet, sheetIndex) => {
            sheet.querySelector('[data-sheet-number]').textContent = String(sheetIndex + 1);
            sheet.querySelector('textarea').name = `folhas[${sheetIndex}][observacao]`;
            sheet.querySelectorAll('[data-contract-product]').forEach((product, productIndex) => {
                product.querySelectorAll('input, select').forEach((input) => {
                    const field = input.name.match(/\[(nome|marca|unidade|quantidade|preco)\]$/)?.[1];
                    if (field) input.name = `folhas[${sheetIndex}][produtos][${productIndex}][${field}]`;
                });
                const quantity = Number(product.querySelector('[name$="[quantidade]"]').value) || 0;
                const price = product.querySelector('[name$="[preco]"]');
                const parsed = cents(price.value);
                price.setCustomValidity(parsed === null ? 'Use 1250,00 ou 1.250,00, com até duas casas decimais.' : '');
                price.setAttribute('aria-invalid', String(parsed === null && price.value !== ''));
                invalidPrice ||= parsed === null;
                if (parsed !== null) {
                    const lineTotal = quantity * parsed;
                    if (!Number.isSafeInteger(lineTotal) || !Number.isSafeInteger(total + lineTotal)) invalidPrice = true;
                    else total += lineTotal;
                }
            });
            sheet.querySelectorAll('[data-remove-product]').forEach((button) => {
                button.disabled = sheet.querySelectorAll('[data-contract-product]').length === 1;
            });
        });
        contractBuilder.querySelectorAll('[data-remove-sheet]').forEach((button) => {
            button.disabled = sheetContainer.querySelectorAll('[data-contract-sheet]').length === 1;
        });
        const budget = document.querySelector('#valor');
        const budgetCents = cents(budget.value);
        budget.setCustomValidity(budgetCents === null ? 'Use 1250,00 ou 1.250,00, com até duas casas decimais.' : '');
        contractBuilder.querySelector('[data-items-total]').textContent = invalidPrice ? 'Confira o preço deste produto' : money(total);
        contractBuilder.querySelector('[data-budget-left]').textContent = invalidPrice || budgetCents === null ? 'Confira os valores informados' : money(budgetCents - total);
        contractBuilder.closest('form')?.dispatchEvent(new Event('gse:form-change'));
    };
    const removalNotice = document.createElement('div');
    removalNotice.className = 'contract-removal-notice';
    removalNotice.setAttribute('role', 'status');
    contractBuilder.append(removalNotice);
    const removeWithUndo = (element, label, focusTarget) => {
        const parent = element.parentNode;
        const next = element.nextSibling;
        const filled = [...element.querySelectorAll('input, textarea, select')].some((input) => input.tagName === 'SELECT' ? input.value !== 'UN' : input.value.trim() !== '');
        element.remove();
        focusTarget?.focus();
        if (!filled) return;
        const notice = document.createElement('p');
        notice.append(document.createTextNode(label === 'Nota' ? 'Nota removida. ' : 'Produto removido. '));
        const undo = document.createElement('button');
        undo.type = 'button'; undo.textContent = 'Desfazer'; undo.dataset.undoRemoval = '';
        notice.append(undo); removalNotice.append(notice);
        undo.addEventListener('click', () => {
            // Restore the containing note too when a later removal detached it.
            if (!parent.isConnected) { undo.textContent = 'Desfaça primeiro a remoção da nota'; return; }
            parent.insertBefore(element, next?.parentNode === parent ? next : null);
            notice.remove(); refresh();
            element.querySelector('input, textarea, select')?.focus();
        });
    };
    const resetProductFields = (element) => {
        element.querySelectorAll('[id$="-client-error"]').forEach((message) => message.remove());
        element.querySelectorAll('input, textarea').forEach((input) => { input.value = ''; });
        element.querySelectorAll('[id^="validation-field-"]').forEach((input) => {
            input.removeAttribute('id'); input.removeAttribute('aria-describedby'); input.removeAttribute('aria-invalid');
        });
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
            removeWithUndo(sheet, 'Nota', contractBuilder.querySelector('[data-add-sheet]'));
        } else if (button.matches('[data-add-product]')) {
            if (contractBuilder.querySelectorAll('[data-contract-product]').length >= 100) return;
            const copy = sheet.querySelector('[data-contract-product]').cloneNode(true);
            resetProductFields(copy);
            sheet.querySelector('[data-contract-products]').append(copy);
            copy.querySelector('input').focus();
        } else if (button.matches('[data-remove-product]') && sheet.querySelectorAll('[data-contract-product]').length > 1) {
            removeWithUndo(button.closest('[data-contract-product]'), 'Produto', sheet.querySelector('[data-add-product]'));
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
        if (focus) {
            const tab = tabs[index];
            tab.focus({preventScroll: true});
            const bounds = contractTabs.getBoundingClientRect();
            const item = tab.getBoundingClientRect();
            if (item.left < bounds.left) contractTabs.scrollLeft += item.left - bounds.left;
            else if (item.right > bounds.right) contractTabs.scrollLeft += item.right - bounds.right;
        }
    };
    const activateTab = (index, focus = false) => {
        const hash = tabs[index].getAttribute('href');
        if (location.hash !== hash) history.pushState(history.state, '', hash);
        select(index, focus);
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
            if (scroll && target !== panels[index]) target?.scrollIntoView({block: 'start'});
        }
    };
    select(0);
    followHash();
    // Select the ancestor before the browser scrolls to a form in a previously hidden note.
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (!link || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        const target = fragmentTarget(link.getAttribute('href'));
        const index = target ? panels.findIndex((panel) => panel === target || panel.contains(target)) : -1;
        if (index >= 0) { select(index); revealFragmentTarget(target); }
    });
    contractTabs.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        const index = tabs.indexOf(event.target.closest('a'));
        if (index >= 0) {
            event.preventDefault();
            activateTab(index);
        }
    });
    contractTabs.addEventListener('keydown', (event) => {
        const current = tabs.indexOf(document.activeElement);
        if (current < 0) return;
        const next = event.key === 'ArrowRight' ? (current + 1) % tabs.length
            : event.key === 'ArrowLeft' ? (current - 1 + tabs.length) % tabs.length
            : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : -1;
        if (next >= 0) { event.preventDefault(); activateTab(next, true); }
    });
    window.addEventListener('hashchange', () => followHash());
    window.addEventListener('pageshow', () => followHash());
    let printIndex = 0;
    let printScroll = 0;
    window.addEventListener('beforeprint', () => { printIndex = selectedIndex; printScroll = window.scrollY; panels.forEach((panel) => { panel.hidden = false; }); });
    window.addEventListener('afterprint', () => { select(printIndex); followHash(false); window.scrollTo(0, printScroll); });
}

const initReportGenerator = (root = document) => {
    const reportGenerator = root.querySelector('[data-report-generator]');
    if (!reportGenerator || reportGenerator.dataset.reportGeneratorReady === '1') return;
    reportGenerator.dataset.reportGeneratorReady = '1';
    const updateReportAction = () => {
        const format = reportGenerator.querySelector('[name="formato"]:checked')?.value;
        reportGenerator.action = format === 'csv' ? reportGenerator.dataset.csvUrl : reportGenerator.dataset.pdfUrl;
        reportGenerator.target = '_blank';
        const button = reportGenerator.querySelector('.btn-gerar');
        if (button) button.textContent = format === 'csv' ? 'Baixar CSV' : 'Visualizar PDF';
    };
    reportGenerator.querySelectorAll('[name="formato"]').forEach((input) => input.addEventListener('change', updateReportAction));
    updateReportAction();
    reportGenerator.addEventListener('submit', (event) => {
        if (event.submitter?.hasAttribute('formaction')) return;
        updateReportAction();
    });
};
initReportGenerator();
document.addEventListener('gse:list-state-restored', () => initReportGenerator());

const studentSelectionKey = (scope) => `gse.passivo-lote.v1:${scope}`;
document.querySelectorAll('[data-student-selection-reset]').forEach((marker) => {
    try { sessionStorage.removeItem(studentSelectionKey(marker.dataset.selectionScope)); } catch (_) { /* A seleção também pode funcionar apenas na página atual. */ }
});

let restoreStudentArchiveSelection = () => {};
const initStudentArchiveSelection = () => {
    const studentArchiveSelection = document.querySelector('[data-student-archive-selection]');
    if (!studentArchiveSelection || studentArchiveSelection.dataset.selectionReady === '1') return;
    studentArchiveSelection.dataset.selectionReady = '1';
    const limit = Math.min(200, Math.max(1, Number(studentArchiveSelection.dataset.selectionLimit) || 200));
    const storageKey = studentSelectionKey(studentArchiveSelection.dataset.selectionScope);
    const checkboxes = [...document.querySelectorAll('[data-student-selection-id]')];
    const available = checkboxes.filter((checkbox) => !checkbox.hasAttribute('data-student-selection-unavailable'));
    const summary = studentArchiveSelection.querySelector('[data-student-selection-summary]');
    const submit = studentArchiveSelection.querySelector('[data-student-selection-submit]');
    const regress = studentArchiveSelection.querySelector('[data-student-regress-submit]');
    const selectPage = studentArchiveSelection.querySelector('[data-student-select-page]');
    const clear = studentArchiveSelection.querySelector('[data-student-clear-selection]');
    const offPage = studentArchiveSelection.querySelector('[data-student-offpage-selection]');
    const validId = (id) => typeof id === 'string' && /^[1-9][0-9]{0,18}$/.test(id);
    let storageAvailable = true;
    let stored = [];
    try {
        const parsed = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
        if (Array.isArray(parsed) && parsed.length <= limit) stored = parsed.filter(validId);
    } catch (_) { storageAvailable = false; }
    const selected = new Set(stored);
    checkboxes.filter((checkbox) => checkbox.hasAttribute('data-student-selection-unavailable')).forEach((checkbox) => selected.delete(checkbox.value));
    let notice = '';
    summary.tabIndex = -1;
    const save = () => {
        try {
            if (selected.size) sessionStorage.setItem(storageKey, JSON.stringify([...selected]));
            else sessionStorage.removeItem(storageKey);
        } catch (_) { storageAvailable = false; }
    };
    const refresh = () => {
        let visibleSelected = 0;
        checkboxes.forEach((checkbox) => {
            const unavailable = checkbox.hasAttribute('data-student-selection-unavailable');
            checkbox.checked = !unavailable && selected.has(checkbox.value);
            checkbox.disabled = unavailable || (selected.size >= limit && !checkbox.checked);
            checkbox.closest('tr')?.classList.toggle('student-row-selected', checkbox.checked);
            if (checkbox.checked) visibleSelected += 1;
        });
        const pageIds = new Set(checkboxes.map((checkbox) => checkbox.value));
        const fragment = document.createDocumentFragment();
        selected.forEach((id) => {
            if (pageIds.has(id)) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'alunos[]';
            input.value = id;
            fragment.append(input);
        });
        offPage.replaceChildren(fragment);
        save();
        const outside = selected.size - visibleSelected;
        summary.textContent = `${selected.size} de ${limit} aluno(s) selecionado(s)${outside ? `; ${outside} fora desta página` : ''}. ${storageAvailable ? 'A seleção acompanha páginas e filtros.' : 'O navegador não permite guardar a seleção; continue nesta página.'}${notice ? ` ${notice}` : ''}`;
        submit.disabled = selected.size === 0;
        if (regress) { regress.hidden = selected.size === 0; regress.disabled = selected.size === 0; }
        clear.disabled = selected.size === 0;
        const wholePageSelected = available.length > 0 && available.every((checkbox) => selected.has(checkbox.value));
        selectPage.textContent = wholePageSelected ? 'Desmarcar todos nesta página' : 'Marcar todos nesta página';
        selectPage.setAttribute('aria-pressed', String(wholePageSelected));
        selectPage.disabled = available.length === 0;
    };
    available.forEach((checkbox) => checkbox.addEventListener('change', () => {
        notice = '';
        if (checkbox.checked && selected.size < limit && validId(checkbox.value)) selected.add(checkbox.value);
        else selected.delete(checkbox.value);
        refresh();
    }));
    selectPage.hidden = false;
    clear.hidden = false;
    selectPage.addEventListener('click', () => {
        notice = '';
        if (available.every((checkbox) => selected.has(checkbox.value))) available.forEach((checkbox) => selected.delete(checkbox.value));
        else available.forEach((checkbox) => {
            if (selected.has(checkbox.value)) return;
            if (selected.size < limit && validId(checkbox.value)) selected.add(checkbox.value);
            else notice = `Limite de ${limit} alunos atingido. Desmarque alunos para incluir outros.`;
        });
        refresh();
    });
    clear.addEventListener('click', () => { selected.clear(); notice = ''; refresh(); });
    studentArchiveSelection.addEventListener('submit', (event) => {
        refresh();
        if (selected.size === 0) { event.preventDefault(); summary.focus(); }
    });
    restoreStudentArchiveSelection = (persisted = false) => {
        if (persisted && storageAvailable) {
            try {
                const parsed = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
                selected.clear();
                if (Array.isArray(parsed) && parsed.length <= limit) parsed.filter(validId).forEach((id) => selected.add(id));
                checkboxes.filter((checkbox) => checkbox.hasAttribute('data-student-selection-unavailable')).forEach((checkbox) => selected.delete(checkbox.value));
            } catch (_) { storageAvailable = false; }
        }
        refresh();
    };
    refresh();
};
initStudentArchiveSelection();
document.addEventListener('gse:list-state-restored', initStudentArchiveSelection);
window.addEventListener('pageshow', (event) => restoreStudentArchiveSelection(event.persisted));

document.querySelectorAll('[data-archive-batch-box]').forEach((form) => {
    const mode = form.querySelector('[data-archive-box-mode]');
    const fields = [...form.querySelectorAll('[data-archive-box-field]')];
    const refreshBox = () => fields.forEach((field) => {
        const chosen = field.dataset.archiveBoxField === mode.value;
        field.hidden = !chosen;
        field.querySelectorAll('input, select').forEach((input) => { input.disabled = !chosen; input.required = chosen; });
    });
    mode.addEventListener('change', refreshBox);
    refreshBox();
});
// Count Unicode code points, as the server does; native maxlength counts UTF-16 units.
document.querySelectorAll('[data-password-max]').forEach((input) => {
    const validate = () => {
        const password = input.value;
        const length = Array.from(password).length;
        const min = Number(input.dataset.passwordMin || 0);
        const max = Number(input.dataset.passwordMax);
        let message = '';
        if (length > max) {
            message = `Use no máximo ${max} caracteres.`;
        } else if (min > 0 && length > 0) {
            if (length < min) {
                message = `Use pelo menos ${min} caracteres.`;
            } else if (!/\p{Lu}/u.test(password) || !/\p{Nd}/u.test(password) || !/[\p{P}\p{S}]/u.test(password)) {
                message = 'A senha deve incluir uma letra maiúscula, um número e um caractere especial.';
            }
        }
        input.setCustomValidity(message);
    };
    input.addEventListener('input', validate);
    validate();
});

// Installed at document level after form-specific validation/confirmation handlers.
const pendingForms = new Map();
const releaseForm = (form) => {
    clearTimeout(pendingForms.get(form));
    pendingForms.delete(form);
    form.removeAttribute('aria-busy');
    form.querySelector('[data-submit-status]')?.remove();
};
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented || !form.checkValidity()) return;
    if (pendingForms.has(form)) { event.preventDefault(); return; }
    const submitter = event.submitter;
    const action = submitter?.hasAttribute('formaction') ? submitter.formAction : form.action;
    const target = submitter?.hasAttribute('formtarget') ? submitter.formTarget : form.target;
    // Downloads do not navigate away; retain a short repeat guard and release it.
    const downloadFlag = submitter?.getAttribute('data-submit-download') ?? form.getAttribute('data-submit-download');
    const download = downloadFlag !== null ? downloadFlag === 'true'
        : target === '_blank' || /(?:exportar|imprimir|download|baixar)/.test(new URL(action, location.href).pathname);
    pendingForms.set(form, download ? setTimeout(() => releaseForm(form), 3000) : null);
    form.setAttribute('aria-busy', 'true');
    const status = document.createElement('span');
    status.dataset.submitStatus = '';
    status.setAttribute('role', 'status');
    status.textContent = ' Processando…';
    form.append(status);
    // Keep the submitter enabled so its name/value and formaction are submitted.
});
window.addEventListener('pageshow', () => [...pendingForms.keys()].forEach(releaseForm));
