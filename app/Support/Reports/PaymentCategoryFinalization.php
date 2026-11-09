<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;

/** An explicit native category selection confirms even an unchanged Other category. */
final class PaymentCategoryFinalization
{
    public static function journal(TransactionJournal $journal, array $data, $user): void
    {
        if (!array_key_exists('category_id', $data) && !array_key_exists('category_name', $data)) {
            return;
        }
        DB::transaction(static function () use ($journal, $user): void {
            $owned = TransactionGroup::query()
                ->where('id', $journal->transaction_group_id)
                ->where('user_group_id', $user->user_group_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = $owned->transactionJournals()->where('id', $journal->id)->where('user_group_id', $user->user_group_id)->lockForUpdate()->firstOrFail();
            DB::table('tag_transaction_journal')->where('transaction_journal_id', $locked->id)->lockForUpdate()->get();
            $markers = $locked->tags()->where('tags.user_group_id', $user->user_group_id)->where('tag', PaymentReview::DEFAULT_MARKER)->lockForUpdate()->get();
            $locked->tags()->detach($markers->modelKeys());
        });
    }
}
