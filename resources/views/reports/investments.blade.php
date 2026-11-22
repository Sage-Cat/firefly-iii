@extends('layout.v3.session')
{{-- A non-empty comment suppresses the layout's automatic breadcrumbs. --}}
@section('breadcrumbs')
    <!-- -->
@endsection

@php($money = static fn (?string $value): string => null === $value ? '—' : \FireflyIII\Support\Reports\ProfitSummary::money($value))
@php($symbol = static fn (string $code): string => \FireflyIII\Support\Reports\CurrencyDisplay::symbol($code))
@php($date = static fn (?string $day): string => null === $day ? '—' : \Carbon\Carbon::parse($day)->format('d.m.Y'))
@php($percent = static fn (?string $value): string => null === $value ? '—' : str_replace('.', ',', $value).'%')

@section('content')
    <style nonce="{{ $JS_NONCE }}">
        #investment-history-wrap { position: relative; height: 340px; }
        #investment-allocation-wrap, #investment-share-wrap, #investment-flow-wrap { position: relative; height: 280px; }
        #investment-positions td, #investment-positions th { white-space: nowrap; }
        #investment-positions td.investment-name { white-space: normal; min-width: 10rem; }
        .investment-swatch { display: inline-block; width: .75rem; height: .75rem; border-radius: 50%; flex-shrink: 0; }
        .investment-color-0 { background-color: #38bdf8; } .investment-color-1 { background-color: #a78bfa; }
        .investment-color-2 { background-color: #34d399; } .investment-color-3 { background-color: #fbbf24; }
        .investment-color-4 { background-color: #fb7185; } .investment-color-5 { background-color: #2dd4bf; }
        @media (max-width: 767px) { #investment-history-wrap { height: 280px; } #investment-allocation-wrap, #investment-share-wrap, #investment-flow-wrap { height: 240px; } }
    </style>
    @if($anonymous)
        <div class="card"><div class="card-body text-muted">Інвестиції приховано в анонімному режимі.</div></div>
    @elseif($investmentError)
        <div class="alert alert-danger" role="alert">{{ $investmentError }} Підсумок не показано, щоб не вводити в оману.</div>
    @elseif([] === $overview['rows'])
        <div class="card"><div class="card-body text-muted">
            @if([] === $overview['excluded'])
                Немає рахунків з інвестиційними даними. Рахунок потрапляє сюди, коли в його нотатках є блок <code>firefly-investment:v1</code>.
            @else
                Рахунки з інвестиційними даними не входять до чистих активів: {{ implode(', ', $overview['excluded']) }}.
            @endif
        </div></div>
    @else
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="row g-3 mb-3">
                    <div class="col-md-4"><div class="card h-100"><div class="card-body">
                        <div class="text-muted mb-2">Разом в інвестиціях</div>
                        <div class="fs-3 fw-semibold text-body-emphasis">{{ $money($overview['total_eur']) }} €</div>
                        <div class="text-body-secondary">{{ $money($overview['total_uah']) }} ₴</div>
                    </div></div></div>
                    <div class="col-md-4"><div class="card h-100"><div class="card-body">
                        <div class="text-muted mb-2">Частка капіталу</div>
                        <div class="fs-3 fw-semibold text-info">{{ $percent($overview['capital_share']) }}</div>
                        <div class="small text-muted">від чистих активів {{ $money($overview['capital_eur']) }} €</div>
                    </div></div></div>
                    <div class="col-md-4"><div class="card h-100"><div class="card-body">
                        <div class="text-muted mb-2">Позицій</div>
                        <div class="fs-3 fw-semibold text-body-emphasis">{{ count($overview['rows']) }}</div>
                        <div class="small text-muted">НБУ на {{ $valuationDay->format('d.m.Y') }}</div>
                    </div></div></div>
                </div>
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Вартість інвестицій · €</h3></div>
                    <div class="card-body">
                        <p class="small text-muted">З {{ $date($overview['start']) }} до сьогодні, за поточним курсом НБУ. Позиції складено одна над одною.@if($overview['incomplete_days'] > 0) Дні, коли якусь позицію ще не оцінено, пропущено.@endif</p>
                        <div id="investment-history-wrap"><canvas id="investment-history" role="img" aria-label="Історія вартості інвестицій у євро за позиціями"></canvas></div>
                    </div>
                </div>
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Позиції</h3></div>
                    <div class="table-responsive">
                        <table id="investment-positions" class="table table-striped mb-0">
                            <thead><tr><th>Рахунок</th><th class="text-end">Залишок</th><th class="text-end">₴</th><th class="text-end">€</th><th class="text-end">Частка</th><th>Оцінка на</th></tr></thead>
                            <tbody>
                            @foreach($overview['rows'] as $index => $row)
                                <tr>
                                    <td class="investment-name"><div class="d-flex gap-2 align-items-center"><span class="investment-swatch investment-color-{{ $index % 6 }}" aria-hidden="true"></span><a href="{{ route('accounts.show', [$row['id']]) }}">{{ $row['name'] }}</a></div><div class="small text-muted">{{ $row['kind_label'] }}@if([] !== $row['foreign_currencies']) · містить {{ implode(', ', $row['foreign_currencies']) }} за курсом НБУ@endif</div></td>
                                    <td class="text-end">{{ $money($row['native']) }} {{ $symbol($row['currency']) }}</td>
                                    <td class="text-end">{{ $money($row['uah']) }} ₴</td>
                                    <td class="text-end fw-semibold">{{ $money($row['eur']) }} €</td>
                                    <td class="text-end">{{ $percent($row['allocation']) }}</td>
                                    <td>{{ $date($row['valued_on']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot><tr><th>Разом</th><th></th><th class="text-end">{{ $money($overview['total_uah']) }} ₴</th><th class="text-end">{{ $money($overview['total_eur']) }} €</th><th class="text-end">{{ null === ($overview['rows'][0]['allocation'] ?? null) ? '—' : '100%' }}</th><th></th></tr></tfoot>
                        </table>
                    </div>
                    <div class="card-footer small text-muted">Залишок — останній відомий стан рахунку: банківські рухи або внесена оцінка з датою в колонці «Оцінка на». Ринкові ціни не завантажуються.@if([] !== $overview['excluded']) Не входять до чистих активів і не враховані: {{ implode(', ', $overview['excluded']) }}.@endif</div>
                </div>
                @foreach($overview['rows'] as $row)
                    @if('bond' === $row['kind'])
                        <div class="card mb-3">
                            <div class="card-header"><h3 class="card-title">{{ $row['name'] }}</h3></div>
                            <div class="card-body">
                                @if($row['bond'])
                                    <dl class="row mb-0">
                                        <dt class="col-sm-5">Погашення</dt><dd class="col-sm-7">{{ $date($row['bond']['maturity']) }} · через {{ $row['bond']['days_left'] }} дн.</dd>
                                        @if($row['bond']['yield'])<dt class="col-sm-5">Дохідність</dt><dd class="col-sm-7">{{ $percent($row['bond']['yield']) }} річних @if($row['bond']['yield_basis'])<span class="text-muted">· {{ $row['bond']['yield_basis'] }}</span>@endif</dd>@endif
                                        @if(bccomp($row['bond']['invested'], '0', 2) > 0)<dt class="col-sm-5">Вкладено</dt><dd class="col-sm-7">{{ $money($row['bond']['invested']) }} {{ $symbol($row['currency']) }}</dd>@endif
                                        @if(null !== $row['bond']['projected'])
                                            <dt class="col-sm-5">Орієнтовний дохід до погашення</dt>
                                            <dd class="col-sm-7">≈ {{ $money($row['bond']['projected']) }} {{ $symbol($row['currency']) }}
                                                <div class="small text-muted">@foreach($row['bond']['terms'] as $term){{ $money($term['amount']) }} × {{ $percent($row['bond']['yield']) }} × {{ $term['days'] }}/365 @if(!$loop->last)+ @endif @endforeach· простий розрахунок, не графік купонів</div></dd>
                                            <dt class="col-sm-5">Орієнтовно при погашенні</dt><dd class="col-sm-7">≈ {{ $money(bcadd($row['bond']['invested'], $row['bond']['projected'], 2)) }} {{ $symbol($row['currency']) }}</dd>
                                        @endif
                                    </dl>
                                @else
                                    <p class="text-muted mb-2">Дати погашення й дохідність не внесено.</p>
                                @endif
                                @if([] !== $row['income'] || bccomp($row['withdrawn'], '0', 2) > 0)
                                    <div class="border-top pt-2 mt-2 small">
                                        @foreach($row['income'] as $code => $value)<div>Купони з {{ $date($overview['start']) }}: <strong>{{ $money($value) }} {{ $symbol($code) }}</strong></div>@endforeach
                                        @if(bccomp($row['withdrawn'], '0', 2) > 0)<div>Переказано на власні рахунки з {{ $date($overview['start']) }}: <strong>{{ $money($row['withdrawn']) }} {{ $symbol($row['currency']) }}</strong></div>@endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
            <div class="col-xl-4">
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Розподіл · €</h3></div>
                    <div class="card-body"><div id="investment-allocation-wrap"><canvas id="investment-allocation" role="img" aria-label="Частка кожної позиції в інвестиціях"></canvas></div></div>
                </div>
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Частка капіталу: факт і ціль</h3></div>
                    <div class="card-body">
                        <div id="investment-share-wrap"><canvas id="investment-share" role="img" aria-label="Фактична й цільова частка кожної позиції в чистих активах"></canvas></div>
                        <p class="small text-muted mb-0 mt-2">Ціль — частка з планування капіталу; без цілі стовпчик не показано.</p>
                    </div>
                </div>
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Рухи коштів за місяцями · €</h3></div>
                    <div class="card-body">
                        @if([] === $overview['flows'])
                            <p class="text-muted mb-0">Купонів, внесків і повернень за цей період немає.</p>
                        @else
                            <div id="investment-flow-wrap"><canvas id="investment-flow" role="img" aria-label="Купони, внески й повернення коштів за місяцями"></canvas></div>
                            <p class="small text-muted mb-0 mt-2">Купони — дохід; внески й повернення — перекази між власними рахунками, не дохід і не витрата.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@section('scripts')
    @vite(['js/pages/generic.js'])
    @if(!$anonymous && !$investmentError && [] !== ($overview['rows'] ?? []))
        <script src="{{ asset('v1/js/lib/Chart.bundle.min.js') }}?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
        <script nonce="{{ $JS_NONCE }}">
            const investmentRows = @json($overview['rows']);
            const investmentHistory = @json($overview['history']);
            const investmentFlows = @json($overview['flows']);
            const investmentColors = ['#38bdf8', '#a78bfa', '#34d399', '#fbbf24', '#fb7185', '#2dd4bf'];
            const investmentMoney = value => window.fireflyCurrencyDisplay.format(value, 'EUR');
            const investmentAxis = {fontColor: '#9aa5ae'};
            const investmentGrid = {color: 'rgba(128,128,128,0.15)'};
            const investmentDay = day => new Date(day + 'T12:00:00').toLocaleDateString('uk-UA', {day: '2-digit', month: '2-digit'});
            new Chart(document.getElementById('investment-history').getContext('2d'), {
                type: 'line',
                data: {labels: investmentHistory.map(point => investmentDay(point.day)), datasets: investmentRows.map((row, index) => ({
                    label: row.name, data: investmentHistory.map(point => point.total === null ? null : Number(point.values[row.id])),
                    borderColor: investmentColors[index % 6], backgroundColor: investmentColors[index % 6] + '55', borderWidth: 2, pointRadius: 0, lineTension: 0, fill: 0 === index ? 'origin' : '-1'
                }))},
                options: {responsive: true, maintainAspectRatio: false, legend: {labels: investmentAxis},
                    scales: {xAxes: [{ticks: Object.assign({maxTicksLimit: 8}, investmentAxis), gridLines: {display: false}}],
                        yAxes: [{stacked: true, ticks: Object.assign({beginAtZero: true, callback: value => investmentMoney(value)}, investmentAxis), gridLines: investmentGrid}]},
                    tooltips: {mode: 'index', intersect: false, callbacks: {label: (item, data) => data.datasets[item.datasetIndex].label + ': ' + investmentMoney(item.yLabel)}}}
            });
            new Chart(document.getElementById('investment-allocation').getContext('2d'), {
                type: 'doughnut',
                data: {labels: investmentRows.map(row => row.name), datasets: [{data: investmentRows.map(row => Number(row.eur)),
                    backgroundColor: investmentRows.map((row, index) => investmentColors[index % 6]), borderWidth: 2}]},
                options: {responsive: true, maintainAspectRatio: false, legend: {position: 'bottom', labels: investmentAxis},
                    tooltips: {callbacks: {label: (item, data) => data.labels[item.index] + ': ' + investmentMoney(data.datasets[0].data[item.index]) + ' · ' + String(investmentRows[item.index].allocation).replace('.', ',') + '%'}}}
            });
            new Chart(document.getElementById('investment-share').getContext('2d'), {
                type: 'horizontalBar',
                data: {labels: investmentRows.map(row => row.name), datasets: [
                    {label: 'Факт', backgroundColor: 'rgba(56, 189, 248, 0.8)', data: investmentRows.map(row => row.capital_share === null ? null : Number(row.capital_share))},
                    {label: 'Ціль', backgroundColor: 'rgba(167, 139, 250, 0.6)', data: investmentRows.map(row => row.target_share === null ? null : Number(row.target_share))}
                ]},
                options: {responsive: true, maintainAspectRatio: false, legend: {labels: investmentAxis},
                    scales: {xAxes: [{ticks: Object.assign({beginAtZero: true, callback: value => value + '%'}, investmentAxis), gridLines: investmentGrid}], yAxes: [{ticks: investmentAxis, gridLines: {display: false}}]},
                    tooltips: {callbacks: {label: (item, data) => data.datasets[item.datasetIndex].label + ': ' + String(item.xLabel).replace('.', ',') + '%'}}}
            });
            if (investmentFlows.length > 0) {
                new Chart(document.getElementById('investment-flow').getContext('2d'), {
                    type: 'bar',
                    data: {labels: investmentFlows.map(flow => new Date(flow.month + '-01T12:00:00').toLocaleDateString('uk-UA', {month: 'short', year: 'numeric'})), datasets: [
                        {label: 'Купони', backgroundColor: 'rgba(36, 172, 116, 0.75)', data: investmentFlows.map(flow => Number(flow.income))},
                        {label: 'Внесено', backgroundColor: 'rgba(56, 189, 248, 0.75)', data: investmentFlows.map(flow => Number(flow.contributed))},
                        {label: 'Повернено', backgroundColor: 'rgba(251, 191, 36, 0.75)', data: investmentFlows.map(flow => Number(flow.withdrawn))}
                    ]},
                    options: {responsive: true, maintainAspectRatio: false, legend: {labels: investmentAxis},
                        scales: {xAxes: [{ticks: investmentAxis, gridLines: {display: false}}], yAxes: [{ticks: Object.assign({beginAtZero: true, callback: value => investmentMoney(value)}, investmentAxis), gridLines: investmentGrid}]},
                        tooltips: {mode: 'index', intersect: false, callbacks: {label: (item, data) => data.datasets[item.datasetIndex].label + ': ' + investmentMoney(item.yLabel)}}}
                });
            }
        </script>
    @endif
@endsection
