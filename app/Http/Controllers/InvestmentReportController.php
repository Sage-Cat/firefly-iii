<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Reports\AccountingPeriod;
use FireflyIII\Support\Reports\DashboardLedger;
use FireflyIII\Support\Reports\InvestmentOverview;
use FireflyIII\Support\Reports\ReportingCurrency;
use Illuminate\View\View;
use RuntimeException;

final class InvestmentReportController extends Controller
{
    public function index(): View
    {
        $today    = ReportingCurrency::today();
        $overview = null;
        $error    = null;
        $start    = $today->copy()->subYearNoOverflow()->addDay();

        try {
            $start      = AccountingPeriod::start($start->format('Y-m-d'), $today->format('Y-m-d'));
            $repository = app(AccountRepositoryInterface::class);
            $repository->setUser(auth()->user());
            $ledger     = DashboardLedger::data();
            $accounts   = [];
            foreach ($repository->getAccountsByType(config('firefly.accountTypesByIdentifier.asset')) as $model) {
                $id            = (string) $model->id;
                if (!isset($ledger['accounts'][$id])) {
                    continue;
                }
                $accounts[$id] = $ledger['accounts'][$id] + ['name' => $model->name,
                    'profile'                                       => InvestmentOverview::read($repository->getNoteText($model) ?? '')];
            }
            // Liabilities keep their place in total capital but can never be investment positions.
            foreach ($ledger['accounts'] as $id => $account) {
                $accounts[$id] ??= $account + ['name' => '', 'profile' => null];
            }
            $overview   = InvestmentOverview::build($accounts, $ledger['legs'], ReportingCurrency::quotes(), $start, $today->format('Y-m-d'));
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        return view('reports.investments', [
            'title'    => 'Інвестиції', 'mainTitleIcon' => 'bi-bank', 'subTitle' => 'Облігації, брокер і криптоактиви',
            'overview' => $overview, 'investmentError' => $error, 'valuationDay' => $today,
        ]);
    }
}
