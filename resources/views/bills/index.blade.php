@extends('layout.v3.session')
@section('content')
    @php($subscriptionOverview = \FireflyIII\Support\Reports\SubscriptionOverview::forUser(auth()->user()))
    <div class="row g-3 align-items-start">
        <div class="col-xl-8 subscription-list-column">
            @if(0 === $total)
                @php($shownDemo = true)
                <x-empty-page :route="route('subscriptions.create')" type="bills" object-type="default" />
            @endif
            @if($total > 0)
                <x-lists.subscriptions :bills="$bills" :sums="$sums" :totals="$totals" :today="$today" />
            @endif
        </div>
        <div class="col-xl-4">@include('bills.subscription-overview')</div>
    </div>
@endsection
@section('scripts')
    @vite(['js/pages/generic.js'])
    <script src="{{ asset('v1/js/lib/jquery-ui.min.js') }}?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
    <script src="{{ asset('v1/js/ff/bills/index.js') }}?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
    @if(!$subscriptionOverview['error'] && bccomp($subscriptionOverview['uah'], '0', 2) > 0)
        <script src="{{ asset('v1/js/lib/Chart.bundle.min.js') }}?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
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
