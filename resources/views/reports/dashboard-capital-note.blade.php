@php($capital = \FireflyIII\Support\Reports\DashboardCapital::data())
<style nonce="{{ $JS_NONCE }}">
    #dashboard-capital-chart { position: relative; height: 360px; width: 100%; }
    #dashboard-capital-chart > canvas { position: absolute; inset: 0; display: block; width: 100% !important; height: 100% !important; }
    @media (max-width: 767px) { #dashboard-capital-chart { height: 280px; } }
</style>
<p class="text-muted small mb-2">Останні 30 днів · EUR · НБУ на {{ \FireflyIII\Support\Reports\ReportingCurrency::today()->format('d.m.Y') }}</p>
@if($capital['error'])
    <p class="text-warning" role="status">{{ $capital['error'] }}</p>
@elseif($capital['coverage_start'] > $capital['start'])
    <p class="text-muted small" role="status">Повна історія всіх рахунків доступна з {{ \Carbon\Carbon::parse($capital['coverage_start'])->format('d.m.Y') }}. Раніші неповні дні залишено пропусками.</p>
@endif
@if(!empty($capital['manual_currencies']))
    <p class="text-muted small">Для {{ implode(', ', $capital['manual_currencies']) }} використано збережену ручну оцінку.</p>
@endif
