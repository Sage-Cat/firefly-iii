@php
    $nbuTodayError = null;
    try { $nbuTodayQuotes = \FireflyIII\Support\Reports\ReportingCurrency::quotes(); }
    catch (\Throwable $exception) { $nbuTodayQuotes = []; $nbuTodayError = 'Не вдалося отримати курси НБУ на сьогодні.'; }
@endphp
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title">НБУ · {{ \FireflyIII\Support\Reports\ReportingCurrency::today()->format('d.m.Y') }}</h3></div>
    <div class="card-body">
        @if($nbuTodayError)<p class="text-warning mb-0">{{ $nbuTodayError }}</p>
        @else
            <div class="d-flex flex-wrap gap-4">
            @foreach(['EUR', 'USD', 'CHF'] as $code)
                @php($quoteText = str_contains($nbuTodayQuotes[$code], '.') ? rtrim(rtrim($nbuTodayQuotes[$code], '0'), '.') : $nbuTodayQuotes[$code])
                <span><strong>1 {{ $code }}</strong> = {{ str_replace('.', ',', $quoteText) }} ₴</span>
            @endforeach
            </div>
            <p class="text-muted small mb-0 mt-2">Офіційний курс до гривні. Оновлюється автоматично.</p>
        @endif
    </div>
</div>
