<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepository;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Retire only an unchanged technical leg for confirmed cards of one asset. */
final class PendingInternalCardDelete
{
    private const META = ['external_id', 'internal_reference', 'original_source', 'import_hash_v2'];

    public static function destroy(Request $request, TransactionGroup $group, TransactionGroupRepository $repository): JsonResponse
    {
        $expected = $request->header('If-Match-Internal-Card');
        if (!is_string($expected) || !preg_match('/^[0-9a-f]{64}$/D', $expected)) {
            return response()->json(['message' => 'Invalid internal card delete precondition.'], 422);
        }
        $user = auth()->user();

        return DB::transaction(static function () use ($group, $user, $expected, $repository): JsonResponse {
            // Ignore route binding's eager-loaded snapshot: every read belongs to this lock.
            $locked = TransactionGroup::query()
                ->whereKey($group->id)
                ->where('user_id', $user->id)
                ->where('user_group_id', $user->user_group_id)
                ->lockForUpdate()
                ->first();
            if (null === $locked) {
                return response()->json(['message' => 'Transaction not found.'], 404);
            }
            $journals = $locked->transactionJournals()->lockForUpdate()->get();
            if (1 !== $journals->count() || '' !== ($locked->title ?? '')) {
                return self::conflict();
            }
            $journal = $journals->first();
            if ((string) $journal->user_id !== (string) $user->id || (string) $journal->user_group_id !== (string) $user->user_group_id) {
                return self::conflict();
            }
            $legs     = $journal->transactions()->orderBy('id')->lockForUpdate()->get();
            $notes    = $journal->notes()->orderBy('id')->lockForUpdate()->get();
            $metaRows = $journal->transactionJournalMeta()->lockForUpdate()->get();
            $meta     = [];
            foreach ($metaRows as $row) {
                if (!in_array($row->name, self::META, true)) {
                    if (null !== $row->data && '' !== $row->data) {
                        return self::conflict();
                    }
                    continue;
                }
                if (array_key_exists($row->name, $meta) || !is_string($row->data)) {
                    return self::conflict();
                }
                $meta[$row->name] = $row->data;
            }
            // Lock empty pivot ranges too, so a concurrent assignment cannot slip through.
            $categories = DB::table('category_transaction_journal')->where('transaction_journal_id', $journal->id)->lockForUpdate()->get();
            $budgets    = DB::table('budget_transaction_journal')->where('transaction_journal_id', $journal->id)->lockForUpdate()->get();
            $tagLinks   = DB::table('tag_transaction_journal')->where('transaction_journal_id', $journal->id)->lockForUpdate()->get();
            $tags       = DB::table('tags')->whereIn('id', $tagLinks->pluck('tag_id'))->orderBy('tag')->lockForUpdate()->get();
            if (
                $journal->attachments()->lockForUpdate()->get()->isNotEmpty()
                || $journal->locations()->lockForUpdate()->get()->isNotEmpty()
                || $journal->sourceJournalLinks()->lockForUpdate()->get()->isNotEmpty()
                || $journal->destJournalLinks()->lockForUpdate()->get()->isNotEmpty()
                || $journal->piggyBankEvents()->lockForUpdate()->get()->isNotEmpty()
            ) {
                return self::conflict();
            }
            $type     = $journal->transactionType()->lockForUpdate()->first();
            $currency = $journal->transactionCurrency()->lockForUpdate()->first();
            if (
                'Transfer' !== $type?->type
                || 2 !== $legs->count()
                || 1 !== $notes->count()
                || $categories->isNotEmpty()
                || $budgets->isNotEmpty()
                || null !== $journal->bill_id
                || null === $currency
                || $tags->count() !== $tagLinks->count()
                || '' !== ($notes->first()?->title ?? '')
            ) {
                return self::conflict();
            }
            foreach ($tags as $tag) {
                if (
                    null !== $tag->deleted_at
                    || (string) $tag->user_id !== (string) $user->id
                    || (string) $tag->user_group_id !== (string) $user->user_group_id
                ) {
                    return self::conflict();
                }
            }
            $source      = $legs->first(static fn($leg): bool => bccomp((string) $leg->amount, '0', 16) < 0);
            $destination = $legs->first(static fn($leg): bool => bccomp((string) $leg->amount, '0', 16) > 0);
            if (
                null === $source
                || null === $destination
                || (string) $source->account_id === (string) $destination->account_id
                || 0 !== bccomp(bcadd((string) $source->amount, (string) $destination->amount, 16), '0', 16)
            ) {
                return self::conflict();
            }
            foreach ($legs as $leg) {
                if (
                    (string) $leg->transaction_currency_id !== (string) $currency->id
                    || null !== $leg->foreign_currency_id
                    || null !== $leg->getRawOriginal('foreign_amount')
                    || $leg->reconciled
                ) {
                    return self::conflict();
                }
            }
            $accounts = Account::query()
                ->whereIn('id', [$source->account_id, $destination->account_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if (2 !== $accounts->count()) {
                return self::conflict();
            }
            foreach ($accounts as $account) {
                $accountType = $account->accountType()->lockForUpdate()->first();
                if (
                    (string) $account->user_id !== (string) $user->id
                    || (string) $account->user_group_id !== (string) $user->user_group_id
                    || !in_array($accountType?->type, ['Asset account', 'Default account'], true)
                ) {
                    return self::conflict();
                }
                $accountMeta = $account->accountMeta()->where('name', 'currency_id')->lockForUpdate()->get();
                if (1 !== $accountMeta->count() || (string) $accountMeta->first()->data !== (string) $currency->id) {
                    return self::conflict();
                }
            }
            $sourceNotes      = $accounts[$source->account_id]->notes()->lockForUpdate()->get();
            $destinationNotes = $accounts[$destination->account_id]->notes()->lockForUpdate()->get();
            if (1 !== $sourceNotes->count()) {
                return self::conflict();
            }
            $suspense = json_decode($sourceNotes->first()->text, true);
            if (!is_array($suspense) || 1 !== ($suspense['bank_review_suspense'] ?? null) || ($suspense['currency'] ?? null) !== $currency->code) {
                return self::conflict();
            }
            foreach ($destinationNotes as $note) {
                $data = json_decode($note->text, true);
                if (is_array($data) && array_key_exists('bank_review_suspense', $data)) {
                    return self::conflict();
                }
            }
            $rawNotes = $notes->first()->text;
            $data     = json_decode($rawNotes, true);
            $review   = is_array($data) ? $data['bank_review'] ?? null : null;
            if (
                !is_array($review)
                || 1 !== ($review['version'] ?? null)
                || 'own_transfer' !== ($review['kind'] ?? null)
                || 'pending' !== ($review['status'] ?? null)
                || 'same_asset_cards' !== ($review['account_scope'] ?? null)
                || 'in' !== ($review['direction'] ?? null)
                || isset($review['decision'])
                || (string) ($review['account_id'] ?? '') !== (string) $destination->account_id
                || ($review['currency'] ?? null) !== $currency->code
                || !is_string($review['amount'] ?? null)
                || !preg_match('/^[0-9]+(?:\.[0-9]{1,16})?$/D', $review['amount'])
                || 0 !== bccomp($review['amount'], (string) $destination->amount, 16)
            ) {
                return self::conflict();
            }
            $fingerprint = self::fingerprint((string) $locked->id, $journal, $source, $destination, $rawNotes, $tags->pluck('tag')->all(), $meta);
            if (!hash_equals($expected, $fingerprint)) {
                return self::conflict();
            }
            $journal->setRelation('transactions', $legs);
            $locked->setRelation('transactionJournals', $journals);
            $repository->destroy($locked);
            Preferences::mark();

            return response()->json([], 204);
        });
    }

    /** Same compact string-array contract as firefly_api.internal_card_fingerprint. */
    public static function fingerprint(
        string $groupId,
        TransactionJournal $journal,
        $source,
        $destination,
        string $notes,
        array $tags,
        array $meta = []
    ): string {
        sort($tags, SORT_STRING);
        $state = [
            'internal-card-v1',
            $groupId,
            (string) $journal->id,
            'transfer',
            (string) $journal->date->getTimestamp(),
            bcadd((string) $destination->amount, '0', 16),
            (string) $journal->transaction_currency_id,
            (string) $source->account_id,
            (string) $destination->account_id,
            $journal->description,
            $notes,
            $tags
        ];
        foreach (self::META as $name) {
            $state[] = $meta[$name] ?? '';
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    private static function conflict(): JsonResponse
    {
        return response()->json(['message' => 'Pending internal card staging changed since it was read.'], 409);
    }
}
