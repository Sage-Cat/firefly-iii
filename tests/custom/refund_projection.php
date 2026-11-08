<?php

declare(strict_types=1);

namespace FireflyIII\Support\Facades {
    final class Steam
    {
        public static function anonymous(): bool
        {
            return false;
        }
    }
}

namespace FireflyIII\Support\Reports {
    final class ReportingCurrency
    {
        public static function convert(string $amount, string $from, string $to): string
        {
            return CurrencyBridge::convert($amount, $from, $to, ['UAH' => '1', 'EUR' => '50', 'USD' => '45']);
        }

        public static function currency(string $code): object
        {
            return (object) ['id' => 'UAH' === $code ? 1 : 2, 'name' => $code, 'code' => $code, 'symbol' => 'UAH' === $code ? '₴' : '€', 'decimal_places' => 2];
        }

        public static function today(): \DateTimeImmutable
        {
            return new \DateTimeImmutable('2026-11-04');
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/RefundProjection.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ProfitSummary.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/BudgetProjection.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ExpenseProjection.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/PaymentReview.php';
    use FireflyIII\Support\Reports\BudgetProjection;
    use FireflyIII\Support\Reports\ExpenseProjection;
    use FireflyIII\Support\Reports\PaymentReview;
    use FireflyIII\Support\Reports\ProfitSummary;
    use FireflyIII\Support\Reports\RefundProjection;

    $checks = 0;
    function check(bool $value, string $message): void
    {
        global $checks;
        if (!$value) {
            throw new RuntimeException($message);
        }
        ++$checks;
    }

    function trans(string $key): string
    {
        return $key;
    }

    function format_amount_by_symbol($amount, $symbol, $decimals, $colored): string
    {
        return $amount . ' ' . $symbol;
    }

    function flow(string $id, string $type, string $amount, string $day = '2026-11-04', string $currency = 'UAH'): array
    {
        return [
            'id'                     => $id,
            'transaction_journal_id' => $id,
            'type'                   => $type,
            'transaction_type_type'  => $type,
            'amount'                 => $amount,
            'currency'               => $currency,
            'currency_code'          => $currency,
            'day'                    => $day,
            'date'                   => $day,
            'source_id'              => 'Withdrawal' === $type ? 'bank' : 'merchant',
            'destination_id'         => 'Withdrawal' === $type ? 'merchant' : 'bank',
            'budget_id'              => 'budget',
            'category_name'          => 'Deposit' === $type ? RefundProjection::CATEGORY : 'Цифрові сервіси'
        ];
    }

    $charge   = flow('charge', 'Withdrawal', '-125.00');
    $refund   = flow('refund', 'Deposit', '125.00');
    $original = serialize([$charge, $refund]);
    $summary  = ProfitSummary::build([$charge, $refund], [], ['bank'], '2026-11-04', '2026-11-04', 'UAH');
    check($summary['totals'] === ['income' => '0.00', 'expenses' => '0.00', 'net' => '0.00'], 'A charge and refund must cancel without new income.');
    check($summary['transactions'] === 2, 'Both original journal records remain present.');
    $partial = array_replace($refund, ['amount' => '25.00']);
    check(
        ProfitSummary::build([$charge, $partial], [], ['bank'], '2026-11-04', '2026-11-04', 'UAH')['totals']['expenses'] === '100.00',
        'Partial refund offsets only its amount.'
    );
    $later = array_replace($refund, ['day' => '2026-11-05']);
    check(
        ProfitSummary::build([$charge, $later], [], ['bank'], '2026-11-05', '2026-11-05', 'UAH')['totals'] === [
            'income'   => '0.00',
            'expenses' => '-125.00',
            'net'      => '125.00'
        ],
        'A later refund is a credit on the refund date.'
    );
    check(
        ProfitSummary::build(
            [flow('foreign-charge', 'Withdrawal', '-2.50', '2026-11-04', 'EUR'), flow('foreign-refund', 'Deposit', '2.50', '2026-11-04', 'EUR')],
            ['EUR' => '50', 'UAH' => '1'],
            ['bank'],
            '2026-11-04',
            '2026-11-04',
            'UAH'
        )['totals']['expenses'] === '0.00',
        'Both foreign legs use the same NBU bridge once.'
    );
    $ordinary = array_replace($refund, ['category_name' => 'Дохід ФОП']);
    check(
        ProfitSummary::build([$ordinary], [], ['bank'], '2026-11-04', '2026-11-04', 'UAH')['totals']['income'] === '125.00',
        'Ordinary income remains income.'
    );
    check(serialize([$charge, $refund]) === $original, 'Projection must preserve original records.');
    $j = [
        'charge' => [
            'id'            => 'charge',
            'owner'         => '1',
            'group_owner'   => '1',
            'type'          => 'Withdrawal',
            'bank_id'       => 'bank',
            'currency'      => 'UAH',
            'amount'        => '125',
            'category_id'   => '7',
            'category_name' => 'Цифрові сервіси',
            'budget_ids'    => ['budget'],
            'budget_names'  => ['budget' => 'Підписки'],
            'merchant_id'   => 'merchant'
        ],
        'refund' => [
            'id'            => 'refund',
            'owner'         => '1',
            'group_owner'   => '1',
            'type'          => 'Deposit',
            'bank_id'       => 'bank',
            'currency'      => 'UAH',
            'currency_code' => 'UAH',
            'amount'        => '125',
            'day'           => '2026-11-04'
        ]
    ];
    $link = ['type' => 'Refund', 'source_id' => 'charge', 'destination_id' => 'refund'];
    $map  = RefundProjection::linked($j, [$link], '1');
    check(
        $map['refund']['category_name'] === 'Цифрові сервіси' && $map['refund']['budget_ids'] === ['budget'],
        'Refund inherits the original reporting allocation.'
    );
    $rows = RefundProjection::annotate([$charge, $refund], $map);
    check($rows[1]['amount'] === '125.00' && $rows[1]['refund_original_id'] === 'charge', 'Metadata annotates without rewriting money.');
    $period = ExpenseProjection::periodTotalsFrom($rows, '2026-11-04', '2026-11-04');
    check($period[1]['amount'] === '0.00', 'Expense period includes positive expense-credit leg.');
    check(
        ExpenseProjection::periodGroupFrom([$refund], '2026-11-04', '2026-11-04', 'UAH', ['Deposit']) === ['count' => 0],
        'Generic revenue summaries must not count refunds as earned.'
    );
    $categories = ExpenseProjection::categoryChartFrom($rows)[0]['entries'];
    check(
        $categories['Цифрові сервіси'] === '0.00' && !isset($categories[RefundProjection::CATEGORY]),
        'Category expense chart nets the refund against the original category.'
    );
    $history = ExpenseProjection::categoryHistoryFrom($rows, [['label' => 'day', 'start' => '2026-11-04', 'end' => '2026-11-04']]);
    check($history[0]['entries']['day'] === '0.00' && $history[1]['entries']['day'] === '0.00', 'Category history must not show refund as earned income.');
    $budgetRows = BudgetProjection::project($rows, ['bank'], '2026-11-04', '2026-11-04', ['budget']);
    check(BudgetProjection::sum($budgetRows, '2026-11-04', '2026-11-04', ['budget']) === '0.00', 'Budget charge and refund credit cancel once.');
    $j['charge']['budget_ids'] = [];
    $cleared                   = RefundProjection::linked($j, [$link], '1');
    check($cleared['refund']['budget_ids'] === [], 'A budget already removed after full refund must not receive a second credit.');
    $j['charge']['budget_ids'] = ['budget'];
    foreach (['owner' => '2', 'group_owner' => '2', 'bank_id' => 'another-bank', 'currency' => 'EUR'] as $field => $value) {
        $bad                   = $j;
        $bad['refund'][$field] = $value;
        check(RefundProjection::linked($bad, [$link], '1') === [], 'Foreign or mismatched linked money must not allocate a credit: ' . $field);
    }
    check(RefundProjection::linked($j, [array_replace($link, ['type' => 'Related'])], '1') === [], 'Other link types are not refunds.');
    $tooMuch                     = $j;
    $tooMuch['refund']['amount'] = '126';
    check(RefundProjection::linked($tooMuch, [$link], '1') === [], 'An over-refund must not be assigned to that purchase.');
    $ambiguous                 = $j;
    $ambiguous['other-charge'] = array_replace($j['charge'], ['id' => 'other-charge']);
    check(
        RefundProjection::linked($ambiguous, [$link, array_replace($link, ['source_id' => 'other-charge'])], '1') === [],
        'One refund linked to two purchases must not choose arbitrarily.'
    );
    $credits = RefundProjection::merchantCredits($map, '2026-11-04', '2026-11-04');
    check($credits === ['merchant' => '125.00'], 'Expense-account credits use original merchant and reporting date.');
    check(
        ExpenseProjection::accountChartFromBalances(['merchant' => 'Shop'], [], ['merchant' => ['UAH' => '125']], $credits)[0]['entries'] === [],
        'Fully refunded merchant expense chart must show no spending.'
    );
    check(
        ExpenseProjection::merchantDisplayFromBalances(
            ['merchant' => ['UAH' => '125']],
            [],
            ['merchant' => ['UAH' => '125']],
            $credits,
            $credits
        )['merchant']['balance_difference'] === '0.00',
        'Merchant period total must net the refund.'
    );
    $choices = PaymentReview::recommendations(
        ['type' => 'Deposit', 'description' => 'Скасування. SOME SHOP'],
        [],
        ['1' => 'Дохід ФОП', '2' => RefundProjection::CATEGORY]
    );
    check(count($choices) === 1 && $choices[0]['id'] === '2', 'Explicit cancellation recommends refund rather than business income.');
    echo 'Passed ' . $checks . " refund projection checks.\n";
}
