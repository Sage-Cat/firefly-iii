<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\Category;
use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PaymentCategoryUpdate
{
    public static function update(Request $request, TransactionGroup $group): JsonResponse
    {
        $expected = $request->header('If-Match-Category');
        $default  = $request->header('If-Category-Default');
        $payment  = $request->header('If-Match-Payment');
        $data     = $request->all();
        $rows     = $data['transactions'] ?? null;
        if (
            !is_string($expected)
            || !preg_match('/^(?:none|[1-9][0-9]*)$/D', $expected)
            || null !== $default && '1' !== $default
            || null !== $payment && (!is_string($payment) || !preg_match('/^[0-9a-f]{64}$/D', $payment))
            || [] !== array_diff(array_keys($data), ['transactions', 'apply_rules', 'fire_webhooks'])
            || ($data['apply_rules'] ?? false) !== false
            || ($data['fire_webhooks'] ?? false) !== false
            || !is_array($rows)
            || array_keys($rows) !== [0]
            || !is_array($rows[0])
            || [] !== array_diff(array_keys($rows[0]), ['transaction_journal_id', 'category_id'])
            || !ctype_digit((string) ($rows[0]['transaction_journal_id'] ?? ''))
            || !ctype_digit((string) ($rows[0]['category_id'] ?? ''))
            || (int) $rows[0]['category_id'] < 1
        ) {
            return response()->json(['message' => 'Conditional category update accepts one category-only journal.'], 422);
        }
        $user = auth()->user();
        if ((string) $group->user_group_id !== (string) $user->user_group_id) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }
        $response = DB::transaction(static function () use ($rows, $group, $expected, $default, $payment, $user): JsonResponse {
            $ownedGroup = TransactionGroup::query()->where('id', $group->id)->where('user_group_id', $user->user_group_id)->lockForUpdate()->first();
            if (null === $ownedGroup) {
                return response()->json(['message' => 'Transaction not found.'], 404);
            }
            $journal = $ownedGroup
                ->transactionJournals()
                ->where('id', $rows[0]['transaction_journal_id'])
                ->where('user_group_id', $user->user_group_id)
                ->lockForUpdate()
                ->first();
            if (null === $journal) {
                return response()->json(['message' => 'Transaction journal not found.'], 404);
            }
            $ids    = $journal->categories()->lockForUpdate()->pluck('categories.id')->all();
            $actual = [] === $ids ? 'none' : (string) $ids[0];
            if (count($ids) > 1 || !hash_equals($expected, $actual)) {
                return response()->json(['message' => 'Category changed since it was read.'], 409);
            }
            try {
                PaymentMetadataState::lock($journal);
                if (null !== $payment && !hash_equals($payment, PaymentMetadataState::fingerprint($journal))) {
                    throw new \RuntimeException('Payment changed since it was read.');
                }
                if ('1' === $default) {
                    $target = Category::query()->where('user_group_id', $user->user_group_id)->where('id', $rows[0]['category_id'])->lockForUpdate()->first();
                    if (null === $target || PaymentReview::DEFAULT_CATEGORY !== $target->name) {
                        throw new \InvalidArgumentException('Default classification requires the owned fallback category.');
                    }
                    $accounts  = PaymentReview::accounts($user);
                    $row       = PaymentReview::row($journal, $accounts);
                    $ownedLegs = $journal->transactions->filter(static fn($leg): bool => isset($accounts[(string) $leg->account_id]));
                    if (
                        $row['pending']
                        || $row['blocked']
                        || !$row['eligible']
                        || 1 !== $ownedLegs->count()
                        || !in_array($journal->transactionType->type, ['Deposit', 'Withdrawal'], true)
                        || !$journal->categories->every(static fn($category): bool => PaymentReview::LEGACY_CATEGORY === $category->name)
                    ) {
                        throw new \RuntimeException('Only an ordinary uncategorized payment can receive a default.');
                    }
                    $markers = Tag::query()->where('user_group_id', $user->user_group_id)->where('tag', PaymentReview::DEFAULT_MARKER)->lockForUpdate()->get();
                    if (1 !== $markers->count() || PaymentReview::DEFAULT_MARKER !== $markers->first()->tag) {
                        throw new \InvalidArgumentException('A unique existing owned fallback marker is required.');
                    }
                    DB::table('tag_transaction_journal')->where('transaction_journal_id', $journal->id)->lockForUpdate()->get();
                    $journal->categories()->sync([(int) $target->id]);
                    $journal->tags()->syncWithoutDetaching($markers->modelKeys());
                } else {
                    PaymentReview::save($user, (int) $journal->id, [
                        'fingerprint' => PaymentReview::fingerprint(PaymentReview::state($journal)),
                        'action'      => 'category',
                        'category_id' => (string) $rows[0]['category_id']
                    ]);
                }
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            } catch (\RuntimeException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return response()->json([
                'data' => [
                    'type'       => 'transactions',
                    'id'         => (string) $group->id,
                    'attributes' => ['transaction_journal_id' => (string) $journal->id, 'category_id' => (string) $rows[0]['category_id']]
                ]
            ]);
        });
        if (200 === $response->getStatusCode()) {
            Preferences::mark();
        }

        return $response;
    }
}
