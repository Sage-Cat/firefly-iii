<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class PaymentReview
{
    public const LEGACY_CATEGORY = 'Потребує уточнення';

    public static function accounts($user): array
    {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);
        $result = [];
        foreach ($repository->getAccountsByType(config('firefly.accountTypesByIdentifier.asset')) as $account) {
            if ((string) $account->user_group_id !== (string) $user->user_group_id) {
                continue;
            }
            $currency = $repository->getAccountCurrency($account);
            if (null === $currency) {
                continue;
            }
            $note                          = self::decodeNote($repository->getNoteText($account) ?? '');
            $result[(string) $account->id] = [
                'id'       => (string) $account->id,
                'name'     => $account->name,
                'currency' => $currency->code,
                'active'   => (bool) $account->active,
                'suspense' => 1 === ($note['bank_review_suspense'] ?? null)
            ];
        }

        return $result;
    }

    public static function assertFingerprint(array $state, string $expected): void
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $expected) || !hash_equals(self::fingerprint($state), $expected)) {
            throw new RuntimeException('Платіж змінився. Онови сторінку та перевір його ще раз.');
        }
    }

    public static function categories($user): array
    {
        return Category::query()
            ->where('user_group_id', $user->user_group_id)
            ->where('name', '!=', self::LEGACY_CATEGORY)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** Only already scoped category/account maps may be passed to this decision builder. */
    public static function decide(array $review, string $action, null|string $categoryId, null|string $accountId, array $categories, array $accounts): array
    {
        if ('pending' !== ($review['status'] ?? '')) {
            throw new InvalidArgumentException('Рішення вже збережено. Дочекайся його застосування синхронізацією.');
        }
        if ('transfer' === $action) {
            $account = $accounts[$accountId ?? ''] ?? null;
            if (
                !is_array($account)
                || ($account['suspense'] ?? true)
                || !($account['active'] ?? false)
                || (string) $accountId === (string) $review['account_id']
                || $account['currency'] !== $review['currency']
            ) {
                throw new InvalidArgumentException('Обери інший власний рахунок у валюті переказу.');
            }
            $decision = ['kind' => 'transfer', 'account_id' => (string) $accountId];
        } elseif ('external' === $action) {
            if (!isset($categories[$categoryId ?? '']) || self::LEGACY_CATEGORY === $categories[$categoryId]) {
                throw new InvalidArgumentException('Обери категорію зовнішнього платежу.');
            }
            $decision = ['kind' => 'external', 'category_id' => (string) $categoryId];
        } else {
            throw new InvalidArgumentException('Обери тип платежу.');
        }
        $review['status']   = 'decided';
        $review['decision'] = $decision;

        return $review;
    }

    public static function decodeNote(string $text): array
    {
        $value = json_decode($text, true);

        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    public static function fingerprint(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    public static function page($user, int $page, string $filter): array
    {
        $accounts   = self::accounts($user);
        $categories = self::categories($user);
        $candidates = self::query($user)
            ->where(static function ($query): void {
                $query->where(static fn($ordinary) => $ordinary->whereHas('transactionType', static fn($type) => $type->whereIn('type', [
                    'Withdrawal',
                    'Deposit'
                ]))->whereDoesntHave('categories', static fn($category) => $category->where(
                    'name',
                    '!=',
                    self::LEGACY_CATEGORY
                )))->orWhereHas('notes', static fn($note) => $note->where('text', 'like', '%"bank_review"%'));
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
        $rows = $candidates
            ->map(static fn($journal): array => self::row($journal, $accounts))
            ->filter(static fn(array $row): bool => $row['eligible'])
            ->values();
        $counts = ['all' => $rows->count(), 'category' => $rows->where('pending', false)->count(), 'transfer' => $rows->where('pending', true)->count()];
        if ('all' !== $filter) {
            $rows = $rows->where('pending', 'transfer' === $filter)->values();
        }
        $history = self::query($user)
            ->whereHas('categories', static fn($category) => $category->where('name', '!=', self::LEGACY_CATEGORY))
            ->whereHas('transactionType', static fn($type) => $type->whereIn('type', ['Withdrawal', 'Deposit']))
            ->orderByDesc('date')
            ->limit(2000)
            ->get()
            ->map(static fn($journal): array => self::row($journal, $accounts))
            ->all();
        $paginator = new LengthAwarePaginator(
            $rows
                ->forPage($page, 20)
                ->map(static function (array $row) use ($history, $categories): array {
                    $row['recommendations'] = self::recommendations($row, $history, $categories);

                    return $row;
                })
                ->values(),
            $rows->count(),
            20,
            $page,
            ['path' => route('transactions.review'), 'query' => ['filter' => $filter]]
        );

        return ['payments' => $paginator, 'counts' => $counts, 'categories' => $categories, 'accounts' => $accounts, 'filter' => $filter];
    }

    /** Recommendations remain choices and never change an imported journal. */
    public static function recommendations(array $payment, array $history, array $categories): array
    {
        $available = array_filter($categories, static fn(string $name): bool => self::LEGACY_CATEGORY !== $name);
        if ('Deposit' === ($payment['type'] ?? '') && preg_match('/^\s*Скасування\s*[.:]/ui', (string) ($payment['description'] ?? ''))) {
            $refundId = array_search('Повернення коштів', $available, true);

            return false === $refundId ? [] : [['id' => (string) $refundId, 'name' => 'Повернення коштів', 'reason' => 'Банк позначив скасування платежу']];
        }
        $scores      = [];
        $description = self::normalize((string) ($payment['description'] ?? ''));
        $merchant    = self::normalize((string) ($payment['merchant'] ?? ''));
        $generic     = ['', 'monobank', 'privat', 'приват', 'невідомий контрагент'];
        foreach ($history as $previous) {
            $category = (string) ($previous['category_id'] ?? '');
            if (!isset($available[$category]) || ($previous['type'] ?? '') !== ($payment['type'] ?? '')) {
                continue;
            }
            $sameDescription = '' !== $description && $description === self::normalize((string) ($previous['description'] ?? ''));
            $sameMerchant    = !in_array($merchant, $generic, true) && $merchant === self::normalize((string) ($previous['merchant'] ?? ''));
            if ($sameDescription || $sameMerchant) {
                $scores[$category] = ($scores[$category] ?? 0) + ($sameDescription ? 2 : 1);
            }
        }
        arsort($scores, SORT_NUMERIC);
        $result = [];
        foreach ($scores as $id => $score) {
            $result[] = ['id' => (string) $id, 'name' => $available[$id], 'reason' => 'За попередніми подібними платежами'];
            if (3 === count($result)) {
                return $result;
            }
        }
        $type     = $payment['type'] ?? '';
        $mcc      = 'Withdrawal' === $type ? (int) ($payment['mcc'] ?? 0) : 0;
        $mccNames = match ($mcc) {
            5411, 5422, 5441, 5451, 5462, 5499 => ['Продукти'],
            4121                               => ['Таксі', 'Транспорт'],
            5541, 5542                         => ['Пальне', 'Транспорт'],
            5912, 8062                         => ["Здоров'я"],
            5812, 5814                         => ['Кафе та ресторани', 'Доставка їжі'],
            default                            => []
        };
        $fallbackNames = match ($type) {
            'Withdrawal' => ['Покупки', 'Послуги', 'Продукти'],
            'Deposit'    => ['Дохід ФОП', 'Повернення коштів', 'Подарунки', 'Інші доходи'],
            default      => []
        };
        foreach ([$mccNames, $fallbackNames] as $source => $names) {
            foreach ($names as $name) {
                $id = array_search($name, $available, true);
                if (false !== $id && !in_array((string) $id, array_column($result, 'id'), true)) {
                    $result[] = [
                        'id'     => (string) $id,
                        'name'   => $name,
                        'reason' => 0 === $source ? 'За кодом банку — перевір призначення' : 'Можливий варіант'
                    ];
                    if (3 === count($result)) {
                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    public static function review(string $text): null|array
    {
        $review = self::decodeNote($text)['bank_review'] ?? null;
        if (
            !is_array($review)
            || 1 !== ($review['version'] ?? null)
            || 'own_transfer' !== ($review['kind'] ?? null)
            || !in_array($review['status'] ?? '', ['pending', 'decided', 'resolved'], true)
            || !in_array($review['direction'] ?? '', ['in', 'out'], true)
            || !is_string($review['key'] ?? null)
            || '' === $review['key']
            || !(is_string($review['account_id'] ?? null) || is_int($review['account_id'] ?? null))
            || !ctype_digit((string) $review['account_id'])
            || !is_string($review['currency'] ?? null)
            || !preg_match('/^[A-Z]{3,6}$/D', $review['currency'])
        ) {
            return null;
        }

        return $review;
    }

    public static function row($journal, array $accounts): array
    {
        $review       = null;
        $reviewNoteId = null;
        $mcc          = null;
        $reviewError  = null;
        foreach ($journal->notes as $note) {
            $mcc       ??= self::decodeNote($note->text)['mcc'] ?? null;
            $candidate = self::review($note->text);
            if (null === $candidate && str_contains($note->text, '"bank_review"')) {
                $reviewError = 'Не вдалося прочитати дані уточнення. Перевір нотатку платежу.';
            }
            if (null !== $candidate) {
                if (null !== $review) {
                    $reviewError = 'Для платежу знайдено кілька записів уточнення. Потрібна звірка нотаток.';
                }
                $review       = $candidate;
                $reviewNoteId = (string) $note->id;
            }
        }
        $pending        = null !== $review && in_array($review['status'], ['pending', 'decided'], true);
        $type           = $journal->transactionType->type;
        $realCategories = $journal->categories->filter(static fn($category): bool => self::LEGACY_CATEGORY !== $category->name);
        $eligible       = $pending || in_array($type, ['Withdrawal', 'Deposit'], true) && $realCategories->isEmpty();
        $leg            = $journal->transactions->first(static fn($leg): bool => $pending
            ? (string) $leg->account_id === (string) $review['account_id']
            : isset($accounts[(string) $leg->account_id])
                && !$accounts[(string) $leg->account_id]['suspense']
                && (
                    'Transfer' === $type
                    || ('Withdrawal' === $type ? bccomp((string) $leg->amount, '0', 16) < 0 : bccomp((string) $leg->amount, '0', 16) > 0)
                ));
        $other = null === $leg ? null : $journal->transactions->first(static fn($other): bool => (string) $other->account_id !== (string) $leg->account_id);
        if (
            $pending
            && (
                null === $leg
                || !isset($accounts[(string) $leg->account_id])
                || $accounts[(string) $leg->account_id]['suspense']
                || 'Transfer' !== $type
                || $accounts[(string) $leg->account_id]['currency'] !== $review['currency']
                || null === $other
                || !($accounts[(string) $other->account_id]['suspense'] ?? false)
                || ('out' === $review['direction'] ? bccomp((string) $leg->amount, '0', 16) >= 0 : bccomp((string) $leg->amount, '0', 16) <= 0)
            )
        ) {
            $reviewError = 'Рух на уточненні не відповідає банківському рахунку. Потрібна звірка.';
        }
        if ($pending && 'same_asset_cards' === ($review['account_scope'] ?? null)) {
            $reviewError = 'Це переказ між картками, об’єднаними в один рахунок. Для звірки потрібна оновлена виписка картки, з якої надійшли кошти.';
        }
        $value  = null === $leg ? '0' : (string) $leg->amount;
        $amount = str_starts_with($value, '-') ? substr($value, 1) : $value;
        $state  = self::state($journal);

        return [
            'id'             => (string) $journal->id,
            'group_id'       => (string) $journal->transaction_group_id,
            'eligible'       => null !== $reviewError || $eligible && null !== $leg,
            'pending'        => $pending,
            'review'         => $review,
            'review_note_id' => $reviewNoteId,
            'blocked'        => null !== $reviewError,
            'review_error'   => $reviewError,
            'fingerprint'    => self::fingerprint($state),
            'description'    => $journal->description,
            'date'           => $journal->date->copy()->setTimezone('Europe/Kyiv')->format('d.m.Y H:i'),
            'type'           => $pending ? ('in' === $review['direction'] ? 'Deposit' : 'Withdrawal') : $type,
            'currency'       => $journal->transactionCurrency->code,
            'amount'         => $amount,
            'incoming'       => !str_starts_with($value, '-'),
            'account'        => null === $leg ? 'Рахунок не визначено' : $accounts[(string) $leg->account_id]['name'] ?? 'Рахунок недоступний',
            'merchant'       => $pending ? '' : $other?->account?->name ?? '',
            'mcc'            => $mcc,
            'category_id'    => $realCategories->first()?->id
        ];
    }

    public static function save($user, int $journalId, array $input): bool
    {
        return DB::transaction(static function () use ($user, $journalId, $input): bool {
            $journal = self::query($user)->where('id', $journalId)->lockForUpdate()->firstOrFail();
            $journal->setRelation('transactions', $journal->transactions()->with('account')->lockForUpdate()->get());
            $journal->setRelation('notes', $journal->notes()->lockForUpdate()->get());
            DB::table('category_transaction_journal')->where('transaction_journal_id', $journalId)->lockForUpdate()->get();
            $journal->load('categories');
            self::assertFingerprint(self::state($journal), (string) $input['fingerprint']);
            $accounts = self::accounts($user);
            $row      = self::row($journal, $accounts);
            if ($row['blocked']) {
                throw new InvalidArgumentException($row['review_error']);
            }
            if (!$row['eligible']) {
                throw new RuntimeException('Цей платіж уже уточнено. Онови сторінку.');
            }
            $categories = self::categories($user);
            $categoryId = isset($input['category_id']) ? (string) $input['category_id'] : null;
            if (!$row['pending']) {
                if ('category' !== $input['action'] || !isset($categories[$categoryId ?? ''])) {
                    throw new InvalidArgumentException('Обери категорію цього платежу.');
                }
                $journal->categories()->sync([(int) $categoryId]);

                return false;
            }
            $review = self::decide(
                $row['review'],
                (string) $input['action'],
                $categoryId,
                isset($input['account_id']) ? (string) $input['account_id'] : null,
                $categories,
                $accounts
            );
            $note                = $journal->notes->firstWhere('id', $row['review_note_id']);
            $data                = self::decodeNote($note->text);
            $data['bank_review'] = $review;
            $note->text          = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $note->save();

            return true;
        });
    }

    public static function state($journal): array
    {
        return [
            'id'          => (string) $journal->id,
            'group'       => (string) $journal->transaction_group_id,
            'owner'       => (string) $journal->user_group_id,
            'type'        => $journal->transactionType->type,
            'date'        => $journal->date->toIso8601String(),
            'description' => $journal->description,
            'currency'    => (string) $journal->transaction_currency_id,
            'categories'  => $journal->categories->sortBy('id')
                ->pluck('id')
                ->map(static fn($id): string => (string) $id)
                ->values()
                ->all(),
            'notes'       => $journal->notes->sortBy('id')
                ->map(static fn($note): array => ['id' => (string) $note->id, 'text' => $note->text])
                ->values()
                ->all(),
            'meta'        => $journal->transactionJournalMeta->sortBy('id')
                ->map(static fn($meta): array => ['id' => (string) $meta->id, 'name' => $meta->name, 'data' => $meta->data])
                ->values()
                ->all(),
            'legs'        => $journal->transactions->sortBy('id')
                ->map(static fn($leg): array => [
                    'id'                  => (string) $leg->id,
                    'account_id'          => (string) $leg->account_id,
                    'currency_id'         => (string) $leg->transaction_currency_id,
                    'amount'              => (string) $leg->amount,
                    'foreign_currency_id' => (string) $leg->foreign_currency_id,
                    'foreign_amount'      => (string) $leg->foreign_amount
                ])
                ->values()
                ->all()
        ];
    }

    private static function query($user)
    {
        return TransactionJournal::query()
            ->where('user_group_id', $user->user_group_id)
            ->whereHas('transactionGroup', static fn($query) => $query->where('user_group_id', $user->user_group_id))
            ->with(['categories', 'notes', 'transactionType', 'transactionCurrency', 'transactions.account', 'transactionJournalMeta']);
    }
}
