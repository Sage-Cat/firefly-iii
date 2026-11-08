<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\Account;
use FireflyIII\Models\Note;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Revise a bank authorization through the native update pipeline under a CAS lock. */
final class BankAuthorizationMutation
{
    public const PENDING_TAG = 'monobank: очікує підтвердження';
    private const HEADER = 'If-Match-Bank-Authorization';
    private const META   = ['external_id', 'internal_reference', 'original_source', 'import_hash_v2'];

    public static function destroy(Request $request, TransactionGroup $group, TransactionGroupRepositoryInterface $repository): JsonResponse
    {
        $expected = self::precondition($request);
        if (null === $expected || [] !== $request->all()) {
            return self::invalid();
        }

        return DB::transaction(static function () use ($group, $expected, $repository): JsonResponse {
            $state = self::snapshot($group, $expected);
            if ($state instanceof JsonResponse) {
                return $state;
            }
            [$locked, $journal, , , , , $marker, $meta] = $state;
            if ('pending' !== $marker->status) {
                return self::conflict();
            }
            if (
                $journal->attachments()->lockForUpdate()->get()->isNotEmpty()
                || $journal->locations()->lockForUpdate()->get()->isNotEmpty()
                || $journal->sourceJournalLinks()->lockForUpdate()->get()->isNotEmpty()
                || $journal->destJournalLinks()->lockForUpdate()->get()->isNotEmpty()
                || $journal->piggyBankEvents()->lockForUpdate()->get()->isNotEmpty()
            ) {
                return self::conflict();
            }
            $archive = [
                'version'                => 1,
                'kind'                   => 'monobank_authorization_release',
                'group_id'               => (string) $locked->id,
                'journal_id'             => (string) $journal->id,
                'fingerprint'            => $expected,
                'monobank_authorization' => $marker,
                'bank_identity'          => $meta
            ];
            foreach (['category_transaction_journal', 'budget_transaction_journal', 'tag_transaction_journal'] as $table) {
                $archive[$table] = DB::table($table)->where('transaction_journal_id', $journal->id)->lockForUpdate()->get()->all();
            }
            $archive['audit_log_entries'] = DB::table('audit_log_entries')
                ->where('auditable_type', TransactionJournal::class)
                ->where('auditable_id', $journal->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();
            // Native deletion detaches assignment pivots. Keep their evidence in a soft-deleted note.
            $note = new Note();
            $note->noteable()->associate($journal);
            $note->title = 'monobank authorization release';
            $note->text  = json_encode($archive, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $note->save();
            $repository->destroy($locked);
            Preferences::mark();

            return response()->json([], 204);
        });
    }

    /** Same compact JSON-array contract as firefly_api.bank_authorization_fingerprint. */
    public static function fingerprint(
        string $groupId,
        TransactionJournal $journal,
        $source,
        $destination,
        string $notes,
        array $tags,
        array $meta = [],
        $category = null,
        $budget = null
    ): string {
        sort($tags, SORT_STRING);
        $state = [
            'bank-authorization-v1',
            $groupId,
            (string) $journal->id,
            'withdrawal',
            (string) $journal->date->getTimestamp(),
            bcadd((string) $destination->amount, '0', 16),
            (string) $journal->transaction_currency_id,
            (string) $source->account_id,
            (string) $destination->account_id,
            $journal->description,
            $notes,
            $tags,
            (string) $category,
            (string) $budget,
            (string) $journal->bill_id
        ];
        foreach (self::META as $name) {
            $state[] = $meta[$name] ?? '';
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    public static function update(Request $request, TransactionGroup $group, callable $update): JsonResponse
    {
        $expected = self::precondition($request);
        if (null === $expected) {
            return self::invalid();
        }
        $data = $request->all();
        $rows = $data['transactions'] ?? null;
        if (
            [] !== array_diff(array_keys($data), ['transactions', 'apply_rules', 'fire_webhooks'])
            || false !== ($data['apply_rules'] ?? null)
            || false !== ($data['fire_webhooks'] ?? null)
            || !is_array($rows)
            || [0] !== array_keys($rows)
            || !is_array($rows[0])
            || [] !== array_diff(array_keys($rows[0]), [
                'transaction_journal_id',
                'type',
                'date',
                'currency_id',
                'amount',
                'source_id',
                'destination_id',
                'tags',
                'notes'
            ])
            || !self::id($rows[0]['transaction_journal_id'] ?? null)
            || !self::money($rows[0]['amount'] ?? null)
            || null === self::timestamp($rows[0]['date'] ?? null)
            || !is_string($rows[0]['notes'] ?? null)
            || !self::tags($rows[0]['tags'] ?? null)
        ) {
            return self::invalid();
        }

        return DB::transaction(static function () use ($group, $expected, $rows, $update): JsonResponse {
            $state = self::snapshot($group, $expected);
            if ($state instanceof JsonResponse) {
                return $state;
            }
            [$locked, $journal, $source, $destination, $notes, $tags, $marker, $meta, $category, $budget] = $state;
            $row = $rows[0];
            foreach ([
                'transaction_journal_id' => $journal->id,
                'currency_id'            => $journal->transaction_currency_id,
                'source_id'              => $source->account_id,
                'destination_id'         => $destination->account_id
            ] as $field => $value) {
                if (array_key_exists($field, $row) && (string) $row[$field] !== (string) $value) {
                    return self::invalid();
                }
            }
            if (array_key_exists('type', $row) && 'withdrawal' !== $row['type']) {
                return self::invalid();
            }
            $old = json_decode($notes);
            $new = json_decode($row['notes']);
            if (
                !$new instanceof \stdClass
                || !self::marker($new, (string) $source->account_id, $marker->key, $marker->currency, $row['amount'], self::timestamp($row['date']))
            ) {
                return self::invalid();
            }
            $next = $new->monobank_authorization;
            if ('settled' === $marker->status && 'pending' === $next->status) {
                return self::invalid();
            }
            unset($old->hold, $old->monobank_authorization, $new->hold, $new->monobank_authorization);
            $oldTags = array_values(array_diff($tags, [self::PENDING_TAG]));
            $newTags = array_values(array_diff($row['tags'], [self::PENDING_TAG]));
            sort($oldTags, SORT_STRING);
            sort($newTags, SORT_STRING);
            if (
                self::canonical($old) !== self::canonical($new)
                || $oldTags !== $newTags
                || in_array(self::PENDING_TAG, $row['tags'], true) !== ('pending' === $next->status)
            ) {
                return self::invalid();
            }

            $expectedJournal             = clone $journal;
            $expectedJournal->date       = new \Carbon\Carbon($row['date']);
            $expectedDestination         = clone $destination;
            $expectedDestination->amount = $row['amount'];
            $resultHash                  = self::fingerprint(
                (string) $locked->id,
                $expectedJournal,
                $source,
                $expectedDestination,
                $row['notes'],
                $row['tags'],
                $meta,
                $category,
                $budget
            );
            // Native repository, audit events, recalculation and response all remain inside this transaction.
            $response = $update($locked);
            if (self::snapshot($locked, $resultHash) instanceof JsonResponse) {
                throw new \RuntimeException('Native update did not preserve the guarded bank authorization.');
            }

            return $response;
        });
    }

    private static function canonical($value): string
    {
        $normalize = static function ($value) use (&$normalize) {
            if ($value instanceof \stdClass) {
                $fields = get_object_vars($value);
                ksort($fields, SORT_STRING);
                foreach ($fields as &$field) {
                    $field = $normalize($field);
                }

                return (object) $fields;
            }
            if (is_array($value)) {
                return array_map($normalize, $value);
            }

            return $value;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function conflict(): JsonResponse
    {
        return response()->json(['message' => 'Bank authorization changed since it was read.'], 409);
    }

    private static function id($value): bool
    {
        return (is_string($value) || is_int($value)) && 1 === preg_match('/^[1-9][0-9]*$/D', (string) $value);
    }

    private static function invalid(): JsonResponse
    {
        return response()->json(['message' => 'Invalid conditional bank authorization mutation.'], 422);
    }

    private static function marker(\stdClass $data, string $account, string $key, string $currency, string $amount, int $time): bool
    {
        $marker = $data->monobank_authorization ?? null;

        return (
            $marker instanceof \stdClass
            && 1 === ($marker->version ?? null)
            && $key === ($marker->key ?? null)
            && self::id($marker->account_id ?? null)
            && $account === (string) $marker->account_id
            && $currency === ($marker->currency ?? null)
            && self::money($marker->amount ?? null)
            && 0 === bccomp($marker->amount, $amount, 16)
            && is_int($marker->bank_time ?? null)
            && $time === $marker->bank_time
            && $time > 0
            && in_array($marker->status ?? null, ['pending', 'settled'], true)
            && is_bool($data->hold ?? null)
            && $data->hold === ('pending' === $marker->status)
        );
    }

    private static function money($value): bool
    {
        return is_string($value) && 1 === preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/D', $value) && bccomp($value, '0', 2) > 0;
    }

    private static function precondition(Request $request): null|string
    {
        $values   = $request->headers->all(self::HEADER);
        $expected = $request->header(self::HEADER);
        foreach (['If-Match-Notes', 'If-Match-Category', 'If-Match-Tags', 'If-Match-Internal-Card'] as $header) {
            if ($request->headers->has($header)) {
                return null;
            }
        }

        return 1 === count($values) && is_string($expected) && preg_match('/^[0-9a-f]{64}$/D', $expected) ? $expected : null;
    }

    /** Read every relevant relation afresh, including empty assignment ranges. */
    private static function snapshot(TransactionGroup $group, string $expected): array|JsonResponse
    {
        $user = auth()->user();
        if (null === $user) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }
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
        $journal    = $journals->first();
        $legs       = $journal->transactions()->orderBy('id')->lockForUpdate()->get();
        $notes      = $journal->notes()->orderBy('id')->lockForUpdate()->get();
        $metaRows   = $journal->transactionJournalMeta()->lockForUpdate()->get();
        $categories = DB::table('category_transaction_journal')->where('transaction_journal_id', $journal->id)->lockForUpdate()->get();
        $budgets    = DB::table('budget_transaction_journal')->where('transaction_journal_id', $journal->id)->lockForUpdate()->get();
        $tagLinks   = DB::table('tag_transaction_journal')->where('transaction_journal_id', $journal->id)->lockForUpdate()->get();
        $tags       = DB::table('tags')->whereIn('id', $tagLinks->pluck('tag_id'))->orderBy('tag')->lockForUpdate()->get();
        $type       = $journal->transactionType()->lockForUpdate()->first();
        $currency   = $journal->transactionCurrency()->lockForUpdate()->first();
        if (
            (string) $journal->user_id !== (string) $user->id
            || (string) $journal->user_group_id !== (string) $user->user_group_id
            || 'Withdrawal' !== $type?->type
            || !in_array($currency?->code, ['UAH', 'EUR', 'USD', 'CHF'], true)
            || 2 !== (int) $currency->decimal_places
            || 2 !== $legs->count()
            || 1 !== $notes->count()
            || '' !== ($notes->first()?->title ?? '')
            || $categories->count() > 1
            || $budgets->count() > 1
            || $tags->count() !== $tagLinks->count()
        ) {
            return self::conflict();
        }
        foreach ([
            'categories' => $categories->pluck('category_id')->all(),
            'budgets'    => $budgets->pluck('budget_id')->all(),
            'bills'      => null === $journal->bill_id ? [] : [$journal->bill_id]
        ] as $table => $ids) {
            $assigned = DB::table($table)->whereIn('id', $ids)->lockForUpdate()->get();
            if (count($ids) !== $assigned->count()) {
                return self::conflict();
            }
            foreach ($assigned as $row) {
                if (
                    null !== $row->deleted_at
                    || (string) $row->user_id !== (string) $user->id
                    || (string) $row->user_group_id !== (string) $user->user_group_id
                ) {
                    return self::conflict();
                }
            }
        }
        foreach ($tags as $tag) {
            if (null !== $tag->deleted_at || (string) $tag->user_id !== (string) $user->id || (string) $tag->user_group_id !== (string) $user->user_group_id) {
                return self::conflict();
            }
        }
        foreach ($legs as $leg) {
            if (
                !preg_match('/^-?[0-9]+(?:\.[0-9]{1,16})?$/D', (string) $leg->amount)
                || (string) $leg->transaction_currency_id !== (string) $currency->id
                || null !== $leg->foreign_currency_id
                || null !== $leg->getRawOriginal('foreign_amount')
                || $leg->reconciled
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
            $accountType     = $account->accountType()->lockForUpdate()->first();
            $accountMeta     = $account->accountMeta()->lockForUpdate()->get();
            $accountNotes    = $account->notes()->lockForUpdate()->get();
            $isSource        = (string) $account->id === (string) $source->account_id;
            $accountCurrency = $accountMeta->where('name', 'currency_id');
            if (
                (string) $account->user_id !== (string) $user->id
                || (string) $account->user_group_id !== (string) $user->user_group_id
                || ($isSource ? !in_array($accountType?->type, ['Asset account', 'Default account'], true) : 'Expense account' !== $accountType?->type)
                || $isSource && (1 !== $accountCurrency->count() || (string) $accountCurrency->first()->data !== (string) $currency->id)
            ) {
                return self::conflict();
            }
            foreach ($accountNotes as $note) {
                $data = json_decode($note->text);
                if ($data instanceof \stdClass && property_exists($data, 'bank_review_suspense')) {
                    return self::conflict();
                }
            }
        }
        $meta = [];
        foreach ($metaRows as $row) {
            $value = json_decode((string) $row->getRawOriginal('data'));
            if (JSON_ERROR_NONE !== json_last_error()) {
                return self::conflict();
            }
            if (!in_array($row->name, self::META, true)) {
                // Native date updates retain this internal recalculation boundary.
                if ('_internal_previous_date' === $row->name && (null === $value || is_string($value) && null !== self::timestamp($value))) {
                    continue;
                }
                if (null !== $value && '' !== $value) {
                    return self::conflict();
                }
                continue;
            }
            if (array_key_exists($row->name, $meta) || !is_string($value)) {
                return self::conflict();
            }
            $meta[$row->name] = $value;
        }
        $rawNotes = $notes->first()->text;
        $data     = json_decode($rawNotes);
        $external = $meta['external_id'] ?? '';
        if (
            !str_starts_with($external, 'monobank:')
            || strlen($external) <= strlen('monobank:')
            || 'monobank' !== ($meta['internal_reference'] ?? '')
            || !$data instanceof \stdClass
            || !self::marker($data, (string) $source->account_id, $external, $currency->code, (string) $destination->amount, $journal->date->getTimestamp())
            || in_array(self::PENDING_TAG, $tags->pluck('tag')->all(), true) !== ('pending' === $data->monobank_authorization->status)
        ) {
            return self::conflict();
        }
        $fingerprint = self::fingerprint(
            (string) $locked->id,
            $journal,
            $source,
            $destination,
            $rawNotes,
            $tags->pluck('tag')->all(),
            $meta,
            $categories->first()?->category_id,
            $budgets->first()?->budget_id
        );
        if (!hash_equals($expected, $fingerprint)) {
            return self::conflict();
        }
        $journal->setRelation('transactions', $legs);
        $locked->setRelation('transactionJournals', $journals);

        return [
            $locked,
            $journal,
            $source,
            $destination,
            $rawNotes,
            $tags->pluck('tag')->all(),
            $data->monobank_authorization,
            $meta,
            $categories->first()?->category_id,
            $budgets->first()?->budget_id
        ];
    }

    private static function tags($tags): bool
    {
        if (!is_array($tags) || !array_is_list($tags)) {
            return false;
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || '' === $tag || trim($tag) !== $tag) {
                return false;
            }
        }

        return count($tags) === count(array_unique($tags, SORT_STRING));
    }

    private static function timestamp($value): null|int
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value)) {
            return null;
        }
        try {
            $date   = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();

            return false === $errors || 0 === $errors['warning_count'] && 0 === $errors['error_count'] ? $date->getTimestamp() : null;
        } catch (\Exception) {
            return null;
        }
    }
}
