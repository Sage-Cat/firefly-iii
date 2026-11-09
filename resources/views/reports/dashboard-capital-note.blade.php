@php($capital = \FireflyIII\Support\Reports\DashboardCapital::data())
<style nonce="{{ $JS_NONCE }}">
    #dashboard-capital-chart { position: relative; height: 360px; width: 100%; }
    #dashboard-capital-chart > canvas { position: absolute; inset: 0; display: block; width: 100% !important; height: 100% !important; }
    .dashboard-chart-period { scroll-margin-top: 5rem; }
    @media (max-width: 767px) { #dashboard-capital-chart { height: 280px; } }
</style>
@include('reports.dashboard-chart-period', ['chart' => 'capital', 'currency' => '€', 'valuation' => true])
@if($capital['error'])
    <p class="text-warning" role="status">{{ $capital['error'] }}</p>
@endif
