(() => {
    'use strict';
    const init = () => {
        const config = document.getElementById('workspace-tools-config');
        const content = document.querySelector('.app-content > .container-fluid');
        if (!config || !content) return;
        const route = config.dataset.route || '';
        const filterRoutes = /^(budgets|subscriptions|piggy-banks|categories|tags|object-groups|rules|rule-groups|recurring|webhooks|attachments|search)\.index$/;
        const normalize = value => String(value).normalize('NFKC').toLocaleLowerCase('uk-UA').replace(/\s+/g, ' ').trim();
        let serial = 0;
        const install = table => {
            if (table.dataset.workspaceFilter || !table.tBodies.length || table.closest('[data-no-workspace-filter]')) return;
            if (Array.from(table.tBodies).reduce((n, body) => n + body.rows.length, 0) < 2) return;
            table.dataset.workspaceFilter = 'true';
            const tools = document.createElement('div');
            tools.className = 'workspace-table-tools d-print-none';
            const id = 'workspace-table-search-' + ++serial;
            const label = document.createElement('label');
            label.htmlFor = id;
            label.textContent = 'Пошук на цій сторінці';
            const input = document.createElement('input');
            input.id = id;
            input.type = 'search';
            input.className = 'form-control form-control-sm';
            input.autocomplete = 'off';
            input.placeholder = 'Назва або текст у таблиці';
            const reset = document.createElement('button');
            reset.type = 'button';
            reset.className = 'btn btn-sm btn-outline-secondary';
            reset.textContent = 'Очистити';
            const status = document.createElement('span');
            status.className = 'small text-muted';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            const empty = document.createElement('p');
            empty.className = 'workspace-filter-empty text-muted px-3';
            empty.textContent = 'На цій сторінці збігів немає. Очистіть пошук або скористайтеся загальним пошуком у меню.';
            empty.hidden = true;
            tools.append(label, input, reset, status);
            const host = table.closest('form') || table.closest('.table-responsive') || table;
            host.before(tools);
            host.after(empty);
            const update = () => {
                const query = normalize(input.value);
                let total = 0, visible = 0;
                for (const row of Array.from(table.tBodies).flatMap(body => Array.from(body.rows))) {
                    row.classList.remove('workspace-filtered');
                    if (row.hidden || getComputedStyle(row).display === 'none') continue;
                    total++;
                    const match = !query || normalize(row.textContent).includes(query);
                    if (!match) row.classList.add('workspace-filtered'); else visible++;
                }
                status.textContent = `Показано ${visible} із ${total} · на цій сторінці`;
                empty.hidden = !total || visible > 0;
                reset.disabled = !input.value;
            };
            input.addEventListener('input', update);
            reset.addEventListener('click', () => { input.value = ''; update(); input.focus(); });
            input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); } if (event.key === 'Escape') { input.value = ''; update(); } });
            new MutationObserver(update).observe(table, {childList: true, subtree: true, characterData: true});
            update();
        };
        const enhance = () => {
            if (filterRoutes.test(route)) content.querySelectorAll('table').forEach(install);
        };
        enhance();
        new MutationObserver(enhance).observe(content, {childList: true, subtree: true});
        const homeActions = content.querySelector('.workspace-home-actions');
        if (homeActions) content.prepend(homeActions);
        const headings = Array.from(content.querySelectorAll('h2, h3.card-title')).filter(node => node.textContent.trim());
        if (headings.length > 1) {
            const details = document.createElement('details');
            details.className = 'workspace-sections d-print-none mb-3';
            const summary = document.createElement('summary');
            summary.textContent = 'Розділи цієї сторінки';
            const nav = document.createElement('nav');
            nav.className = 'd-flex flex-wrap gap-2 mt-2';
            nav.setAttribute('aria-label', 'Перейти до розділу сторінки');
            for (const [index, heading] of headings.entries()) {
                if (!heading.id) heading.id = 'workspace-section-' + index;
                const link = document.createElement('a');
                link.href = '#' + heading.id;
                link.className = 'btn btn-sm btn-outline-secondary';
                link.textContent = heading.textContent.trim();
                nav.append(link);
            }
            details.append(summary, nav);
            content.prepend(details);
        }
    };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init, {once: true}) : init();
})();
