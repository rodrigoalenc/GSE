(() => {
    'use strict';

    const form = document.querySelector('[data-agenda-importacao]');
    if (!form) return;

    const choices = [...form.querySelectorAll('input[name="selecionados[]"]')];
    const status = form.querySelector('[data-agenda-selecionados]');
    const submit = form.querySelector('button[type="submit"]');
    const update = () => {
        const total = choices.filter(choice => choice.checked).length;
        status.textContent = `${total} de ${choices.length} selecionados`;
        submit.disabled = total === 0;
    };
    const setAll = selected => {
        choices.forEach(choice => { choice.checked = selected; });
        update();
    };

    form.querySelector('[data-agenda-marcar]').addEventListener('click', () => setAll(true));
    form.querySelector('[data-agenda-desmarcar]').addEventListener('click', () => setAll(false));
    form.addEventListener('change', update);
    form.querySelector('.agenda-importacao-atalhos').hidden = false;
    update();
})();
