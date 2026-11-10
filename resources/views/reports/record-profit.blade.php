@if($summary && !($anonymous ?? false))
    @php
        $recordDays = max(1, (int) $start->diffInDays($end, true) + 1);
        $recordDailyExpenses = bcdiv($summary['totals']['expenses'], (string) $recordDays, 2);
        $recordShare = bccomp($summary['totals']['income'], '0', 2) > 0 ? bcdiv(bcmul($summary['totals']['net'], '100', 4), $summary['totals']['income'], 1) : null;
    @endphp
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-3 justify-content-between">
            <div><div class="small text-muted">Середні витрати за день</div><strong>{{ \FireflyIII\Support\Reports\ProfitSummary::money($recordDailyExpenses) }} {{ \FireflyIII\Support\Reports\CurrencyDisplay::symbol($currency) }}</strong></div>
            <div><div class="small text-muted">Частка доходів після витрат</div><strong>{{ $recordShare === null ? 'Немає доходів для порівняння' : str_replace('.', ',', $recordShare).'%' }}</strong></div>
        </div>
    </div>
@endif
