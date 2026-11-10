@if(!$anonymous && null !== $piggy['target_amount'] && bccomp((string) $piggy['target_amount'], '0', 2) > 0)
    @php
        $controlPiggySaved = bccomp((string) $piggy['current_amount'], '0', 2) < 0 ? '0' : (string) $piggy['current_amount'];
        $controlPiggyPercent = bcdiv(bcmul($controlPiggySaved, '100', 2), (string) $piggy['target_amount'], 0);
        $controlPiggyWidth = bccomp($controlPiggyPercent, '100', 0) > 0 ? '100' : $controlPiggyPercent;
    @endphp
    <div class="small text-muted mb-1">{{ $controlPiggyPercent }}% цілі</div>
    <div class="progress" role="progressbar" aria-label="Накопичено на ціль" aria-valuenow="{{ $controlPiggyWidth }}" aria-valuemin="0" aria-valuemax="100" style="height: .5rem; min-width: 5rem">
        <div class="progress-bar" style="width: {{ $controlPiggyWidth }}%; background-color: #8b7be8"></div>
    </div>
@endif
