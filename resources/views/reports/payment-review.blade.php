@extends('layout.v3.session')
@section('breadcrumbs')
    <li class="breadcrumb-item active">Уточнення платежів</li>
@endsection

@section('content')
    <style nonce="{{ $JS_NONCE }}">
        #payment-review { max-width: 74rem; margin-inline: auto; }
        #payment-review .payment-intro { border-radius: .75rem; }
        #payment-review .payment-intro-text { max-width: 46rem; color: var(--bs-secondary-color); }
        #payment-review .payment-filter { border-radius: 2rem; }
        #payment-review .payment-card { border-radius: .75rem; overflow: hidden; }
        #payment-review .payment-card-header {
            display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 1rem;
            align-items: center; padding: 1.1rem 1.25rem; background: var(--bs-tertiary-bg);
        }
        #payment-review .payment-meta { display: flex; flex-wrap: wrap; gap: .25rem .75rem; font-size: .8rem; color: var(--bs-secondary-color); }
        #payment-review .payment-account { overflow-wrap: anywhere; }
        #payment-review .payment-description { overflow-wrap: anywhere; }
        #payment-review .payment-card-title { float: none; margin: .35rem 0 0; font-size: 1.15rem; font-weight: 600; line-height: 1.4; }
        #payment-review .payment-amount-block { text-align: end; }
        #payment-review .payment-amount-label { font-size: .75rem; color: var(--bs-secondary-color); margin-bottom: .15rem; }
        #payment-review .payment-amount { white-space: nowrap; font-size: 1.35rem; font-variant-numeric: tabular-nums; }
        #payment-review .payment-card-body { padding: 1.1rem 1.25rem; }
        #payment-review .payment-status { font-size: .75rem; font-weight: 500; padding: .35rem .6rem; border-radius: 2rem; }
        #payment-review .payment-category-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(18rem, .85fr); gap: 1.5rem; align-items: start; }
        #payment-review .payment-category-layout.no-recommendations { grid-template-columns: minmax(0, 36rem); }
        #payment-review .payment-section-title { display: block; font-size: .875rem; font-weight: 600; margin: 0 0 .6rem; }
        #payment-review .payment-hint { font-size: .8rem; color: var(--bs-secondary-color); margin: -.2rem 0 .65rem; }
        #payment-review .payment-recommendations { display: flex; flex-wrap: wrap; gap: .5rem; }
        #payment-review .payment-recommendations form { margin: 0; max-width: 100%; }
        #payment-review .payment-recommendation {
            --bs-btn-color: var(--bs-info-text-emphasis);
            --bs-btn-bg: var(--bs-info-bg-subtle);
            --bs-btn-border-color: var(--bs-info-border-subtle);
            --bs-btn-hover-color: var(--bs-info-text-emphasis);
            --bs-btn-hover-bg: var(--bs-secondary-bg);
            --bs-btn-hover-border-color: var(--bs-info-text-emphasis);
            --bs-btn-active-color: var(--bs-info-text-emphasis);
            --bs-btn-active-bg: var(--bs-info-bg-subtle);
            display: flex; align-items: center; gap: .75rem; min-height: 2.75rem;
            padding: .55rem .8rem; border-radius: .6rem; text-align: start; white-space: normal;
        }
        #payment-review .payment-recommendation-name { overflow-wrap: anywhere; }
        #payment-review .payment-recommendation-reason { display: block; font-size: .75rem; font-weight: 400; color: var(--bs-secondary-color); margin-top: .2rem; }
        #payment-review .payment-recommendation:focus-visible { outline: 3px solid var(--bs-info); outline-offset: 3px; box-shadow: none; }
        #payment-review .payment-choice { max-width: 36rem; min-width: 0; }
        #payment-review .payment-select-row { display: flex; gap: .5rem; }
        #payment-review .payment-select-row select { flex: 1 1 0; min-width: 0; min-height: 2.75rem; }
        #payment-review .payment-select-row button { flex: 0 0 auto; min-height: 2.75rem; }
        #payment-review .payment-footer { padding: .65rem 1.25rem; border-top: 1px solid var(--bs-border-color); }
        #payment-review .payment-details { display: inline-flex; gap: .4rem; align-items: center; font-size: .8rem; text-decoration: none; }
        #payment-review .payment-details:hover { text-decoration: underline; }
        @media (max-width: 991px) {
            #payment-review .payment-category-layout { grid-template-columns: minmax(0, 1fr); gap: 1.25rem; }
            #payment-review .payment-choice { max-width: none; }
        }
        @media (max-width: 575px) {
            #payment-review .payment-card-header { grid-template-columns: minmax(0, 1fr); gap: .85rem; padding: 1rem; }
            #payment-review .payment-amount-block { display: flex; align-items: center; justify-content: space-between; gap: .5rem; text-align: start; }
            #payment-review .payment-amount-label { margin: 0; }
            #payment-review .payment-card-body, #payment-review .payment-footer { padding: 1rem; }
        }
        @media (max-width: 420px) {
            #payment-review .payment-select-row { flex-direction: column; }
        }
    </style>
    <div id="payment-review">
        <div class="card payment-intro mb-3">
            <div class="card-body">
                <p class="payment-intro-text mb-3">Ці платежі вже в обліку. Обери категорію або вкажи другий рахунок власного переказу.</p>
                <nav class="d-flex flex-wrap gap-2" aria-label="Тип уточнення">
                    @foreach(['all' => 'Усі', 'category' => 'Уточнення категорії', 'transfer' => 'Власні перекази'] as $key => $label)
                        <a class="btn btn-sm payment-filter {{ $filter === $key ? 'btn-primary' : 'btn-outline-secondary' }}" @if($filter === $key) aria-current="page" @endif href="{{ route('transactions.review', ['filter' => $key]) }}">{{ $label }} <span class="badge text-bg-secondary ms-1">{{ $counts[$key] }}</span></a>
                    @endforeach
                </nav>
            </div>
        </div>
        @include('reports.record-review')
        @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
        @forelse($payments as $payment)
            <article class="card payment-card mb-3" aria-labelledby="payment-title-{{ $payment['id'] }}">
                <div class="card-header payment-card-header">
                    <div>
                        <div class="payment-meta"><span>{{ $payment['date'] }}</span><span class="payment-account">{{ $payment['account'] }}</span></div>
                        <h2 id="payment-title-{{ $payment['id'] }}" class="payment-card-title payment-description">{{ $payment['description'] }}</h2>
                    </div>
                    <div class="payment-amount-block">
                        <div class="payment-amount-label">{{ $payment['incoming'] ? 'Надходження' : 'Витрата' }}</div>
                        <div class="fw-semibold payment-amount {{ $payment['incoming'] ? 'text-success-emphasis' : 'text-danger-emphasis' }}">{{ $payment['incoming'] ? '+' : '−' }}{{ \FireflyIII\Support\Reports\ProfitSummary::money($payment['amount']) }} {{ \FireflyIII\Support\Reports\CurrencyDisplay::symbol($payment['currency']) }}</div>
                    </div>
                </div>
                <div class="card-body payment-card-body">
                    @if($payment['blocked'])
                        <div class="alert alert-warning" role="status">{{ $payment['review_error'] }}</div>
                    @else
                    @if($payment['pending'])
                        <span class="badge text-bg-warning payment-status mb-2">Потрібен другий рахунок</span>
                        <p class="text-muted small">Рух імпортовано як можливий власний переказ. Поки не визначено другий рахунок, це не підтверджений дохід чи витрата.</p>
                        @if(($payment['review']['resolution_issue'] ?? '') === 'paired_pending')
                            <div class="alert alert-warning" role="status">Обидва банківські рухи вже імпортовані. Для їх об’єднання потрібна окрема звірка; повторна синхронізація не створить дубліката.</div>
                        @elseif($payment['review']['status'] === 'decided')
                            <div class="alert alert-info" role="status">
                                Рішення збережено:
                                @if(($payment['review']['decision']['kind'] ?? '') === 'transfer')
                                    переказ {{ $payment['review']['direction'] === 'in' ? 'з рахунку' : 'на рахунок' }} «{{ $accounts[$payment['review']['decision']['account_id']]['name'] ?? 'вибраний рахунок' }}».
                                @else
                                    зовнішній платіж, категорія «{{ $categories[$payment['review']['decision']['category_id'] ?? ''] ?? 'вибрана категорія' }}».
                                @endif
                                Його застосує наступна синхронізація банку.
                            </div>
                        @endif
                        @if($payment['review']['status'] === 'pending')
                        <form method="post" action="{{ route('transactions.review.store', ['journalId' => $payment['id']]) }}" class="payment-choice mb-4">
                            @csrf
                            <input type="hidden" name="fingerprint" value="{{ $payment['fingerprint'] }}">
                            <input type="hidden" name="action" value="transfer">
                            <input type="hidden" name="filter" value="{{ $filter }}">
                            <input type="hidden" name="page" value="{{ $payments->currentPage() }}">
                            <label class="payment-section-title" for="payment-account-{{ $payment['id'] }}">Власний переказ {{ $payment['review']['direction'] === 'in' ? 'з рахунку' : 'на рахунок' }}</label>
                            <div class="payment-select-row">
                                <select class="form-select flex-grow-1 w-auto" id="payment-account-{{ $payment['id'] }}" name="account_id" required>
                                    <option value="">Обери власний рахунок · {{ \FireflyIII\Support\Reports\CurrencyDisplay::symbol($payment['currency']) }}</option>
                                    @foreach($accounts as $account)
                                        @if($account['active'] && !$account['suspense'] && $account['currency'] === $payment['currency'] && $account['id'] !== (string) $payment['review']['account_id'])
                                            <option value="{{ $account['id'] }}">{{ $account['name'] }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-primary">Обрати рахунок</button>
                            </div>
                        </form>
                        <h3 class="fs-6">Якщо це {{ $payment['incoming'] ? 'дохід від іншої особи' : 'витрата іншій особі' }}</h3>
                        @endif
                    @else
                        <span class="badge text-bg-secondary payment-status mb-3">{{ $payment['category_pending'] ? 'Інше · потребує уточнення' : 'Без категорії' }}</span>
                    @endif
                    @if(!$payment['pending'] || $payment['review']['status'] === 'pending')
                    <div class="payment-category-layout {{ count($payment['recommendations']) === 0 ? 'no-recommendations' : '' }}">
                    @if(count($payment['recommendations']) > 0)
                        @php($sharedReasons = collect($payment['recommendations'])->pluck('reason')->unique()->values())
                        <section aria-labelledby="recommendations-title-{{ $payment['id'] }}">
                            <h3 class="payment-section-title" id="recommendations-title-{{ $payment['id'] }}">Рекомендовані категорії</h3>
                            @if($sharedReasons->count() === 1)<p class="payment-hint" id="recommendations-hint-{{ $payment['id'] }}">{{ $sharedReasons->first() }}</p>@endif
                            <div class="payment-recommendations">
                                @foreach($payment['recommendations'] as $recommendation)
                                    <form method="post" action="{{ route('transactions.review.store', ['journalId' => $payment['id']]) }}">
                                        @csrf
                                        <input type="hidden" name="fingerprint" value="{{ $payment['fingerprint'] }}">
                                        <input type="hidden" name="action" value="{{ $payment['pending'] ? 'external' : 'category' }}">
                                        <input type="hidden" name="category_id" value="{{ $recommendation['id'] }}">
                                        <input type="hidden" name="filter" value="{{ $filter }}">
                                        <input type="hidden" name="page" value="{{ $payments->currentPage() }}">
                                        <button type="submit" class="btn payment-recommendation" aria-label="Призначити категорію «{{ $recommendation['name'] }}»" aria-describedby="{{ $sharedReasons->count() === 1 ? 'recommendations-hint-'.$payment['id'] : 'recommendation-reason-'.$payment['id'].'-'.$recommendation['id'] }}">
                                            <span class="payment-recommendation-name">{{ $recommendation['name'] }}@if($sharedReasons->count() > 1)<span class="payment-recommendation-reason" id="recommendation-reason-{{ $payment['id'] }}-{{ $recommendation['id'] }}">{{ $recommendation['reason'] }}</span>@endif</span>
                                            <span aria-hidden="true">→</span>
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        </section>
                    @endif
                    <form method="post" action="{{ route('transactions.review.store', ['journalId' => $payment['id']]) }}" class="payment-choice">
                        @csrf
                        <input type="hidden" name="fingerprint" value="{{ $payment['fingerprint'] }}">
                        <input type="hidden" name="action" value="{{ $payment['pending'] ? 'external' : 'category' }}">
                        <input type="hidden" name="filter" value="{{ $filter }}">
                        <input type="hidden" name="page" value="{{ $payments->currentPage() }}">
                        <label class="payment-section-title" for="payment-category-{{ $payment['id'] }}">{{ $payment['pending'] ? 'Категорія зовнішнього платежу' : (count($payment['recommendations']) > 0 ? 'Інша категорія' : 'Категорія платежу') }}</label>
                        <div class="payment-select-row">
                            <select class="form-select flex-grow-1 w-auto" id="payment-category-{{ $payment['id'] }}" name="category_id" required>
                                <option value="">Обери категорію</option>
                                @foreach($categories as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            <button type="submit" class="btn btn-primary">Застосувати</button>
                        </div>
                    </form>
                    </div>
                    @endif
                    @endif
                </div>
                <div class="payment-footer"><a class="payment-details" href="{{ route('transactions.show', [$payment['group_id']]) }}" aria-label="Деталі платежу «{{ $payment['description'] }}»">Деталі платежу <span aria-hidden="true">→</span></a></div>
            </article>
        @empty
            <div class="card"><div class="card-body text-center py-5">
                <em class="bi bi-check2-circle fs-1 text-success" aria-hidden="true"></em>
                <p class="mt-2 mb-0">Платежів для уточнення в цьому списку немає.</p>
            </div></div>
        @endforelse
        <div class="mt-3">{{ $payments->links() }}</div>
    </div>
@endsection

@section('scripts')
    @vite(['js/pages/generic.js'])
@endsection
