@php($capital = \FireflyIII\Support\Reports\DashboardCapital::data())
<style nonce="{{ $JS_NONCE }}">
    #dashboard-capital-chart { position: relative; height: 360px; width: 100%; }
    #dashboard-capital-chart > canvas { position: absolute; inset: 0; display: block; width: 100% !important; height: 100% !important; }
    @media (max-width: 767px) { #dashboard-capital-chart { height: 280px; } }
</style>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <div class="btn-group btn-group-sm" role="group" aria-label="Період історії капіталу">
        @foreach(['30d' => '30 днів', '3m' => '3 місяці', '1y' => '1 рік'] as $period => $label)
            <a class="btn {{ $capital['period'] === $period ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ url('/').'?capital_period='.$period }}">{{ $label }}</a>
        @endforeach
    </div>
    <span class="text-muted small">{{ \Carbon\Carbon::parse($capital['start'])->format('d.m.Y') }}–{{ \Carbon\Carbon::parse($capital['end'])->format('d.m.Y') }} · € · НБУ на {{ \FireflyIII\Support\Reports\ReportingCurrency::today()->format('d.m.Y') }}</span>
</div>
@if($capital['error'])
    <p class="text-warning" role="status">{{ $capital['error'] }}</p>
@endif
