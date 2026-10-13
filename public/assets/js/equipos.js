(() => {
    const form = document.querySelector('[data-team-form]');
    if (!form) return;

    const feedback = document.querySelector('[data-feedback]');
    const submit = form.querySelector('[data-submit]');
    const repOutput = form.querySelector('[data-rep]');
    const titleInput = form.querySelector('#titulo_equipo');
    const descInput = form.querySelector('#descripcion_equipo');
    const titleCounter = form.querySelector('[data-title-counter]');
    const pickers = [...form.querySelectorAll('[data-picker]')];
    const teamList = document.querySelector('[data-team-list]');
    const teamCount = document.querySelector('[data-team-count]');
    const teamEmpty = document.querySelector('[data-team-empty]');
    const teamFilter = document.querySelector('[data-team-filter]');

    const required = { directores: 2, sinodales: 3, seguimiento: 1 };
    const docenteRoles = ['directores', 'sinodales', 'seguimiento'];

    const normalize = (text) => text.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const optionsOf = (picker) => [...picker.querySelectorAll('.picker-option')];
    const checkedOf = (picker) => optionsOf(picker).filter((option) => option.querySelector('input').checked);

    function showFeedback(type, message) {
        feedback.hidden = !message;
        feedback.className = `alert alert-${type}`;
        feedback.textContent = message;
        if (message) feedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function metaText(option) {
        const load = Number(option.dataset.load);
        const max = Number(option.dataset.maxLoad);
        return load >= max ? 'Límite alcanzado' : `${load} de ${max} equipos`;
    }

    function updateOptionStates() {
        // Un docente solo puede tener un rol por equipo
        const takenBy = new Map();
        for (const picker of pickers) {
            if (!docenteRoles.includes(picker.dataset.role)) continue;
            for (const option of checkedOf(picker)) takenBy.set(option.dataset.id, picker.dataset.label);
        }

        for (const picker of pickers) {
            const max = Number(picker.dataset.max);
            const isFull = max > 1 && checkedOf(picker).length >= max;
            const isDocente = docenteRoles.includes(picker.dataset.role);

            for (const option of optionsOf(picker)) {
                const input = option.querySelector('input');
                const meta = option.querySelector('[data-meta]');
                const atLimit = isDocente && Number(option.dataset.load) >= Number(option.dataset.maxLoad);
                const usedElsewhere = isDocente && takenBy.has(option.dataset.id) && !input.checked;
                const blocked = !input.checked && (atLimit || usedElsewhere || isFull);

                input.disabled = blocked;
                option.classList.toggle('is-disabled', blocked);
                option.classList.toggle('is-selected', input.checked);
                if (isDocente) {
                    meta.textContent = usedElsewhere ? `Ya elegido como ${takenBy.get(option.dataset.id)}` : metaText(option);
                }
            }
        }
    }

    function renderChips(picker) {
        const chips = picker.querySelector('[data-chips]');
        chips.replaceChildren();
        for (const option of checkedOf(picker)) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'chip';
            chip.title = 'Quitar';
            chip.textContent = `${option.dataset.name} ×`;
            chip.addEventListener('click', () => {
                option.querySelector('input').checked = false;
                refresh();
            });
            chips.append(chip);
        }
        const max = Number(picker.dataset.max);
        const count = checkedOf(picker).length;
        const counter = picker.querySelector('[data-count]');
        counter.textContent = max > 0 ? `${count}/${max}` : String(count);
        counter.classList.toggle('is-complete', max > 0 && count === max);
    }

    function updateRepresentative() {
        const students = checkedOf(pickers.find((p) => p.dataset.role === 'estudiantes'));
        students.sort((a, b) => a.dataset.sort.localeCompare(b.dataset.sort));
        repOutput.textContent = students.length ? students[0].dataset.name : '—';
    }

    function validate() {
        const status = {
            titulo: titleInput.value.trim() !== '' && descInput.value.trim() !== '',
            estudiantes: checkedOf(pickers.find((p) => p.dataset.role === 'estudiantes')).length >= 1,
        };
        for (const picker of pickers) {
            if (required[picker.dataset.role]) {
                status[picker.dataset.role] = checkedOf(picker).length === required[picker.dataset.role];
            }
        }
        for (const item of form.querySelectorAll('[data-check]')) {
            item.classList.toggle('is-ok', Boolean(status[item.dataset.check]));
        }
        submit.disabled = !Object.values(status).every(Boolean);
    }

    function refresh() {
        pickers.forEach(renderChips);
        updateOptionStates();
        updateRepresentative();
        validate();
    }

    function applyFilter(picker, term) {
        const query = normalize(term.trim());
        let visible = 0;
        for (const option of optionsOf(picker)) {
            const match = normalize(option.dataset.search).includes(query);
            option.hidden = !match;
            if (match) visible += 1;
        }
        picker.querySelector('[data-empty]').hidden = visible > 0;
    }

    for (const picker of pickers) {
        picker.addEventListener('change', refresh);
        picker.querySelector('.picker-search').addEventListener('input', (event) => applyFilter(picker, event.target.value));
    }

    titleInput.addEventListener('input', () => {
        titleCounter.textContent = `${titleInput.value.length}/100`;
        validate();
    });
    descInput.addEventListener('input', validate);

    function updateTeamList() {
        const cards = [...teamList.children];
        teamCount.textContent = String(cards.length);
        teamEmpty.hidden = cards.length > 0;
    }

    teamFilter.addEventListener('input', () => {
        const query = normalize(teamFilter.value.trim());
        for (const card of teamList.children) card.hidden = !normalize(card.textContent).includes(query);
    });

    function applyRegisteredTeam(selected) {
        // Los estudiantes asignados ya no están libres y los docentes suben su carga
        const students = pickers.find((p) => p.dataset.role === 'estudiantes');
        for (const option of checkedOf(students)) option.remove();
        const docenteIds = new Set(selected.flatMap((picker) => checkedOf(picker).map((option) => option.dataset.id)));
        for (const picker of pickers) {
            for (const option of optionsOf(picker)) {
                if (docenteRoles.includes(picker.dataset.role) && docenteIds.has(option.dataset.id)) {
                    option.dataset.load = String(Number(option.dataset.load) + 1);
                }
            }
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        const docentePickers = pickers.filter((p) => docenteRoles.includes(p.dataset.role));
        submit.disabled = true;
        submit.firstChild.textContent = 'Guardando… ';
        showFeedback('success', '');

        try {
            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(form),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                showFeedback('error', data.message || 'No fue posible registrar el equipo.');
                return;
            }

            applyRegisteredTeam(docentePickers);
            teamList.insertAdjacentHTML('afterbegin', data.html);
            updateTeamList();
            form.reset();
            titleCounter.textContent = '0/100';
            for (const picker of pickers) {
                picker.querySelector('.picker-search').value = '';
                applyFilter(picker, '');
            }
            showFeedback('success', data.message);
        } catch {
            showFeedback('error', 'No hay conexión con el servidor. Intenta de nuevo.');
        } finally {
            submit.firstChild.textContent = 'Registrar equipo ';
            refresh();
        }
    });

    refresh();
})();
