<div class="card mb-3 record-filter-tools" id="record-filter-{{ $recordFilterKey }}">
    <div class="card-body">
        <label class="form-label" for="record-filter-input-{{ $recordFilterKey }}">Пошук на цій сторінці</label>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <input id="record-filter-input-{{ $recordFilterKey }}" class="form-control" type="search" autocomplete="off" placeholder="Назва, опис або сума" style="flex: 1 1 15rem; min-width: 0" aria-describedby="record-filter-count-{{ $recordFilterKey }}">
            <button type="button" class="btn btn-outline-secondary record-filter-clear">Очистити</button>
            <span id="record-filter-count-{{ $recordFilterKey }}" class="small text-muted" role="status" aria-live="polite"></span>
        </div>
        <p class="small text-muted mb-0 mt-2 record-filter-empty" hidden>На цій сторінці збігів немає. Очисти пошук або перейди на іншу сторінку.</p>
    </div>
</div>
<style nonce="{{ $JS_NONCE }}">{{ $recordFilterRowSelector }}[hidden] { display: none !important; }</style>
<script nonce="{{ $JS_NONCE }}">
(() => {
    const start = () => {
        const tools = document.getElementById(@json('record-filter-'.$recordFilterKey));
        const root = document.querySelector(@json($recordFilterRootSelector));
        if (!tools || !root) return;
        const input = tools.querySelector('input'), clear = tools.querySelector('button');
        const count = tools.querySelector('[role="status"]'), empty = tools.querySelector('.record-filter-empty');
        const normalize = value => value.normalize('NFKC').toLocaleLowerCase('uk-UA').trim();
        const update = () => {
            const rows = Array.from(root.querySelectorAll(@json($recordFilterRowSelector)));
            const query = normalize(input.value);
            let visible = 0;
            for (const row of rows) {
                row.hidden = query !== '' && !normalize(row.textContent).includes(query);
                if (!row.hidden) visible++;
            }
            const text = `${visible} із ${rows.length} на цій сторінці`;
            if (count.textContent !== text) count.textContent = text;
            empty.hidden = visible !== 0 || rows.length === 0;
            input.disabled = rows.length === 0;
            clear.disabled = input.value === '';
        };
        input.addEventListener('input', update);
        clear.addEventListener('click', () => { input.value = ''; update(); input.focus(); });
        let scheduled = false;
        const observer = new MutationObserver(records => {
            if (records.every(record => tools.contains(record.target)) || scheduled) return;
            scheduled = true;
            requestAnimationFrame(() => { scheduled = false; update(); });
        });
        observer.observe(root, {childList: true, subtree: true, characterData: true});
        update();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})();
</script>
