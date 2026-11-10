@if(!$nbuTodayError)
<div class="card mb-3" id="record-currency-calculator">
    <div class="card-header"><h3 class="card-title">Калькулятор за курсом НБУ</h3></div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-4"><label class="form-label" for="record-currency-amount">Сума</label><input id="record-currency-amount" class="form-control" type="text" inputmode="decimal" autocomplete="off" value="100" maxlength="24" aria-describedby="record-currency-help"></div>
            <div class="col-md-4"><label class="form-label" for="record-currency-from">З валюти</label><select id="record-currency-from" class="form-select">@foreach(['EUR' => '€ · Євро', 'UAH' => '₴ · Гривня', 'USD' => '$ · Долар США', 'CHF' => '₣ · Швейцарський франк', 'USDT' => '₮ · USDT'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label" for="record-currency-to">У валюту</label><select id="record-currency-to" class="form-select">@foreach(['UAH' => '₴ · Гривня', 'EUR' => '€ · Євро', 'USD' => '$ · Долар США', 'CHF' => '₣ · Швейцарський франк', 'USDT' => '₮ · USDT'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div>
        </div>
        <output class="d-block fs-3 mt-3" id="record-currency-result" aria-live="polite"></output>
        <p class="small text-muted mt-2 mb-0" id="record-currency-help">Оцінка на {{ \FireflyIII\Support\Reports\ReportingCurrency::today()->format('d.m.Y') }}. Перерахунок через ₴; для USDT використано курс долара США. Банківський курс і комісії можуть відрізнятися.</p>
    </div>
</div>
<style nonce="{{ $JS_NONCE }}">#record-currency-result { overflow-wrap: anywhere; }</style>
<script nonce="{{ $JS_NONCE }}">
(() => {
    const quotes = @json($nbuTodayQuotes);
    const symbols = {UAH: '₴', EUR: '€', USD: '$', CHF: '₣', USDT: '₮'};
    const amount = document.getElementById('record-currency-amount');
    const source = document.getElementById('record-currency-from'), target = document.getElementById('record-currency-to');
    const result = document.getElementById('record-currency-result');
    const fraction = text => {
        const [whole, decimal = ''] = String(text).replace(',', '.').split('.');
        if (!/^\d+$/.test(whole) || !/^\d*$/.test(decimal)) throw new Error('Invalid decimal');
        return {n: BigInt(whole + decimal), d: 10n ** BigInt(decimal.length)};
    };
    const rate = code => fraction(code === 'UAH' ? '1' : quotes[code === 'USDT' ? 'USD' : code]);
    const convert = () => {
        const input = amount.value.trim();
        if (!/^\d{1,15}(?:[.,]\d{1,8})?$/.test(input)) {
            amount.setAttribute('aria-invalid', 'true');
            result.textContent = 'Введи невід’ємну суму: до 15 цифр і 8 знаків після коми.';
            return;
        }
        try {
            const value = fraction(input), from = rate(source.value), to = rate(target.value);
            if (from.n <= 0n || to.n <= 0n) throw new Error('Invalid quote');
            const numerator = value.n * from.n * to.d * 100n, denominator = value.d * from.d * to.n;
            const cents = (numerator * 2n + denominator) / (denominator * 2n);
            const digits = cents.toString().padStart(3, '0');
            const whole = digits.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            result.textContent = whole + ',' + digits.slice(-2) + ' ' + symbols[target.value];
            amount.removeAttribute('aria-invalid');
        } catch (_error) {
            result.textContent = 'Перерахунок недоступний: перевір курси НБУ.';
        }
    };
    for (const field of [amount, source, target]) field.addEventListener('input', convert);
    convert();
})();
</script>
@endif
