<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalLink;

final class RefundProjection
{
    public const CATEGORY = 'Повернення коштів';

    public static function annotate(array $rows, array $refunds): array
    {
        foreach ($rows as &$row) {
            $id = (string) ($row['transaction_journal_id'] ?? $row['journal_id'] ?? $row['id'] ?? '');
            if (isset($refunds[$id]) && 'Deposit' === ($row['transaction_type_type'] ?? $row['type'] ?? '')) {
                $row['is_refund']            = true;
                $row['refund_original_id']   = $refunds[$id]['original_id'];
                $row['refund_category_name'] = $refunds[$id]['category_name'];
                $row['refund_category_id']   = $refunds[$id]['category_id'];
                $row['refund_budget_ids']    = $refunds[$id]['budget_ids'];
                $row['refund_budget_names']  = $refunds[$id]['budget_names'];
                $row['refund_merchant_id']   = $refunds[$id]['merchant_id'];
                $row['refund_bill_id']       = $refunds[$id]['bill_id'];
            }
        }
        unset($row);

        return $rows;
    }

    /** A refund is an expense credit; its original amount and date remain authoritative. */
    public static function isRefund(array $row): bool
    {
        $type = $row['transaction_type_type'] ?? $row['type'] ?? '';

        return 'Deposit' === $type && (true === ($row['is_refund'] ?? false) || self::CATEGORY === ($row['category_name'] ?? null));
    }

    /** Validate both owners and the exact bank-side currency before assigning category/budget credits. */
    public static function linked(array $journals, array $links, string $owner): array
    {
        $result     = [];
        $candidates = [];
        foreach ($links as $link) {
            if ('Refund' !== ($link['type'] ?? '')) {
                continue;
            }
            $original = $journals[(string) $link['source_id']] ?? null;
            $refund   = $journals[(string) $link['destination_id']] ?? null;
            if (
                !$original
                || !$refund
                || 'Withdrawal' !== $original['type']
                || 'Deposit' !== $refund['type']
                || (string) ($original['owner'] ?? '') !== $owner
                || (string) ($refund['owner'] ?? '') !== $owner
                || (string) ($original['group_owner'] ?? '') !== $owner
                || (string) ($refund['group_owner'] ?? '') !== $owner
                || $original['bank_id'] !== $refund['bank_id']
                || $original['currency'] !== $refund['currency']
                || bccomp($refund['amount'], '0', 16) <= 0
                || bccomp($original['amount'], '0', 16) <= 0
            ) {
                continue;
            }
            $candidates[(string) $refund['id']][(string) $original['id']] = true;
        }
        $totals = [];
        foreach ($candidates as $refundId => $originalIds) {
            if (1 === count($originalIds)) {
                $originalId          = (string) array_key_first($originalIds);
                $totals[$originalId] = bcadd($totals[$originalId] ?? '0', $journals[$refundId]['amount'], 16);
            }
        }
        foreach ($candidates as $refundId => $originalIds) {
            if (1 !== count($originalIds)) {
                continue;
            }
            $originalId = (string) array_key_first($originalIds);
            $original   = $journals[$originalId];
            $refund     = $journals[$refundId];
            if (bccomp($totals[$originalId], $original['amount'], 16) > 0) {
                continue;
            }
            $result[(string) $refundId] = [
                'original_id'   => $originalId,
                'category_name' => $original['category_name'] ?? null,
                'category_id'   => $original['category_id'] ?? null,
                'budget_ids'    => $original['budget_ids'] ?? [],
                'budget_names'  => $original['budget_names'] ?? [],
                'merchant_id'   => $original['merchant_id'] ?? null,
                'bill_id'       => $original['bill_id'] ?? null,
                'amount'        => $refund['amount'],
                'currency'      => $refund['currency_code'] ?? null,
                'day'           => $refund['day'] ?? null
            ];
        }

        return $result;
    }

    public static function merchantCredits(array $refunds, null|string $first = null, null|string $last = null): array
    {
        $native = [];
        foreach ($refunds as $refund) {
            if (
                null === ($refund['merchant_id'] ?? null)
                || null === ($refund['currency'] ?? null)
                || null === ($refund['day'] ?? null)
                || null !== $first && $refund['day'] < $first
                || null !== $last && $refund['day'] > $last
            ) {
                continue;
            }
            $id                 = (string) $refund['merchant_id'];
            $code               = $refund['currency'];
            $native[$id][$code] = bcadd($native[$id][$code] ?? '0', $refund['amount'], 16);
        }
        $result = [];
        foreach ($native as $id => $currencies) {
            $result[$id] = '0.00';
            foreach ($currencies as $code => $amount) {
                $result[$id] = bcadd($result[$id], ReportingCurrency::convert($amount, $code, 'UAH'), 2);
            }
        }

        return $result;
    }

    public static function metadata($user): array
    {
        if (!isset($user->user_group_id)) {
            return [];
        }
        $owner = (string) $user->user_group_id;
        $key   = 'reporting-refunds:' . $owner;
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $scope = static fn($query) => $query->where('user_group_id', $owner)->whereHas('transactionGroup', static fn($group) => $group->where(
            'user_group_id',
            $owner
        ));
        $models = TransactionJournalLink::query()
            ->whereHas('linkType', static fn($query) => $query->where('name', 'Refund'))
            ->whereHas('source', $scope)
            ->whereHas('destination', $scope)
            ->with([
                'source.transactionGroup',
                'source.transactionType',
                'source.transactionCurrency',
                'source.categories',
                'source.budgets',
                'source.transactions',
                'destination.transactionGroup',
                'destination.transactionType',
                'destination.transactionCurrency',
                'destination.transactions'
            ])
            ->get();
        $journals = [];
        $links    = [];
        foreach ($models as $model) {
            $links[] = ['type' => 'Refund', 'source_id' => (string) $model->source_id, 'destination_id' => (string) $model->destination_id];
            foreach ([$model->source, $model->destination] as $journal) {
                $withdrawal = 'Withdrawal' === $journal->transactionType->type;
                $leg        = $journal->transactions->first(static fn($leg): bool => $withdrawal
                    ? bccomp((string) $leg->amount, '0', 16) < 0
                    : bccomp((string) $leg->amount, '0', 16) > 0);
                if (null === $leg) {
                    continue;
                }
                $category = $withdrawal ? $journal->categories->first() : null;
                $merchant = $withdrawal ? $journal->transactions->first(static fn($leg): bool => bccomp((string) $leg->amount, '0', 16) > 0) : null;

                $journals[(string) $journal->id] = [
                    'id'            => (string) $journal->id,
                    'owner'         => (string) $journal->user_group_id,
                    'group_owner'   => (string) $journal->transactionGroup->user_group_id,
                    'type'          => $journal->transactionType->type,
                    'bank_id'       => (string) $leg->account_id,
                    'currency'      => (string) $leg->transaction_currency_id,
                    'amount'        => ltrim((string) $leg->amount, '-'),
                    'category_name' => $category?->name,
                    'category_id'   => $category?->id,
                    'bill_id'       => $journal->bill_id,
                    'currency_code' => $journal->transactionCurrency->code,
                    'day'           => $journal->date->copy()->setTimezone('Europe/Kyiv')->format('Y-m-d'),
                    'merchant_id'   => $merchant?->account_id,
                    'budget_ids'    => $withdrawal
                        ? $journal->budgets->pluck('id')
                            ->map(static fn($id): string => (string) $id)
                            ->all()
                        : [],
                    'budget_names'  => $withdrawal ? $journal->budgets->pluck('name', 'id')->all() : []
                ];
            }
        }
        $result   = self::linked($journals, $links, $owner);
        $unlinked = TransactionJournal::query()
            ->where('user_group_id', $owner)
            ->whereHas('transactionGroup', static fn($group) => $group->where('user_group_id', $owner))
            ->whereHas('transactionType', static fn($type) => $type->where('type', 'Deposit'))
            ->whereHas('categories', static fn($category) => $category->where('name', self::CATEGORY)->where('user_group_id', $owner))
            ->with(['categories', 'transactions', 'transactionCurrency'])
            ->get();
        foreach ($unlinked as $journal) {
            $id = (string) $journal->id;
            if (isset($result[$id])) {
                continue;
            }
            $leg = $journal->transactions->first(static fn($leg): bool => bccomp((string) $leg->amount, '0', 16) > 0);
            if (null !== $leg) {
                $category    = $journal->categories->firstWhere('name', self::CATEGORY);
                $result[$id] = [
                    'original_id'   => null,
                    'category_name' => self::CATEGORY,
                    'category_id'   => $category?->id,
                    'budget_ids'    => [],
                    'budget_names'  => [],
                    'merchant_id'   => null,
                    'bill_id'       => null,
                    'amount'        => ltrim((string) $leg->amount, '-'),
                    'currency'      => $journal->transactionCurrency->code,
                    'day'           => $journal->date->copy()->setTimezone('Europe/Kyiv')->format('Y-m-d')
                ];
            }
        }
        request()->attributes->set($key, $result);

        return $result;
    }
}
