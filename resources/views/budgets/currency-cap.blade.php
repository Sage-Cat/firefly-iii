@if($budgetLimit['projection_currency_code'] !== 'UAH')
    <small class="d-block text-muted">{!! format_amount_by_symbol($budgetLimit['display_amount'], 'грн', 2) !!}</small>
@endif
