@if(!$anonymous)
    @if(null !== $piggy['target_date'])
        <div class="small text-muted mt-1">Мета до {{ carbonize($piggy['target_date'])->format('d.m.Y') }}</div>
    @endif
    <div class="d-lg-none mt-2">
        @include('piggy-banks.control-progress')
        @if(null !== $piggy['left_to_save'] && bccomp((string) $piggy['left_to_save'], '0', 16) > 0)
            <div class="small mt-1">Залишилося {!! format_amount_by_symbol($piggy['left_to_save'], $piggy['currency_symbol'], $piggy['currency_decimal_places']) !!}</div>
        @elseif(null !== $piggy['target_amount'] && bccomp((string) $piggy['target_amount'], '0', 16) > 0)
            <div class="small mt-1">Цілі досягнуто</div>
        @else
            <div class="small text-muted">Ціль без визначеної суми</div>
        @endif
    </div>
@endif
