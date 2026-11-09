@foreach($budget['budgeted'] as $budgetLimit)
    {!! format_amount_by_symbol($budgetLimit['display_spent'], 'грн', 2) !!}
    ({!! format_amount_by_symbol($budgetLimit['active_days_passed'] > 0 ? bcdiv($budgetLimit['display_spent'], (string) $budgetLimit['active_days_passed'], 2) : $budgetLimit['display_spent'], 'грн', 2) !!})<br>
@endforeach
@if(!$budget['budgeted'] || 0 !== bccomp($budget['display_outside'], '0', 2))
    {!! format_amount_by_symbol($budget['display_outside'], 'грн', 2) !!}
    ({!! format_amount_by_symbol($activeDaysPassed > 0 ? bcdiv($budget['display_outside'], (string) $activeDaysPassed, 2) : $budget['display_outside'], 'грн', 2) !!})
@endif
