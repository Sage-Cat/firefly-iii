<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\Transaction;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use RuntimeException;

final class DashboardLedger
{
    /** Original signed account legs; no balances, exchange fields or preferences are written. */
    public static function data(): array
    {
        $user = auth()->user();
        $key  = 'dashboard-ledger:' . $user->user_group_id;
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);
        $types    = array_values(array_unique(array_merge(
            config('firefly.accountTypesByIdentifier.asset'),
            config('firefly.accountTypesByIdentifier.liabilities')
        )));
        $models   = $repository->getAccountsByType($types);
        $accounts = [];
        $coverage = [];
        foreach ($models as $account) {
            $currency = $repository->getAccountCurrency($account);
            if (null === $currency) {
                throw new RuntimeException('Не визначено валюту власного рахунку.');
            }
            $flag     = $repository->getMetaValue($account, 'include_net_worth');
            $notes    = $repository->getNoteText($account) ?? '';
            $history  = BalanceHistory::read($notes);
            $metadata = json_decode($notes, true);
            $suspense = is_array($metadata) && 1 === ($metadata['bank_review_suspense'] ?? null) && $currency->code === ($metadata['currency'] ?? null);
            $coverage[(string) $account->id] = $history['ledger_from'];
            $accounts[(string) $account->id] = [
                'id'         => (string) $account->id,
                'currency'   => $currency->code,
                'net_worth'  => null === $flag || '1' === $flag,
                'known_from' => $suspense ? '1970-01-01' : null,
                'snapshots'  => $history['snapshots']
            ];
        }
        $legs  = [];
        $first = [];
        if ([] !== $accounts) {
            $rows = Transaction::query()
                ->whereIn('account_id', array_keys($accounts))
                ->whereHas('transactionJournal', static fn($query) => $query->where('user_group_id', $user->user_group_id)->whereHas('transactionGroup'))
                ->with(['transactionJournal.transactionType', 'transactionJournal.budgets', 'transactionCurrency'])
                ->get();
            foreach ($rows as $row) {
                $journal    = $row->transactionJournal;
                $day        = $journal->date->copy()->setTimezone('Europe/Kyiv')->format('Y-m-d');
                $id         = (string) $row->account_id;
                $type       = $journal->transactionType->type;
                $first[$id] = isset($first[$id]) ? min($first[$id], $day) : $day;
                if (in_array($type, ['Opening balance', 'Liability credit'], true) && 0 !== bccomp((string) $row->amount, '0', 16)) {
                    $known                       = $accounts[$id]['known_from'];
                    $accounts[$id]['known_from'] = null === $known ? $day : min($known, $day);
                }
                $legs[] = [
                    'id'         => (string) $row->id,
                    'journal_id' => (string) $journal->id,
                    'account_id' => $id,
                    'day'        => $day,
                    'currency'   => $row->transactionCurrency->code,
                    'amount'     => (string) $row->amount,
                    'type'       => $type,
                    'budget_ids' => $journal->budgets->pluck('id')
                        ->map(static fn($value) => (string) $value)
                        ->all()
                ];
            }
        }
        foreach ($accounts as $id => &$account) {
            $account['known_from'] ??= $first[$id] ?? null;
            if (null !== $coverage[$id]) {
                $account['known_from'] = $coverage[$id];
            }
        }
        unset($account);
        $result = ['accounts' => $accounts, 'legs' => $legs];
        request()->attributes->set($key, $result);

        return $result;
    }
}
