<style nonce="{{ $JS_NONCE }}">
    #budgetList > thead > tr > th:nth-child(2) {
        text-align: start;
    }

    #budgetList > thead > tr > th:nth-child(3),
    #budgetList > thead > tr > th:nth-child(4),
    #budgetList > thead > tr > th:nth-child(5) {
        text-align: end;
    }

    #budgetList > tbody > tr > td:nth-child(3) .input-group {
        max-width: 18rem;
        margin-inline-start: auto;
    }

    #budgetList > thead > tr > th:nth-child(3) {
        padding-inline-end: 4.25rem;
    }

    #budgetList .budget-usage { max-width: 18rem; }
    #budgetList .budget-usage .progress { height: 0.4rem; }
    #budgetList .budget-usage .progress-bar { background-color: #8b7be8; }

    #budgetList .budget_amount {
        text-align: end;
    }
</style>
