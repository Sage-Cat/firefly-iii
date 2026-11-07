<template x-if="$store.expenseCurrency.accounts[account.id]">
    <span>
        <template x-if="0 === $store.expenseCurrency.accounts[account.id].{{ $expenseField }}_sign">
            <span class="money-neutral" x-text="$store.expenseCurrency.accounts[account.id].{{ $expenseField }}_formatted"></span>
        </template>
        <template x-if="$store.expenseCurrency.accounts[account.id].{{ $expenseField }}_sign > 0">
            <span class="money-positive" x-text="$store.expenseCurrency.accounts[account.id].{{ $expenseField }}_formatted"></span>
        </template>
        <template x-if="$store.expenseCurrency.accounts[account.id].{{ $expenseField }}_sign < 0">
            <span class="money-negative" x-text="$store.expenseCurrency.accounts[account.id].{{ $expenseField }}_formatted"></span>
        </template>
    </span>
</template>
<template x-if="!$store.expenseCurrency.accounts[account.id]">
    <span class="money-neutral">—</span>
</template>
