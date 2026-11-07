@foreach($budget['budgeted'] as $budgetLimit)
    <span data-currency="{{ $budgetLimit['currency_id'] }}" data-limit="{{ $budgetLimit['id'] }}" data-value="{{ $budgetLimit['display_left'] }}" class="{{ $budgetLimit['projection_currency_code'] === 'UAH' ? 'left_span amount_left' : 'budget-uah-readonly' }}">
        {!! format_amount_by_symbol($budgetLimit['display_left'], '₴', 2, true) !!}
        @if(!$budgetLimit['in_past'])
            ({!! format_amount_by_symbol($budgetLimit['active_days_left'] > 0 && bccomp($budgetLimit['display_left'], '0', 2) > 0 ? bcdiv($budgetLimit['display_left'], (string) $budgetLimit['active_days_left'], 2) : '0.00', '₴', 2) !!})
        @endif
    </span>
    @if($budgetLimit['projection_currency_code'] !== 'UAH')
        <small class="d-block"><a href="{{ request()->fullUrl() }}">Оновити після зміни ліміту</a></small>
    @endif
    <br>
@endforeach
