{!! format_amount_by_symbol($expense['amount'], $expense['currency_symbol'], $expense['currency_decimal_places']) !!}
@if(($showOriginal ?? true) && $expense['original_currency_code'] !== 'UAH')
    <small class="text-muted">({!! format_amount_by_symbol($expense['original_amount'], $expense['original_currency_symbol'], $expense['original_currency_decimal_places'], false) !!})</small>
@endif
