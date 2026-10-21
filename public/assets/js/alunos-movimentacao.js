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
