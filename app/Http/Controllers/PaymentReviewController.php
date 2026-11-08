<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Reports\PaymentReview;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

final class PaymentReviewController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['page' => 'sometimes|integer|min:1', 'filter' => 'sometimes|in:all,category,transfer']);

        return view('reports.payment-review', [
            'title'         => 'Уточнення платежів',
            'mainTitleIcon' => 'bi-check2-square',
            'subTitle'      => 'Категорії та власні перекази',
            ...PaymentReview::page(auth()->user(), (int) ($input['page'] ?? 1), $input['filter'] ?? 'all')
        ]);
    }

    public function store(Request $request, int $journalId): RedirectResponse
    {
        abort_if([] !== array_diff(array_keys($request->except('_token')), [
            'fingerprint',
            'action',
            'category_id',
            'account_id',
            'page',
            'filter'
        ]), 422, 'Форма містить непідтримувані поля.');
        $input = $request->validate([
            'fingerprint' => 'required|string|regex:/^[0-9a-f]{64}$/D',
            'action'      => 'required|in:category,transfer,external',
            'category_id' => 'nullable|required_if:action,category,external|integer|min:1',
            'account_id'  => 'nullable|required_if:action,transfer|integer|min:1',
            'page'        => 'sometimes|integer|min:1',
            'filter'      => 'sometimes|in:all,category,transfer'
        ]);
        try {
            $queued = PaymentReview::save(auth()->user(), $journalId, $input);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
        Preferences::mark();

        return redirect()
            ->route('transactions.review', ['filter' => $input['filter'] ?? 'all', 'page' => $input['page'] ?? 1])
            ->with('success', $queued ? 'Рішення збережено. Його застосує наступна синхронізація банку.' : 'Категорію збережено.');
    }
}
