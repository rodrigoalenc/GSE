// Personal drafts are kept in the authenticated server session, never browser storage.
(() => {
    const endpoint = document.body.dataset.draftEndpoint;
    const menuToggle=document.querySelector('[data-sidebar-toggle]');
    if (menuToggle) {
        const sidebar=menuToggle.closest('.sidebar');
        const mobile=matchMedia('(max-width: 768px)');
        const syncMenu=()=>{menuToggle.hidden=!mobile.matches;sidebar.classList.add('is-mobile-menu-ready');};
        menuToggle.addEventListener('click',()=>{
            const open=sidebar.classList.toggle('is-mobile-menu-open');
            menuToggle.setAttribute('aria-expanded',String(open));
            menuToggle.textContent=open?'Fechar menu de funções':'☰ Menu de funções';
        });
        mobile.addEventListener('change',syncMenu); syncMenu();
    }
    const entries = [];
    let navigating = false;
    const signature = (form) => JSON.stringify([...form.elements].filter(el => el.name && !el.disabled && !['hidden','submit','button'].includes(el.type)).map(el => [el.name, ['checkbox','radio'].includes(el.type) ? el.checked : el.value]));
    const targetOf = (form) => {
        const action = new URL(form.action, location.href);
        if (action.origin !== location.origin) return null;
        return action.pathname.match(/(?:^|\/)((?:aluno\/(?:criar|editar\/[1-9][0-9]*|dva\/[1-9][0-9]*)|contrato\/criar|certidao\/(?:cadastrar|editar\/[1-9][0-9]*|renovar\/[1-9][0-9]*)|passivo\/(?:criar|editar\/[1-9][0-9]*)))$/)?.[1] || null;
    };
    const businessFields = (form) => Object.fromEntries([...form.elements].filter(el => el.name && !el.disabled && !['password','file','hidden','submit','button','reset','checkbox','radio'].includes(el.type)).map(el => [el.name, el.value]));
    document.querySelectorAll('form[method="post"]').forEach(form => {
        if (form.closest('.sidebar') || !form.querySelector('input:not([type="hidden"]):not([type="submit"]),textarea,select')) return;
        const entry = {form, initial:signature(form), dirty:false, timer:null, disabled:false, version:0, chain:Promise.resolve()};
        entries.push(entry);
        const key = targetOf(form);
        let status, recover, discard, savedDraft, context='';
        const token = form.querySelector('[name="_csrf_token"]')?.value;
        const request = async (operation, fields = null) => {
            if (!context) throw new Error('draft-unavailable');
            const draftRevision=form.elements.namedItem('_draft_source_revision')?.value || form.querySelector('[data-conflict-review]')?.dataset.sourceRevision || form.elements.namedItem('revisao')?.value || '';
            const body = new URLSearchParams({_csrf_token:token,form:key,operation,context,revision:draftRevision});
            if (fields) body.set('fields', JSON.stringify(fields));
            const response = await fetch(endpoint, {method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body});
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('draft-unavailable');
            const result = await response.json();
            if (!result.ok) throw new Error('draft-unavailable');
        };
        const save = () => {
            if (!key || !endpoint || !token || !entry.dirty || entry.disabled) return;
            const fields = businessFields(form), version = entry.version;
            status.textContent = 'Salvando rascunho…';
            entry.chain = entry.chain.catch(()=>{}).then(()=>request('save', fields)).then(()=>{
                if (version === entry.version) { discard.hidden=false;status.textContent = 'Rascunho salvo nesta sessão por até 30 minutos. Ainda falta salvar o cadastro.'; }
            }).catch(()=>{status.textContent='Não foi possível guardar o rascunho. Mantenha esta tela aberta e tente salvar o formulário.';});
        };
        const changed = () => {
            entry.dirty = signature(form) !== entry.initial;
            entry.version++; entry.disabled = false;
            clearTimeout(entry.timer);
            if (entry.dirty && key && endpoint && token) entry.timer = setTimeout(save, 900);
        };
        form.addEventListener('input', changed); form.addEventListener('change', changed);
        form.addEventListener('gse:form-change', changed);
        if (!key || !endpoint || !token) return;
        const box = document.createElement('section'); box.className='form-draft'; box.setAttribute('aria-label','Proteção do preenchimento');
        status=document.createElement('p'); status.dataset.draftStatus=''; status.setAttribute('role','status');
        status.textContent='Preenchimento protegido durante esta sessão. Use Salvar para concluir.';
        const help=document.createElement('details'); const helpTitle=document.createElement('summary'); helpTitle.textContent='Como funciona a recuperação';
        const helpText=document.createElement('p'); helpText.textContent='O rascunho fica no servidor por até 30 minutos desde a última gravação. Sair da conta ou expirar a sessão remove essa recuperação. Arquivos e senhas não são recuperados.';help.append(helpTitle,helpText);
        recover=document.createElement('button'); recover.type='button'; recover.className='btn-secondary'; recover.dataset.draftRecover=''; recover.textContent='Recuperar preenchimento'; recover.hidden=true;
        discard=document.createElement('button'); discard.type='button'; discard.className='btn-secondary'; discard.dataset.draftDiscard=''; discard.textContent='Descartar rascunho'; discard.hidden=true;
        box.append(status,help,recover,discard); form.prepend(box);
        recover.addEventListener('click',()=>{
            if (!savedDraft) return;
            const currentRevision=form.elements.namedItem('revisao')?.value || '';
            if (key.startsWith('aluno/editar/') && savedDraft.revision && currentRevision && savedDraft.revision!==currentRevision) {
                let source=form.querySelector('[name="_draft_source_revision"]');
                if (!source) { source=document.createElement('input');source.type='hidden';source.name='_draft_source_revision';form.append(source); }
                source.value=savedDraft.revision;
            } else if (savedDraft.revision && currentRevision && savedDraft.revision!==currentRevision) {
                // Other existing editors reject the originating revision instead of
                // silently promoting old fields to the current record version.
                form.elements.namedItem('revisao').value=savedDraft.revision;
            }
            if (key==='contrato/criar') form.dispatchEvent(new CustomEvent('gse:restore-products',{detail:savedDraft.fields}));
            for (const [name,value] of Object.entries(savedDraft.fields)) {
                const control = form.elements.namedItem(name);
                if (!(control instanceof HTMLElement) || control.disabled || control.readOnly || ['hidden','password','file','checkbox','radio','submit','button'].includes(control.type)) continue;
                control.value=String(value); control.dispatchEvent(new Event('input',{bubbles:true})); control.dispatchEvent(new Event('change',{bubbles:true}));
            }
            changed(); recover.hidden=true;
            status.textContent='Preenchimento recuperado. Confira todos os campos antes de salvar. Se o aluno mudou desde o rascunho, o sistema exigirá comparar as duas versões. Se havia PDF, selecione-o novamente.';
            form.querySelector('input:not([type="hidden"]):not([readonly]),textarea,select')?.focus();
        });
        discard.addEventListener('click',()=>{
            clearTimeout(entry.timer); entry.version++; entry.disabled=true; recover.hidden=true; savedDraft=null;
            entry.chain=entry.chain.catch(()=>{}).then(()=>request('discard')).then(()=>{discard.hidden=true;status.textContent='Rascunho descartado. O preenchimento desta tela continua disponível até você sair dela.';}).catch(()=>{status.textContent='Não foi possível descartar o rascunho. Tente novamente.';});
        });
        fetch(`${endpoint}?${new URLSearchParams({form:key})}`,{credentials:'same-origin',headers:{Accept:'application/json'}}).then(async response=>{
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('draft-unavailable');
            return response.json();
        }).then(({draft,context:opened})=>{
            context=opened || '';
            if (entry.dirty) { clearTimeout(entry.timer); save(); return; }
            if (form.querySelector('[data-conflict-review]')) { status.textContent='Sua tentativa está preservada nesta tela. Compare as versões e confirme a revisão antes de salvar.'; return; }
            if (!draft) return;
            savedDraft=draft; recover.hidden=false;
            discard.hidden=false;
            const current=form.elements.namedItem('revisao')?.value || '';
            status.textContent=draft.revision && current && draft.revision!==current
                ? 'Há um preenchimento anterior, mas este registro mudou. Recupere apenas para comparar e confira cada campo antes de salvar.'
                : 'Há um preenchimento não concluído nesta sessão. Você pode recuperá-lo ou descartá-lo. Arquivos precisam ser selecionados novamente.';
        }).catch(()=>{status.textContent='Recuperação de rascunho indisponível. O formulário continua funcionando; mantenha a tela aberta enquanto preenche.';});
    });
    document.addEventListener('submit',event=>{
        if (event.defaultPrevented || !event.target.checkValidity()) return;
        const submitter=event.submitter;
        const target=submitter?.formTarget || event.target.target;
        if (target==='_blank' || submitter?.hasAttribute('data-submit-download') || event.target.hasAttribute('data-submit-download')) return;
        navigating=true; entries.forEach(entry=>clearTimeout(entry.timer));
    });
    window.addEventListener('beforeunload',event=>{
        if (!navigating && entries.some(entry=>entry.dirty)) { event.preventDefault(); event.returnValue=''; }
    });
    window.addEventListener('pageshow',()=>{navigating=false;});

    // Filters stay on the server; browser history retains only the scroll position.
    let listLinks = {};
    try { listLinks=JSON.parse(document.body.dataset.listContext || '{}'); } catch (_) { /* Ordinary links remain usable. */ }
    document.querySelectorAll('main a[href]').forEach(link=>{
        if (!/\b(voltar|cancelar)\b/i.test(link.textContent)) return;
        const mapped=listLinks[link.getAttribute('href')];
        if (mapped) link.href=mapped;
    });
    if (document.body.dataset.listPage) {
        const rememberScroll=()=>{
            try { history.replaceState({...history.state,gseListScroll:{x:scrollX,y:scrollY}},''); } catch (_) { /* Native navigation remains usable. */ }
        };
        document.addEventListener('click',event=>{if(event.target.closest('a[href]')) rememberScroll();});
        window.addEventListener('pagehide',rememberScroll);
        const position=history.state?.gseListScroll;
        if (Number.isFinite(position?.y)) requestAnimationFrame(()=>requestAnimationFrame(()=>window.scrollTo(position.x || 0,position.y)));
    }
})();
