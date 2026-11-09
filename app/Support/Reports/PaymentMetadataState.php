<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;

/** Caller-visible snapshot; all reads run beneath the owned group and journal locks. */
final class PaymentMetadataState
{
    public static function fingerprint(TransactionJournal $journal): string
    {
        $source      = $journal->transactions->first(static fn($leg): bool => bccomp((string) $leg->amount, '0', 16) < 0);
        $destination = $journal->transactions->first(static fn($leg): bool => bccomp((string) $leg->amount, '0', 16) > 0);
        if (2 !== $journal->transactions->count() || null === $source || null === $destination || $journal->categories->count() > 1) {
            throw new \RuntimeException('Ambiguous payment scope.');
        }
        $accounts = Account::query()
            ->whereIn('id', [$source->account_id, $destination->account_id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $budgets = DB::table('budget_transaction_journal')->where('transaction_journal_id', $journal->id)->lockForUpdate()->pluck('budget_id')->all();
        if (2 !== $accounts->count() || count($budgets) > 1) {
            throw new \RuntimeException('Ambiguous payment assignment.');
        }
        $tags = $journal->tags->pluck('tag')->all();
        sort($tags, SORT_STRING);
        $foreign = $source->getRawOriginal('foreign_amount');
        $foreign = null === $foreign || '' === $foreign || 0 === bccomp((string) $foreign, '0', 16) ? '' : bcadd(ltrim((string) $foreign, '-'), '0', 16);
        $state   = [
            'payment-metadata-v1',
            (string) $journal->transaction_group_id,
            (string) $journal->id,
            strtolower($journal->transactionType->type),
            (string) $journal->date->getTimestamp(),
            bcadd(ltrim((string) $source->amount, '-'), '0', 16),
            (string) $journal->transaction_currency_id,
            (string) $source->account_id,
            (string) $destination->account_id,
            $accounts[$source->account_id]->name,
            $accounts[$destination->account_id]->name,
            $journal->description,
            $journal->notes->first()?->text ?? '',
            $tags,
            (string) $journal->categories->first()?->id,
            (string) ($budgets[0] ?? ''),
            (string) $journal->bill_id,
            (string) $source->foreign_currency_id,
            $foreign
        ];
        foreach (['external_id', 'internal_reference', 'original_source', 'import_hash_v2'] as $key) {
            $rows = $journal->transactionJournalMeta->where('name', $key);
            if ($rows->count() > 1) {
                throw new \RuntimeException('Ambiguous payment metadata.');
            }
            $value = $rows->isEmpty() ? '' : json_decode((string) $rows->first()->getRawOriginal('data'), true, 512, JSON_THROW_ON_ERROR);
            if (null !== $value && !is_string($value)) {
                throw new \RuntimeException('Invalid payment metadata.');
            }
            $state[] = $value ?? '';
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    public static function lock(TransactionJournal $journal): void
    {
        foreach (['transactions', 'notes', 'transactionJournalMeta', 'categories', 'tags'] as $relation) {
            $query = $journal->{$relation}();
            $journal->setRelation(
                $relation,
                $query
                    ->orderBy($query->getRelated()->getTable() . '.id')
                    ->lockForUpdate()
                    ->get()
            );
        }
        foreach (['category_transaction_journal', 'budget_transaction_journal', 'tag_transaction_journal'] as $table) {
            DB::table($table)->where('transaction_journal_id', $journal->id)->lockForUpdate()->get();
        }
        $journal->setRelation('transactionType', $journal->transactionType()->lockForUpdate()->first());
        $journal->setRelation('transactionCurrency', $journal->transactionCurrency()->lockForUpdate()->first());
    }
}
