(() => {
    'use strict';
    const workspace = document.querySelector('[data-student-workspace]');
    const card = workspace?.querySelector('[data-student-preview-card]');
    if (!workspace || !card) return;

    const rows = [...workspace.querySelectorAll('[data-student-preview-row]')];
    const announcement = workspace.querySelector('[data-student-preview-announcement]');
    const returnButton = card.querySelector('[data-student-preview-return]');
    const statusNames = new Set(['vigente', 'a_vencer', 'vencida', 'vence_hoje', 'sem_dva']);
    let selectedTrigger = workspace.querySelector('[data-student-preview-select][aria-pressed="true"]');

    const select = (row, reveal = false) => {
        let student;
        try { student = JSON.parse(row.dataset.studentPreview); } catch { return; }
        for (const candidate of rows) {
            const selected = candidate === row;
            candidate.classList.toggle('is-preview-selected', selected);
            candidate.querySelector('[data-student-preview-select]')?.setAttribute('aria-pressed', String(selected));
        }
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
        selectedTrigger = row.querySelector('[data-student-preview-select]');
        if (announcement) announcement.textContent = `Detalhes de ${student.name} exibidos no painel.`;
        if (reveal && matchMedia('(max-width: 1050px)').matches) {
            card.scrollIntoView({block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
        }
    };

    for (const row of rows) {
        const trigger = row.querySelector('[data-student-preview-select]');
        if (trigger) {
            trigger.hidden = false;
            trigger.addEventListener('click', () => select(row, true));
        }
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
        returnButton.addEventListener('click', () => selectedTrigger?.focus());
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
})();
