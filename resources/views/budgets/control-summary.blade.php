@if(!$anonymous && isset($budgeted, $spent))
    @php
        $controlSpent = bcsub('0', (string) $spent, 2);
        $controlLeft = bcadd((string) $budgeted, (string) $spent, 2);
    @endphp
    <section class="row g-2 mb-3" aria-label="Підсумок бюджетів за вибраний період">
        @foreach([['План за період', (string) $budgeted], ['Витрачено за період', $controlSpent], ['Залишок за період', $controlLeft]] as [$controlLabel, $controlAmount])
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body py-3">
                    <div class="small text-muted mb-1">{{ $controlLabel }}</div>
                    <div class="fs-4 fw-semibold">{{ \FireflyIII\Support\Reports\ProfitSummary::money($controlAmount) }} ₴</div>
                </div></div>
            </div>
        @endforeach
    </section>
@endif
