@php
    $chartRange = \FireflyIII\Support\Reports\DashboardChartPeriods::forChart($chart);
    $chartTitle = ['capital' => 'Чисті активи', 'budget' => 'Бюджети та витрати', 'category' => 'Категорії. Топ 5', 'expense' => 'Рахунки витрат. Топ 3', 'revenue' => 'Рахунки доходів. Топ 3'][$chart];
@endphp
<div id="dashboard-{{ $chart }}-period" class="dashboard-chart-period d-flex flex-wrap align-items-center gap-2 mb-2">
    <div class="btn-group btn-group-sm" role="group" aria-label="Період графіка «{{ $chartTitle }}»">
        @foreach(['30d' => '30 днів', '3m' => '3 міс', '1y' => '1 рік'] as $period => $label)
            <a class="btn {{ $chartRange['period'] === $period ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ \FireflyIII\Support\Reports\DashboardChartPeriods::link($chart, $period) }}" @if($chartRange['period'] === $period) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </div>
    <span class="text-muted small">
        <time datetime="{{ $chartRange['start'] }}">{{ \Carbon\Carbon::parse($chartRange['start'])->format('d.m.Y') }}</time>–<time datetime="{{ $chartRange['end'] }}">{{ \Carbon\Carbon::parse($chartRange['end'])->format('d.m.Y') }}</time>
        · {{ $currency }}
        @if($valuation ?? false) · НБУ на {{ \FireflyIII\Support\Reports\ReportingCurrency::today()->format('d.m.Y') }}@endif
    </span>
</div>
