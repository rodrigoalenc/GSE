document.querySelector('[data-movement-archive-class] select')?.addEventListener('change', (event) => {
    if (event.target.value) event.target.form.requestSubmit();
});

document.querySelectorAll('[data-movement-selection]').forEach((form) => {
    const boxes = [...form.querySelectorAll('input[name="alunos[]"]')];
    const summary = form.querySelector('[data-movement-count]');
    const refresh = () => {
        const count = boxes.filter((box) => box.checked).length;
        summary.textContent = `${count} aluno(s) selecionado(s) de ${boxes.length}. Limite: 200 por lote.`;
    };
    const select = form.querySelector('[data-movement-select-all]');
    const clear = form.querySelector('[data-movement-clear]');
    select.hidden = clear.hidden = false;
    select.addEventListener('click', () => { boxes.forEach((box) => { box.checked = true; }); refresh(); });
    clear.addEventListener('click', () => { boxes.forEach((box) => { box.checked = false; }); refresh(); });
    boxes.forEach((box) => box.addEventListener('change', refresh));
    refresh();
});

document.querySelectorAll('[data-movement-bulk]').forEach((form) => {
    const groups = [...form.querySelectorAll('[data-bulk-class]')];
    const refresh = () => {
        let total = 0;
        groups.forEach((group) => {
            const boxes = [...group.querySelectorAll('input[name="manter[]"]')];
            const remaining = boxes.filter((box) => box.checked).length;
            const moving = boxes.length - remaining;
            group.querySelector('[data-bulk-count]').textContent = `${moving} aluno(s) vão progredir; ${remaining} permanecerão.`;
            const destination = group.querySelector('select[data-bulk-destination]');
            if (destination) destination.disabled = moving === 0;
            total += moving;
        });
        form.querySelector('[data-bulk-total]').textContent = `Total: ${total} aluno(s) vão progredir.`;
    };
    form.querySelectorAll('input[name="manter[]"]').forEach((box) => box.addEventListener('change', refresh));
    refresh();
});
