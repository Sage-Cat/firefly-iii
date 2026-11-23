@extends('layout.v3.session')
@section('content')
    @php($subscriptionOverview = \FireflyIII\Support\Reports\SubscriptionOverview::forUser(auth()->user()))
    @php($subscriptionShowEnded = request()->boolean('ended'))
    @php($subscriptionList = \FireflyIII\Support\Reports\SubscriptionOverview::current($bills, \FireflyIII\Support\Reports\ReportingCurrency::today()->format('Y-m-d')))
    <div class="row g-3 align-items-start">
        <div class="col-xl-8 subscription-list-column">
            @include('bills.control-queue', ['bills' => $subscriptionList['bills']])
            @if(0 === $total)
                @php($shownDemo = true)
                <x-empty-page :route="route('subscriptions.create')" type="bills" object-type="default" />
            @endif
            @if($total > 0)
                @if($subscriptionShowEnded || $subscriptionList['shown'] > 0)
                    <x-lists.subscriptions :bills="$subscriptionShowEnded ? $bills : $subscriptionList['bills']" :sums="$sums" :totals="$totals" :today="$today" />
                @else
                    <div class="card mb-2"><div class="card-body text-muted">Чинних підписок немає.</div></div>
                @endif
                @if($subscriptionList['ended'] > 0)
                    <p class="small mb-3">
                        <a href="{{ route('subscriptions.index', $subscriptionShowEnded ? [] : ['ended' => 1]) }}">{{ $subscriptionShowEnded ? 'Сховати' : 'Показати' }} завершені підписки ({{ $subscriptionList['ended'] }})</a>
                    </p>
                @endif
            @endif
        </div>
        <div class="col-xl-4">@include('bills.subscription-overview')</div>
    </div>
@endsection
@section('scripts')
    @vite(['js/pages/generic.js'])
    <script src="{{ asset('v1/js/lib/jquery-ui.min.js') }}?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
    <script src="{{ asset('v1/js/ff/bills/index.js') }}?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
    @if(!$anonymous && !$subscriptionOverview['error'] && bccomp($subscriptionOverview['uah'], '0', 2) > 0)
        <script src="{{ asset('v1/js/lib/Chart.bundle.min.js') }}?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
        <script src="{{ asset('v1/js/ff/charts.defaults.js') }}?v={{ substr(hash_file('sha256', public_path('v1/js/ff/charts.defaults.js')), 0, 12) }}" nonce="{{ $JS_NONCE }}" integrity="sha384-{{ base64_encode(hash_file('sha384', public_path('v1/js/ff/charts.defaults.js'), true)) }}" crossorigin="anonymous"></script>
        <script nonce="{{ $JS_NONCE }}">
            const subscriptionCosts = @json($subscriptionOverview['rows']);
            const subscriptionColors = ['#38bdf8', '#a78bfa', '#34d399', '#fbbf24', '#fb7185', '#2dd4bf', '#f472b6', '#60a5fa'];
            const subscriptionMoney = {format: value => window.fireflyCurrencyDisplay.format(value, 'UAH')};
            new Chart(document.getElementById('subscription-cost-chart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: subscriptionCosts.map(row => row.name),
                    datasets: [{data: subscriptionCosts.map(row => Number(row.uah)), backgroundColor: subscriptionCosts.map(row => subscriptionColors[row.color]), borderWidth: 2, borderColor: getComputedStyle(document.getElementById('subscription-overview')).backgroundColor}]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutoutPercentage: 68,
                    legend: {display: false},
                    tooltips: {callbacks: {label: function (item, data) {
                        const value = data.datasets[item.datasetIndex].data[item.index];
                        return data.labels[item.index] + ': ' + subscriptionMoney.format(value);
                    }}}
                }
            });
        </script>
    @endif
@endsection
