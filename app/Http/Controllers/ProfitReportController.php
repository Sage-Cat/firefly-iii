<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Reports\ProfitSummary;
use FireflyIII\Support\Reports\ReportingCurrency;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

final class ProfitReportController extends Controller
{
    public function index(Request $request): View
    {
        $today = ReportingCurrency::today();
        $input = $request->validate([
            'currency' => 'sometimes|in:EUR,UAH',
            'months'   => 'sometimes|in:1,3,12',
            'start'    => 'nullable|required_with:end|date_format:Y-m-d|before_or_equal:end',
            'end'      => 'nullable|required_with:start|date_format:Y-m-d|before_or_equal:' . $today->format('Y-m-d')
        ]);
        $currency    = $input['currency'] ?? 'UAH';
        $months      = (int) ($input['months'] ?? 1);
        $period      = ProfitSummary::period($today->format('Y-m-d'), $months);
        $end         = Carbon::createFromFormat('!Y-m-d', $input['end'] ?? $period['end'], 'Europe/Kyiv');
        $start       = Carbon::createFromFormat('!Y-m-d', $input['start'] ?? $period['start'], 'Europe/Kyiv');
        $weeklyStart = $today->copy()->subMonthsNoOverflow(3)->addDay();
        $weeklyEnd   = $today->copy();
        abort_if($start->diffInDays($end, true) > 3660, 422, 'Оберіть коротший період.');
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);
        $assets   = $repository->getAccountsByType(config('firefly.accountTypesByIdentifier.asset'));
        $assetIds = $assets
            ->pluck('id')
            ->map(static fn($id): string => (string) $id)
            ->all();
        $journals = [];
        if (!$assets->isEmpty()) {
            $raw = app(GroupCollectorInterface::class)
                ->setUser($user)
                ->setAccounts($assets)
                ->setTypes([TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::WITHDRAWAL->value])
                ->withAccountInformation()
                ->getExtractedJournals();
            foreach ($raw as $journal) {
                $journals[] = [
                    'id'             => $journal['transaction_journal_id'],
                    'day'            => $journal['date']->copy()->setTimezone('Europe/Kyiv')->format('Y-m-d'),
                    'type'           => $journal['transaction_type_type'],
                    'source_id'      => $journal['source_account_id'],
                    'destination_id' => $journal['destination_account_id'],
                    'currency'       => $journal['currency_code'],
                    'amount'         => (string) $journal['amount']
                ];
            }
        }
        $error            = null;
        $summary          = null;
        $monthlySummary   = null;
        $weeklySummary    = null;
        $manualCurrencies = [];
        try {
            $quotes         = ReportingCurrency::quotes();
            $summary        = ProfitSummary::build($journals, $quotes, $assetIds, $start->format('Y-m-d'), $end->format('Y-m-d'), $currency);
            $monthlySummary = ProfitSummary::build($journals, $quotes, $assetIds, $start->format('Y-m-d'), $end->format('Y-m-d'), $currency, 'monthly');
            $weeklySummary  = ProfitSummary::build(
                $journals,
                $quotes,
                $assetIds,
                $weeklyStart->format('Y-m-d'),
                $weeklyEnd->format('Y-m-d'),
                $currency,
                'weekly'
            );
            $manualCurrencies = array_values(array_unique([...$summary['manual_currencies'], ...$weeklySummary['manual_currencies']]));
        } catch (RuntimeException $exception) {
            $error   = $exception->getMessage();
            $summary = $monthlySummary = $weeklySummary = null;
        }
        $firstDay = $journals ? min(array_column($journals, 'day')) : null;

        return view('reports.profit', [
            'title'            => 'Прибуток',
            'mainTitleIcon'    => 'bi-graph-up-arrow',
            'subTitle'         => 'Усі рахунки разом',
            'currency'         => $currency,
            'months'           => $months,
            'start'            => $start,
            'end'              => $end,
            'summary'          => $summary,
            'reportError'      => $error,
            'firstDay'         => $firstDay,
            'monthlySummary'   => $monthlySummary,
            'weeklySummary'    => $weeklySummary,
            'weeklyStart'      => $weeklyStart,
            'weeklyEnd'        => $weeklyEnd,
            'manualCurrencies' => $manualCurrencies,
            'valuationDay'     => $today
        ]);
    }
}
