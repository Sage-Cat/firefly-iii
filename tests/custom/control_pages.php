<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->instance('request', \Illuminate\Http\Request::create('http://localhost/', 'GET'));
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function ($exception): void {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
});
$app['view']->getFinder()->prependLocation(getenv('CONTROL_VIEW_ROOT') ?: '/source/resources/views');
$checks = 0;
$check  = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$render = static fn(string $name, array $data): string => view($name, $data + ['anonymous' => false, 'JS_NONCE' => 'synthetic'])->render();
$budget = $render('budgets.control-summary', ['budgeted' => '100.00', 'spent' => '-30.25']);
$check(
    str_contains($budget, '100,00') && str_contains($budget, '30,25') && str_contains($budget, '69,75'),
    'Budget totals use the projected plan, expense and remaining values.'
);
$check(str_contains($render('budgets.control-summary', ['budgeted' => '100.00', 'spent' => '-130.00']), '-30,00'), 'Overspending is not clamped to zero.');
$check(
    trim($render('budgets.control-summary', ['budgeted' => '100.00', 'spent' => '-30.25', 'anonymous' => true])) === '',
    'Anonymous budget summary discloses no totals.'
);
$bill  = static fn(string $id, string $name, null|string $date, bool $active = true): array => [
    'id'                  => $id,
    'name'                => $name,
    'next_expected_match' => $date,
    'active'              => $active
];
$bills = [['bills' => [
    $bill('1', 'Пізніше', '2027-01-01T00:00:00+02:00'),
    $bill('2', 'Раніше', '2026-12-31T23:00:00+02:00'),
    $bill('3', 'Неактивна', '2026-01-01', false),
    $bill('4', 'Без дати', null),
    $bill('5', 'Некоректна дата', '2026-02-30')
]]];
$queue = $render('bills.control-queue', ['bills' => $bills]);
$check(strpos($queue, 'Раніше') < strpos($queue, 'Пізніше'), 'Expected dates sort chronologically across years.');
$check(
    !str_contains($queue, 'Неактивна') && !str_contains($queue, 'Без дати') && !str_contains($queue, 'Некоректна дата'),
    'Inactive and unavailable dates are not invented.'
);
$check(trim($render('bills.control-queue', ['bills' => $bills, 'anonymous' => true])) === '', 'Anonymous queue discloses no names or dates.');
$check(str_contains($render('bills.control-queue', ['bills' => []]), 'немає визначених дат'), 'The empty renewal queue explains the missing native dates.');
foreach (['0.00' => '0', '1.00' => '1', '25.00' => '25', '100.00' => '100', '140.00' => '100', '-5.00' => '0'] as $saved => $expected) {
    $html = $render('piggy-banks.control-progress', ['piggy' => ['target_amount' => '100.00', 'current_amount' => $saved]]);
    $check(
        str_contains($html, 'aria-valuenow="' . $expected . '"') && str_contains($html, 'width: ' . $expected . '%'),
        'Piggy progress reflects real percentages with bounded width.'
    );
}
$check(
    trim($render('piggy-banks.control-progress', ['piggy' => ['target_amount' => null, 'current_amount' => '10.00']])) === '',
    'An unspecified target never implies a percentage.'
);
$check(
    trim($render('piggy-banks.control-progress', ['piggy' => ['target_amount' => '0.00', 'current_amount' => '10.00']])) === '',
    'A zero target never divides by zero.'
);
$check(
    trim($render('piggy-banks.control-progress', ['piggy' => ['target_amount' => '100.00', 'current_amount' => '25.00'], 'anonymous' => true])) === '',
    'Anonymous goal progress discloses no amounts.'
);
$overview = ['error' => null, 'uah' => '120.25', 'eur' => '2.50', 'count' => 0, 'rows' => [], 'day' => '10.11.2026'];
$annual   = $render('bills.subscription-overview', ['subscriptionOverview' => $overview]);
$check(
    str_contains($annual, '1 443,00') && str_contains($annual, '30,00') && str_contains($annual, 'Еквівалент за 12 місяців'),
    'Annual equivalents derive directly from the displayed monthly estimate.'
);
$private = $render('bills.subscription-overview', ['subscriptionOverview' => $overview, 'anonymous' => true]);
$check(
    !str_contains($private, '120,25') && !str_contains($private, '1 443,00') && !str_contains($private, '<canvas'),
    'Anonymous subscription overview discloses neither money nor chart.'
);
$empty = $render('piggy-banks.control-empty', []);
$check(
    str_contains($empty, 'Створити першу скарбничку') && !str_contains($empty, '<form'),
    'Empty goals offer a native creation link without fabricated goals.'
);
$goal = $render('piggy-banks.control-goal', ['piggy' => [
    'target_amount'           => '100.00',
    'current_amount'          => '25.00',
    'target_date'             => '2026-12-31T00:00:00+02:00',
    'left_to_save'            => '75.00',
    'currency_symbol'         => '₴',
    'currency_decimal_places' => 2
]]);
$check(
    str_contains($goal, '31.12.2026') && str_contains($goal, '75') && str_contains($goal, '₴') && str_contains($goal, '25%'),
    'Mobile goals expose the native deadline, actual progress and remaining amount.'
);
$piggyRow  = [
    'id'                      => '901',
    'name'                    => 'Моя довга мета для подорожі та відпочинку',
    'order'                   => 1,
    'attachments'             => [],
    'current_amount'          => '1.00',
    'currency_symbol'         => '₴',
    'currency_decimal_places' => 2,
    'currency_id'             => '1',
    'target_amount'           => '100.00',
    'left_to_save'            => '99.00',
    'target_date'             => '2027-01-31T00:00:00+02:00',
    'save_per_month'          => null
];
$piggyList = $render('components.lists.piggy-banks', [
    'piggyBanks'       => [['object_group_title' => 'Цілі', 'piggy_banks' => [$piggyRow], 'sums' => []]],
    'convertToPrimary' => false,
    'primaryCurrency'  => (object) ['id' => '1'],
    'FF3_FROM'         => '/'
]);
$check(str_contains($piggyList, 'aria-valuenow="1"') && !str_contains($piggyList, 'max(30'), 'The actual native list renders honest one-percent progress.');
if (getenv('CONTROL_RENDER_OUTPUT')) {
    $manifest = json_decode(file_get_contents('/var/www/html/public/build/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $css      = file_get_contents('/var/www/html/public/build/' . $manifest['sass/app.scss']['file']);
    file_put_contents(
        getenv('CONTROL_RENDER_OUTPUT'),
        json_encode([
            'budget'    => $budget,
            'queue'     => $queue,
            'goal'      => $goal,
            'overview'  => $annual,
            'empty'     => $empty,
            'piggyList' => $piggyList,
            'css'       => $css
        ], JSON_THROW_ON_ERROR)
    );
}
echo 'Passed ' . $checks . " control page render checks.\n";
