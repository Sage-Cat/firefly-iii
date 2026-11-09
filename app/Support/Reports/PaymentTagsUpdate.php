<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PaymentTagsUpdate
{
    public static function fingerprint(array $tags): string
    {
        sort($tags, SORT_STRING);

        return hash('sha256', json_encode(
            array_values($tags),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR
        ));
    }

    public static function update(Request $request, TransactionGroup $group): JsonResponse
    {
        $expected = $request->header('If-Match-Tags');
        $category = $request->header('If-Match-Category');
        $payment  = $request->header('If-Match-Payment');
        $data     = $request->all();
        $rows     = $data['transactions'] ?? null;
        if (
            !is_string($expected)
            || !preg_match('/^[a-f0-9]{64}$/D', $expected)
            || null !== $category && (!is_string($category) || !preg_match('/^(?:none|[1-9][0-9]*)$/D', $category))
            || null !== $payment && (!is_string($payment) || !preg_match('/^[0-9a-f]{64}$/D', $payment))
            || [] !== array_diff(array_keys($data), ['transactions', 'apply_rules', 'fire_webhooks'])
            || ($data['apply_rules'] ?? null) !== false
            || ($data['fire_webhooks'] ?? null) !== false
            || !is_array($rows)
            || array_keys($rows) !== [0]
            || !is_array($rows[0])
            || count($rows[0]) !== 2
            || [] !== array_diff(array_keys($rows[0]), ['transaction_journal_id', 'tags'])
            || !is_string($rows[0]['transaction_journal_id'] ?? null)
            || !preg_match('/^[1-9][0-9]*$/D', $rows[0]['transaction_journal_id'])
            || !is_array($rows[0]['tags'] ?? null)
            || !array_is_list($rows[0]['tags'])
        ) {
            return response()->json(['message' => 'Conditional tag update accepts one tags-only journal.'], 422);
        }
        $requested = $rows[0]['tags'];
        foreach ($requested as $tag) {
            if (!is_string($tag) || '' === $tag || trim($tag) !== $tag) {
                return response()->json(['message' => 'Tag names must be non-empty strings.'], 422);
            }
        }
        if (count($requested) !== count(array_unique($requested, SORT_STRING))) {
            return response()->json(['message' => 'Duplicate tag names are not accepted.'], 422);
        }
        $user = auth()->user();
        if (null === $user || (string) $group->user_group_id !== (string) $user->user_group_id) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }
        $response = DB::transaction(static function () use ($group, $rows, $expected, $category, $payment, $requested, $user): JsonResponse {
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
            if (null !== $payment) {
                try {
                    PaymentMetadataState::lock($journal);
                    if (!hash_equals($payment, PaymentMetadataState::fingerprint($journal))) {
                        return response()->json(['message' => 'Payment changed since it was read.'], 409);
                    }
                } catch (\RuntimeException $exception) {
                    return response()->json(['message' => $exception->getMessage()], 409);
                }
            }
            if (null !== $category) {
                $categories = DB::table('category_transaction_journal')
                    ->where('transaction_journal_id', $journal->id)
                    ->lockForUpdate()
                    ->pluck('category_id')
                    ->all();
                $actual = [] === $categories ? 'none' : (string) $categories[0];
                if (count($categories) > 1 || !hash_equals($category, $actual)) {
                    return response()->json(['message' => 'Category changed since it was read.'], 409);
                }
            }
            $currentIds = DB::table('tag_transaction_journal')->where('transaction_journal_id', $journal->id)->lockForUpdate()->pluck('tag_id')->all();
            $current    = Tag::query()->whereIn('id', $currentIds)->where('user_group_id', $user->user_group_id)->lockForUpdate()->get();
            if (count($currentIds) !== $current->count()) {
                return response()->json(['message' => 'Current tag ownership changed.'], 409);
            }
            $names = $current->pluck('tag')->all();
            if (!hash_equals($expected, self::fingerprint($names))) {
                return response()->json(['message' => 'Tags changed since they were read.'], 409);
            }
            if ([] !== array_diff($names, $requested)) {
                return response()->json(['message' => 'Conditional tag updates may only add tags.'], 422);
            }
            $targets = Tag::query()->where('user_group_id', $user->user_group_id)->whereIn('tag', $requested)->lockForUpdate()->get();
            if (count($requested) !== $targets->count() || [] !== array_diff($requested, $targets->pluck('tag')->all())) {
                return response()->json(['message' => 'A unique existing owned tag is required.'], 422);
            }
            $journal->tags()->sync($targets->modelKeys());

            return response()->json([
                'data' => [
                    'type'       => 'transactions',
                    'id'         => (string) $ownedGroup->id,
                    'attributes' => ['transaction_journal_id' => (string) $journal->id, 'tags' => $requested]
                ]
            ]);
        });
        if (200 === $response->getStatusCode()) {
            Preferences::mark();
        }

        return $response;
    }
}
