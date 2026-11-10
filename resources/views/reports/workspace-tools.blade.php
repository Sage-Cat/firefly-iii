<div id="workspace-tools-config" data-route="{{ request()->route()?->getName() }}" hidden></div>
<style nonce="{{ $JS_NONCE }}">
    .workspace-table-tools { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .75rem; }
    .workspace-table-tools input { flex: 1 1 12rem; min-width: 0; max-width: 24rem; }
    .workspace-table-tools label { margin: 0; font-size: .875rem; }
    .workspace-filtered { display: none !important; }
    .workspace-filter-empty[hidden] { display: none; }
    .workspace-sections summary { cursor: pointer; color: var(--bs-secondary-color); }
    .workspace-advanced summary { cursor: pointer; list-style-position: inside; }
    .workspace-advanced .nav-link { white-space: normal; }
    .workspace-advanced > ul { padding-inline-start: 1rem; }
    .app-content h2[id^="workspace-"], .app-content h3[id^="workspace-"] { scroll-margin-top: 5rem; }
    @media print { .workspace-table-tools, .workspace-sections { display: none !important; } }
</style>
@if(request()->routeIs('index'))
    <nav class="workspace-home-actions d-flex flex-wrap gap-2 my-2 d-print-none" aria-label="Швидкі дії">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('transactions.create', ['withdrawal']) }}">Додати витрату</a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('transactions.review') }}">Уточнити платежі</a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('reports.profit') }}">Переглянути прибуток</a>
    </nav>
@endif
@if(request()->routeIs('export.index'))
    <aside class="card card-body my-3">
        <h3 class="card-title">Перевірка перед експортом</h3>
        <p class="mb-2">Штатний експорт містить усі транзакції. Перегляньте операції та уточніть невідомі платежі перед збереженням файлу.</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-primary" href="{{ route('transactions.index', ['all']) }}">Усі операції</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('transactions.review') }}">Уточнення платежів</a>
        </div>
    </aside>
@endif
<script src="{{ asset('v1/js/ff/workspace-tools.js') }}?v={{ substr(hash_file('sha256', public_path('v1/js/ff/workspace-tools.js')), 0, 12) }}" nonce="{{ $JS_NONCE }}" integrity="sha384-{{ base64_encode(hash_file('sha384', public_path('v1/js/ff/workspace-tools.js'), true)) }}" crossorigin="anonymous" defer></script>
