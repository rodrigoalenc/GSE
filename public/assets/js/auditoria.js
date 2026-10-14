const logSearch = document.getElementById('filtroPainel');
if (logSearch && document.getElementById('corpoLogs')) {
    const normalizeLogText = (value) => value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const logRows = [...document.querySelectorAll('#corpoLogs .item-filtrado')].map((row) => ({
        row,
        fields: [...row.querySelectorAll('.filtro-user, .filtro-acao, .filtro-detalhe')].map((cell) => normalizeLogText(cell.textContent)),
    }));
    const filterLogs = () => {
        const query = normalizeLogText(logSearch.value.trim());
        let matches = 0;
        logRows.forEach(({row, fields}) => {
            row.hidden = !fields.some((value) => value.includes(query));
            if (!row.hidden) matches++;
        });
        document.getElementById('logs-sem-resultados').hidden = !query || matches > 0 || logRows.length === 0;
    };
    logSearch.addEventListener('input', filterLogs);
    logSearch.addEventListener('keyup', filterLogs);
    filterLogs();
}
