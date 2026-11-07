@php
    $expenseAccounts = \FireflyIII\Support\Reports\ExpenseProjection::merchantDisplay(
        clone session('start', today(config('app.timezone'))->startOfMonth()),
        clone session('end', today(config('app.timezone'))->endOfMonth())
    );
@endphp
<script nonce="{{ $JS_NONCE }}">
    document.addEventListener('alpine:init', () => {
        window.Alpine.store('expenseCurrency', {
            accounts: {{ \Illuminate\Support\Js::from((object) $expenseAccounts) }},
        });
    }, {once: true});
</script>
