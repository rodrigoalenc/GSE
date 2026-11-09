(() => {
    'use strict';
    const init = () => {
    const workspace = document.querySelector('[data-student-workspace]');
    const card = workspace?.querySelector('[data-student-preview-card]');
    if (!workspace || !card || workspace.dataset.studentPreviewReady === '1') return;
    workspace.dataset.studentPreviewReady = '1';

    const rows = [...workspace.querySelectorAll('[data-student-preview-row]')];
    const announcement = workspace.querySelector('[data-student-preview-announcement]');
    const returnButton = card.querySelector('[data-student-preview-return]');
    const statusNames = new Set(['vigente', 'a_vencer', 'vencida', 'vence_hoje', 'sem_dva']);
    let selectedRow = workspace.querySelector('[data-student-preview-row].is-preview-selected');

    const select = (row, reveal = false) => {
        let student;
        try { student = JSON.parse(row.dataset.studentPreview); } catch { return; }
        selectedRow?.classList.remove('is-preview-selected');
        selectedRow?.querySelector('[data-student-preview-name]')?.removeAttribute('aria-current');
        selectedRow = row;
        row.classList.add('is-preview-selected');
        row.querySelector('[data-student-preview-name]')?.setAttribute('aria-current', 'true');
        for (const field of card.querySelectorAll('[data-preview-field]')) {
            field.textContent = student[field.dataset.previewField] ?? '';
        }
        const badge = card.querySelector('[data-preview-dva]');
        if (badge && statusNames.has(student.dvaStatus)) {
            badge.className = `dva-badge dva-${student.dvaStatus}`;
            badge.textContent = student.dvaLabel;
        }
        const profile = card.querySelector('[data-preview-profile]');
        if (profile) profile.href = student.profile;
        if (announcement) announcement.textContent = `Detalhes de ${student.name} exibidos no painel.`;
        if (reveal && matchMedia('(max-width: 1050px)').matches) {
            card.scrollIntoView({block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
        }
    };

    for (const row of rows) {
        row.querySelector('[data-student-preview-name]')?.addEventListener('click', (event) => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            select(row, true);
        });
        row.addEventListener('click', (event) => {
            if (event.target.closest('a, button, input, summary, details, label')) return;
            select(row, true);
        });
    }
    if (returnButton && rows.length) {
        returnButton.hidden = false;
        returnButton.addEventListener('click', () => selectedRow?.querySelector('[data-student-preview-name]')?.focus());
    }
    const actionMenus = [...workspace.querySelectorAll('.student-row-actions, .students-more-actions')];
    for (const menu of actionMenus) {
        menu.addEventListener('toggle', () => {
            if (menu.open) for (const other of actionMenus) if (other !== menu) other.open = false;
        });
    }
    document.addEventListener('click', (event) => {
        for (const menu of actionMenus) if (!menu.contains(event.target)) menu.open = false;
    });
    workspace.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const menu = event.target.closest('details[open]');
        if (menu && actionMenus.includes(menu)) {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        }
    });
    };
    init();
    document.addEventListener('gse:list-state-restored', init);
})();
