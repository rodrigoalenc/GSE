(() => {
    const body = document.body;
    const route = body.dataset.listPage || '';
    const tokenPattern = /^[a-f0-9]{32}$/;
    const storageKey = (name) => `gse:list-state:${name}`;
    let restoring = false;

    const technicalState = (name, token) => ({gseListState: {route: name, token}});
    const store = (name, token) => {
        if (!name || !tokenPattern.test(token)) return;
        try { sessionStorage.setItem(storageKey(name), token); } catch (_) { /* Full-page POST remains available. */ }
    };
    const stored = (name) => {
        try {
            const value = sessionStorage.getItem(storageKey(name)) || '';
            return tokenPattern.test(value) ? value : '';
        } catch (_) { return ''; }
    };
    const csrf = () => document.querySelector('input[name="_csrf_token"]')?.value || '';
    const responseDocument = async (response) => {
        if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) throw new Error('list-state-response');
        return new DOMParser().parseFromString(await response.text(), 'text/html');
    };
    const install = (next, mode, requestedToken = '') => {
        const nextMain = next.querySelector('main#conteudo-principal');
        const currentMain = document.querySelector('main#conteudo-principal');
        if (!nextMain || !currentMain) throw new Error('list-state-content');
        currentMain.replaceWith(nextMain);
        body.dataset.listStateToken = next.body.dataset.listStateToken || '';
        body.dataset.listStateExpired = next.body.dataset.listStateExpired || '0';
        const nextToken = body.dataset.listStateToken;
        if (tokenPattern.test(nextToken)) {
            store(route, nextToken);
            const state = technicalState(route, nextToken);
            if (mode === 'push') history.pushState({...history.state, ...state}, '', location.pathname + location.hash);
            else history.replaceState({...history.state, ...state}, '', location.pathname + location.hash);
        } else if (mode !== 'keep') {
            const state = {...history.state}; delete state.gseListState;
            history.replaceState(state, '', location.pathname + location.hash);
        }
        document.dispatchEvent(new CustomEvent('gse:list-state-restored', {detail: {route, token: nextToken || requestedToken}}));
        nextMain.focus({preventScroll: true});
    };
    const request = async (action, stateToken = '', formData = null, mode = 'replace') => {
        if (!route || restoring) return;
        restoring = true;
        try {
            const data = formData || new FormData();
            if (!data.has('_csrf_token')) data.set('_csrf_token', csrf());
            data.set('_list_state_action', action);
            if (stateToken) data.set('_list_state_token', stateToken);
            const response = await fetch(location.pathname, {method: 'POST', credentials: 'same-origin', headers: {'X-GSE-List-State': '1'}, body: data});
            install(await responseDocument(response), mode, stateToken);
        } catch (_) {
            const main = document.querySelector('main#conteudo-principal');
            if (main && !main.querySelector('[data-list-state-error]')) {
                const warning = document.createElement('p'); warning.className = 'warning-message'; warning.dataset.listStateError = '';
                warning.textContent = 'Não foi possível restaurar esta pesquisa. Recarregue a tela para abrir os filtros padrão.';
                main.prepend(warning);
            }
        } finally {
            restoring = false;
        }
    };

    document.addEventListener('submit', (event) => {
        const form = event.target.closest?.('form[data-list-state-form]');
        if (!form || event.defaultPrevented || !form.checkValidity() || !route) return;
        const submitter = event.submitter;
        const actionValue = submitter?.hasAttribute('formaction') ? submitter.formAction : form.action;
        const action = new URL(actionValue, location.href);
        if (action.origin !== location.origin || action.pathname !== location.pathname) return;
        event.preventDefault();
        const data = new FormData(form, submitter || undefined);
        request('apply', '', data, 'push').finally(() => {
            form.removeAttribute('aria-busy');
            form.querySelector('[data-submit-status]')?.remove();
        });
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('a.context-return');
        if (!link) return;
        const target = new URL(link.href, location.href).pathname.replace(/^.*\//, '');
        try { sessionStorage.setItem('gse:list-state:return', target); } catch (_) { /* Clean link remains usable. */ }
    });

    window.addEventListener('popstate', (event) => {
        if (!route) return;
        const state = event.state?.gseListState;
        if (state?.route === route && tokenPattern.test(state.token || '')) request('restore', state.token, null, 'keep');
        else fetch(location.pathname, {credentials: 'same-origin'}).then(responseDocument).then((next) => install(next, 'keep')).catch(() => location.reload());
    });

    if (!route) return;
    const serverToken = body.dataset.listStateToken || '';
    if (tokenPattern.test(serverToken)) {
        store(route, serverToken);
        history.replaceState({...history.state, ...technicalState(route, serverToken)}, '', location.pathname + location.hash);
        return;
    }
    let returnRoute = '';
    try {
        returnRoute = sessionStorage.getItem('gse:list-state:return') || '';
        sessionStorage.removeItem('gse:list-state:return');
    } catch (_) { /* Start from defaults. */ }
    const historyToken = history.state?.gseListState?.route === route ? history.state.gseListState.token : '';
    const token = tokenPattern.test(historyToken || '') ? historyToken : (returnRoute === route ? stored(route) : '');
    if (token) request('restore', token, null, 'replace');
})();
