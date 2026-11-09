<style nonce="{{ $JS_NONCE }}">
    .subscription-list-column td.text-end { white-space: nowrap; }
    #subscription-cost-chart-wrap { position: relative; height: 320px; }
    #subscription-cost-chart { display: block; width: 100% !important; height: 100% !important; }
    #subscription-overview .subscription-name { overflow-wrap: anywhere; min-width: 0; }
    #subscription-overview .subscription-swatch { display: inline-block; width: .75rem; height: .75rem; border-radius: 50%; flex-shrink: 0; margin-top: .35rem; }
    #subscription-overview .subscription-color-0 { background-color: #38bdf8; }
    #subscription-overview .subscription-color-1 { background-color: #a78bfa; }
    #subscription-overview .subscription-color-2 { background-color: #34d399; }
    #subscription-overview .subscription-color-3 { background-color: #fbbf24; }
    #subscription-overview .subscription-color-4 { background-color: #fb7185; }
    #subscription-overview .subscription-color-5 { background-color: #2dd4bf; }
    #subscription-overview .subscription-color-6 { background-color: #f472b6; }
    #subscription-overview .subscription-color-7 { background-color: #60a5fa; }
    @media (max-width: 767px) { #subscription-cost-chart-wrap { height: 280px; } }
</style>
<aside id="subscription-overview" class="card mb-3" aria-labelledby="subscription-overview-title">
    <div class="card-header"><h2 id="subscription-overview-title" class="card-title">Витрати на підписки</h2></div>
    <div class="card-body">
        <p class="text-muted small mb-3">Середня місячна вартість усіх активних підписок.</p>
        @if($subscriptionOverview['error'])
            <div class="alert alert-warning mb-0" role="alert">{{ $subscriptionOverview['error'] }} Підсумок не показано.</div>
        @else
            <div class="mb-3">
                <div class="small text-muted">Разом на місяць</div>
                <div class="fs-3 fw-semibold text-body-emphasis">{{ \FireflyIII\Support\Reports\ProfitSummary::money($subscriptionOverview['uah']) }} ₴</div>
                <div class="fs-5 text-body-secondary">{{ \FireflyIII\Support\Reports\ProfitSummary::money($subscriptionOverview['eur']) }} €</div>
            </div>
            @if(bccomp($subscriptionOverview['uah'], '0', 2) > 0)
                <div id="subscription-cost-chart-wrap" class="mb-3"><canvas id="subscription-cost-chart" role="img" aria-label="Частка місячних витрат за кожною активною підпискою"></canvas></div>
            @else
                <div class="rounded bg-body-tertiary text-center p-4 mb-3 text-muted">{{ $subscriptionOverview['count'] === 0 ? 'Немає активних підписок.' : 'Немає витрат для діаграми.' }}</div>
            @endif
            <ul class="list-unstyled mb-3">
                @foreach($subscriptionOverview['rows'] as $subscription)
                    <li class="d-flex gap-2 align-items-start py-2 border-bottom">
                        <span class="subscription-swatch subscription-color-{{ $subscription['color'] }}" aria-hidden="true"></span>
                        <a class="subscription-name flex-grow-1 text-body" href="{{ route('subscriptions.show', [$subscription['id']]) }}">{{ $subscription['name'] }}</a>
                        <span class="text-nowrap fw-semibold">{{ \FireflyIII\Support\Reports\ProfitSummary::money($subscription['uah']) }} ₴</span>
                    </li>
                @endforeach
            </ul>
            <p class="small text-muted mb-0">{{ $subscriptionOverview['count'] }} активних підписок · НБУ на {{ $subscriptionOverview['day'] }}. Інші періоди приведено до місяця; це планова вартість, а не сума неоплачених рахунків.</p>
        @endif
    </div>
</aside>
