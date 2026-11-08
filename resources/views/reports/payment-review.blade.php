@extends('layout.v3.session')
@section('breadcrumbs')
    <li class="breadcrumb-item active">Уточнення платежів</li>
@endsection

@section('content')
    <style nonce="{{ $JS_NONCE }}">
        #payment-review .payment-description { overflow-wrap: anywhere; }
        #payment-review .payment-choice { max-width: 36rem; }
        #payment-review .payment-amount { white-space: nowrap; }
        #payment-review .payment-recommendation {
            --bs-btn-color: var(--bs-info-text-emphasis);
            --bs-btn-border-color: var(--bs-info-text-emphasis);
            --bs-btn-hover-color: var(--bs-body-bg);
            --bs-btn-hover-bg: var(--bs-info-text-emphasis);
            --bs-btn-active-color: var(--bs-body-bg);
            --bs-btn-active-bg: var(--bs-info-text-emphasis);
        }
    </style>
    <div id="payment-review">
        <div class="card mb-3">
            <div class="card-body">
                <p class="mb-2">Ці платежі вже внесені в облік. Обери категорію або уточни другий рахунок власного переказу.</p>
                <p class="text-muted small mb-3">Рекомендації — підказки, вони не застосовуються автоматично. Платежі без категорії не зупиняють імпорт.</p>
                <div class="d-flex flex-wrap gap-2" aria-label="Тип уточнення">
                    @foreach(['all' => 'Усі', 'category' => 'Без категорії', 'transfer' => 'Власні перекази'] as $key => $label)
                        <a class="btn btn-sm {{ $filter === $key ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('transactions.review', ['filter' => $key]) }}">{{ $label }} <span class="badge text-bg-secondary ms-1">{{ $counts[$key] }}</span></a>
                    @endforeach
                </div>
            </div>
        </div>
        @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
        @forelse($payments as $payment)
            <article class="card mb-3" aria-labelledby="payment-title-{{ $payment['id'] }}">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <div class="text-muted small mb-1">{{ $payment['date'] }} · {{ $payment['account'] }}</div>
                        <h2 id="payment-title-{{ $payment['id'] }}" class="card-title payment-description">{{ $payment['description'] }}</h2>
                    </div>
                    <div class="fw-semibold payment-amount {{ $payment['incoming'] ? 'text-success-emphasis' : 'text-danger-emphasis' }}">{{ $payment['incoming'] ? '+' : '−' }}{{ \FireflyIII\Support\Reports\ProfitSummary::money($payment['amount']) }} {{ $payment['currency'] }}</div>
                </div>
                <div class="card-body">
                    @if($payment['blocked'])
                        <div class="alert alert-warning" role="status">{{ $payment['review_error'] }}</div>
                    @else
                    @if($payment['pending'])
                        <span class="badge text-bg-warning mb-2">Потрібен другий рахунок</span>
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
                            <label class="form-label" for="payment-account-{{ $payment['id'] }}">Власний переказ {{ $payment['review']['direction'] === 'in' ? 'з рахунку' : 'на рахунок' }}</label>
                            <div class="d-flex flex-wrap gap-2">
                                <select class="form-select flex-grow-1 w-auto" id="payment-account-{{ $payment['id'] }}" name="account_id" required>
                                    <option value="">Обери власний рахунок · {{ $payment['currency'] }}</option>
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
                        <span class="badge text-bg-secondary mb-3">Без категорії</span>
                    @endif
                    @if(!$payment['pending'] || $payment['review']['status'] === 'pending')
                    @if(count($payment['recommendations']) > 0)
                        <div class="mb-3">
                            <div class="small text-muted mb-2">Рекомендовані категорії</div>
                            <div class="d-flex flex-wrap gap-2 align-items-start">
                                @foreach($payment['recommendations'] as $recommendation)
                                    <form method="post" action="{{ route('transactions.review.store', ['journalId' => $payment['id']]) }}">
                                        @csrf
                                        <input type="hidden" name="fingerprint" value="{{ $payment['fingerprint'] }}">
                                        <input type="hidden" name="action" value="{{ $payment['pending'] ? 'external' : 'category' }}">
                                        <input type="hidden" name="category_id" value="{{ $recommendation['id'] }}">
                                        <input type="hidden" name="filter" value="{{ $filter }}">
                                        <input type="hidden" name="page" value="{{ $payments->currentPage() }}">
                                        <button type="submit" class="btn btn-outline-info payment-recommendation">{{ $recommendation['name'] }}</button>
                                        <div class="small text-muted mt-1">{{ $recommendation['reason'] }}</div>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <form method="post" action="{{ route('transactions.review.store', ['journalId' => $payment['id']]) }}" class="payment-choice">
                        @csrf
                        <input type="hidden" name="fingerprint" value="{{ $payment['fingerprint'] }}">
                        <input type="hidden" name="action" value="{{ $payment['pending'] ? 'external' : 'category' }}">
                        <input type="hidden" name="filter" value="{{ $filter }}">
                        <input type="hidden" name="page" value="{{ $payments->currentPage() }}">
                        <label class="form-label" for="payment-category-{{ $payment['id'] }}">{{ $payment['pending'] ? 'Категорія зовнішнього платежу' : 'Усі категорії' }}</label>
                        <div class="d-flex flex-wrap gap-2">
                            <select class="form-select flex-grow-1 w-auto" id="payment-category-{{ $payment['id'] }}" name="category_id" required>
                                <option value="">Обери категорію</option>
                                @foreach($categories as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            <button type="submit" class="btn btn-primary">Обрати категорію</button>
                        </div>
                    </form>
                    @endif
                    @endif
                    <a class="btn btn-sm btn-link px-0 mt-3" href="{{ route('transactions.show', [$payment['group_id']]) }}">Переглянути платіж</a>
                </div>
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
