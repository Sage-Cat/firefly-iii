@if(!$anonymous)
    @php
        $controlRenewals = [];
        foreach ($bills as $controlGroup) {
            foreach ($controlGroup['bills'] as $controlBill) {
                if (!$controlBill['active'] || empty($controlBill['next_expected_match'])) { continue; }
                $controlDay = substr($controlBill['next_expected_match'], 0, 10);
                $controlDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $controlDay);
                if (false === $controlDate || $controlDate->format('Y-m-d') !== $controlDay) { continue; }
                $controlRenewals[] = ['id' => $controlBill['id'], 'name' => $controlBill['name'], 'date' => $controlDate];
            }
        }
        usort($controlRenewals, static fn ($left, $right) => $left['date'] <=> $right['date'] ?: strnatcasecmp($left['name'], $right['name']));
    @endphp
    <section class="card mb-3" aria-labelledby="control-renewals-title">
        <div class="card-header"><h2 class="card-title" id="control-renewals-title">Найближчі очікувані платежі</h2></div>
        <div class="card-body">
            @if([] === $controlRenewals)
                <p class="text-muted mb-0">Для активних підписок немає визначених дат наступного платежу.</p>
            @else
                <ul class="list-unstyled mb-0">
                    @foreach(array_slice($controlRenewals, 0, 5) as $controlRenewal)
                        <li class="d-flex flex-wrap justify-content-between gap-2 py-2 border-bottom">
                            <a class="text-break" href="{{ route('subscriptions.show', $controlRenewal['id']) }}">{{ $controlRenewal['name'] }}</a>
                            <time class="text-nowrap" datetime="{{ $controlRenewal['date']->format('Y-m-d') }}">{{ $controlRenewal['date']->format('d.m.Y') }}</time>
                        </li>
                    @endforeach
                </ul>
                <p class="small text-muted mt-2 mb-0">До 5 найближчих дат, визначених Firefly. Це очікування, а не підтвердження списання.</p>
            @endif
        </div>
    </section>
@endif
