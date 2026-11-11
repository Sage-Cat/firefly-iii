@php
    $paymentReviewCount = !($anonymous ?? false) && auth()->check()
        ? \FireflyIII\Support\Reports\PaymentReview::counts(auth()->user())['all'] : 0;
@endphp
<style nonce="{{ $JS_NONCE }}">
    .payment-review-nav-link { position: relative; display: flex; align-items: center; gap: .35rem; }
    .payment-review-nav-link > .nav-icon { flex-shrink: 0; }
    .payment-review-nav-link > p { flex: 1 1 auto; min-width: 0; margin-bottom: 0; }
    .payment-review-nav-link.payment-review-attention:not(.active) { background-color: rgba(245, 158, 11, .13); box-shadow: inset 3px 0 #ffb65c; }
    .payment-review-nav-link.payment-review-attention:not(.active):hover { background-color: rgba(245, 158, 11, .22); }
    .payment-review-nav-link.payment-review-attention.active { box-shadow: inset 3px 0 #ffb65c; }
    .payment-review-count { flex: 0 0 auto; background-color: #ffb65c; color: #30200a; white-space: nowrap; font-variant-numeric: tabular-nums; }
    body.sidebar-collapse .app-sidebar:not(:hover) .payment-review-count { position: absolute; top: .1rem; inset-inline-end: .05rem; font-size: .65rem; }
</style>
<li class="nav-item">
    <a href="{{ route('transactions.review') }}" class="nav-link payment-review-nav-link {{ menu_item_active_partial('transactions.review') }} {{ $paymentReviewCount > 0 ? 'payment-review-attention' : '' }}"
       aria-label="Уточнення платежів{{ $paymentReviewCount > 0 ? ': у черзі '.$paymentReviewCount : '' }}">
        <em class="nav-icon bi bi-check2-square" aria-hidden="true"></em>
        <p>Уточнення платежів</p>
        @if($paymentReviewCount > 0)
            <span class="badge rounded-pill payment-review-count" aria-hidden="true">{{ $paymentReviewCount }}</span>
        @endif
    </a>
</li>
