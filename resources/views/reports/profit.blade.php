@extends('layout.v3.session')
{{-- A non-empty comment suppresses the layout's automatic breadcrumbs. --}}
@section('breadcrumbs')
    <!-- -->
@endsection

@section('content')
    <style nonce="{{ $JS_NONCE }}">
        #profit-chart-wrap { position: relative; height: 360px; }
        #profit-breakdown th:nth-child(n+2), #profit-breakdown td:nth-child(n+2),
        #profit-weekly-expenses th, #profit-weekly-expenses td { white-space: nowrap; }
        .profit-income { --bs-table-bg: rgba(var(--bs-success-rgb), 0.06); }
        .profit-expenses { --bs-table-bg: rgba(var(--bs-danger-rgb), 0.06); }
        .profit-net { --bs-table-bg: rgba(var(--bs-info-rgb), 0.06); }
    </style>
    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card mb-3">
                <div class="card-body">
                    <p class="mb-3">Доходи, витрати та різниця за всіма особистими й ФОП-рахунками разом.</p>
                    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
                        <div class="btn-group" role="group" aria-label="Валюта звіту">
                            @foreach(['EUR' => '€ EUR', 'UAH' => '₴ UAH'] as $code => $label)
                                <a class="btn btn-sm {{ $currency === $code ? 'btn-primary' : 'btn-outline-secondary' }}"
                                   href="{{ route('reports.profit', ['currency' => $code, 'months' => $months, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')]) }}">{{ $label }}</a>
                            @endforeach
                        </div>
                        <div class="btn-group" role="group" aria-label="Період звіту">
                            @foreach([1 => '30 днів', 3 => '3 місяці', 12 => '1 рік'] as $number => $label)
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('reports.profit', ['currency' => $currency, 'months' => $number]) }}">{{ $label }}</a>
                            @endforeach
                        </div>
                    </div>
                    <form method="get" action="{{ route('reports.profit') }}" class="row g-2 align-items-end">
                        <input type="hidden" name="currency" value="{{ $currency }}">
                        <input type="hidden" name="months" value="{{ $months }}">
                        <div class="col-sm-4 col-lg-3">
                            <label for="profit-start" class="form-label">Від</label>
                            <input type="date" class="form-control" id="profit-start" name="start" value="{{ $start->format('Y-m-d') }}" required>
                        </div>
                        <div class="col-sm-4 col-lg-3">
                            <label for="profit-end" class="form-label">До</label>
                            <input type="date" class="form-control" id="profit-end" name="end" value="{{ $end->format('Y-m-d') }}" required>
                        </div>
                        <div class="col-sm-4 col-lg-2">
                            <button type="submit" class="btn btn-primary">Показати</button>
                        </div>
                    </form>
                    <p class="text-muted small mb-0 mt-2">30 днів включають сьогодні та попередні 29 днів. Періоди 3 місяці й 1 рік включають поточний місяць до сьогодні. Валюта цього звіту не змінює основну валюту системи.</p>
                </div>
            </div>
            @if($firstDay && ($start->format('Y-m-d') < $firstDay || $weeklyStart->format('Y-m-d') < $firstDay))
                <div class="alert alert-warning" role="status">Внесена історія починається з {{ \Carbon\Carbon::parse($firstDay)->format('d.m.Y') }}. Результат за ранішу частину періоду неповний.</div>
            @endif
            @if($reportError)
                <div class="alert alert-danger" role="alert">{{ $reportError }} Підсумок не показано, щоб не пропустити операції.</div>
            @else
                <div class="row g-3 mb-3">
                    @foreach(['income' => ['Доходи', 'text-success'], 'expenses' => ['Витрати', 'text-danger'], 'net' => ['Залишок після витрат', bccomp($summary['totals']['net'], '0', 2) < 0 ? 'text-danger' : 'text-info']] as $key => [$label, $color])
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="text-muted mb-2">{{ $label }}</div>
                                    <div class="fs-3 fw-semibold {{ $color }}">{{ \FireflyIII\Support\Reports\ProfitSummary::money($summary['totals'][$key]) }} {{ $currency === 'EUR' ? '€' : '₴' }}</div>
                                    @if($key === 'net')<div class="small text-muted mt-1">Доходи мінус витрати</div>@endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Доходи, витрати та результат · {{ $currency }}</h3></div>
                    <div class="card-body">
                        <div id="profit-chart-wrap"><canvas id="profit-chart" role="img" aria-label="Зведені доходи, витрати й залишок після витрат"></canvas></div>
                    </div>
                </div>
                <p class="text-muted small">Курси НБУ станом на {{ $valuationDay->format('d.m.Y') }}. Перерахунок через гривню. Перекази між власними рахунками та початкові залишки виключені. Лінія «Результат після витрат» показує накопичені доходи мінус витрати від початку вибраного періоду.</p>
                @if(count($manualCurrencies) > 0)<p class="text-muted small">Для {{ implode(', ', $manualCurrencies) }} використано збережену ручну оцінку.</p>@endif
            @endif
        </div>
        @if(!$reportError)
            <div class="col-xl-5">
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Підсумки за місяцями · {{ $currency }}</h3></div>
                    <div class="table-responsive">
                        <table id="profit-breakdown" class="table table-striped mb-0 caption-top">
                            <caption class="px-3">Останні 3 календарні місяці: {{ $monthlyStart->format('d.m.Y') }}–{{ $monthlyEnd->format('d.m.Y') }}, незалежно від періоду графіка. Поточний місяць враховано до сьогодні.</caption>
                            <thead><tr><th>Місяць</th><th class="text-end text-success-emphasis profit-income">Доходи</th><th class="text-end text-danger-emphasis profit-expenses">Витрати</th><th class="text-end text-info-emphasis profit-net">Чистий прибуток</th></tr></thead>
                            <tbody>
                            @foreach($monthlySummary['points'] as $point)
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($point['period'].'-01')->translatedFormat('F Y') }}<div class="small text-muted">{{ \Carbon\Carbon::parse($point['start'])->format('d.m.Y') }}–{{ \Carbon\Carbon::parse($point['end'])->format('d.m.Y') }}</div></td>
                                    @foreach(['income' => 'text-success-emphasis profit-income', 'expenses' => 'text-danger-emphasis profit-expenses', 'net' => bccomp($point['net'], '0', 2) < 0 ? 'text-danger-emphasis profit-expenses' : 'text-info-emphasis profit-net'] as $key => $color)<td class="text-end {{ $color }}">{{ \FireflyIII\Support\Reports\ProfitSummary::money($point[$key]) }} {{ $currency === 'EUR' ? '€' : '₴' }}</td>@endforeach
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot><tr><th>Разом</th>@foreach(['income' => 'text-success-emphasis profit-income', 'expenses' => 'text-danger-emphasis profit-expenses', 'net' => bccomp($monthlySummary['totals']['net'], '0', 2) < 0 ? 'text-danger-emphasis profit-expenses' : 'text-info-emphasis profit-net'] as $key => $color)<th class="text-end {{ $color }}">{{ \FireflyIII\Support\Reports\ProfitSummary::money($monthlySummary['totals'][$key]) }} {{ $currency === 'EUR' ? '€' : '₴' }}</th>@endforeach</tr></tfoot>
                        </table>
                    </div>
                </div>
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Тижневі витрати за останні 3 місяці · {{ $currency }}</h3></div>
                    <div class="table-responsive">
                        <table id="profit-weekly-expenses" class="table table-striped mb-0 caption-top">
                            <caption class="px-3">Останні 3 місяці: {{ $weeklyStart->format('d.m.Y') }}–{{ $weeklyEnd->format('d.m.Y') }}, незалежно від періоду графіка. Тижні тривають з понеділка до неділі; крайні тижні обмежено цими датами.</caption>
                            <thead><tr><th>Тиждень ISO</th><th>Дати</th><th class="text-end text-danger-emphasis profit-expenses">Витрати</th></tr></thead>
                            <tbody>
                            @foreach($weeklySummary['points'] as $point)
                                <tr>
                                    <td>{{ $point['period'] }}</td>
                                    <td>{{ \Carbon\Carbon::parse($point['start'])->format('d.m.Y') }}–{{ \Carbon\Carbon::parse($point['end'])->format('d.m.Y') }}</td>
                                    <td class="text-end text-danger-emphasis profit-expenses">{{ \FireflyIII\Support\Reports\ProfitSummary::money($point['expenses']) }} {{ $currency === 'EUR' ? '€' : '₴' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot><tr><th colspan="2">Разом</th><th class="text-end text-danger-emphasis profit-expenses">{{ \FireflyIII\Support\Reports\ProfitSummary::money($weeklySummary['totals']['expenses']) }} {{ $currency === 'EUR' ? '€' : '₴' }}</th></tr></tfoot>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@section('scripts')
    @vite(['js/pages/generic.js'])
    @if($summary)
        <script src="{{ asset('v1/js/lib/Chart.bundle.min.js') }}?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
        <script nonce="{{ $JS_NONCE }}">
            const profitPoints = @json($summary['points']);
            const profitCurrency = @json($currency);
            const profitDaily = @json($summary['daily']);
            const profitMoney = new Intl.NumberFormat('uk-UA', {style: 'currency', currency: profitCurrency});
            const profitLabels = profitPoints.map(function (point) {
                const date = new Date(point.period + (profitDaily ? 'T12:00:00' : '-01T12:00:00'));
                return date.toLocaleDateString('uk-UA', profitDaily ? {day: '2-digit', month: '2-digit'} : {month: 'short', year: 'numeric'});
            });
            new Chart(document.getElementById('profit-chart').getContext('2d'), {
                type: 'bar',
                data: {labels: profitLabels, datasets: [
                    {label: 'Результат після витрат', type: 'line', borderColor: '#38bdf8', backgroundColor: '#38bdf8', fill: false, borderWidth: 3, lineTension: 0, data: profitPoints.map(point => Number(point.cumulative_net))},
                    {label: 'Доходи', backgroundColor: 'rgba(36, 172, 116, 0.75)', data: profitPoints.map(point => Number(point.income)), order: 2},
                    {label: 'Витрати', backgroundColor: 'rgba(226, 93, 102, 0.75)', data: profitPoints.map(point => Number(point.expenses)), order: 2}
                ]},
                options: {
                    responsive: true, maintainAspectRatio: false,
                    legend: {labels: {fontColor: '#9aa5ae'}},
                    scales: {xAxes: [{ticks: {fontColor: '#9aa5ae'}, gridLines: {display: false}}], yAxes: [{ticks: {beginAtZero: true, fontColor: '#9aa5ae', callback: value => profitMoney.format(value)}, gridLines: {color: 'rgba(128,128,128,0.15)'}}]},
                    tooltips: {mode: 'index', intersect: false, callbacks: {label: function (item, data) {return data.datasets[item.datasetIndex].label + ': ' + profitMoney.format(item.yLabel);}}}
                }
            });
        </script>
    @endif
@endsection
