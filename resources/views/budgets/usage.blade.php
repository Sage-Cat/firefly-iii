@if(!$anonymous && isset($budget['display_amount'], $budget['display_spent']) && bccomp($budget['display_amount'], '0', 2) > 0)
    @php
        $used = bcsub('0', $budget['display_spent'], 2);
        $used = bccomp($used, '0', 2) < 0 ? '0' : $used;
        $percent = bcdiv(bcmul($used, '100', 2), $budget['display_amount'], 0);
        $width = bccomp($percent, '100', 0) > 0 ? '100' : $percent;
    @endphp
    <div class="budget-usage mt-1" aria-label="Використано {{ $percent }}% бюджету">
        <div class="small text-muted">Використано {{ $percent }}%@if(bccomp($used, $budget['display_amount'], 2) > 0) · перевищено ліміт@endif</div>
        <div class="progress" role="progressbar" aria-label="Використано бюджету" aria-valuenow="{{ $width }}" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: {{ $width }}%"></div>
        </div>
    </div>
@endif
