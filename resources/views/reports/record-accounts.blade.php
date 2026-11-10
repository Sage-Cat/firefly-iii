<nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Види рахунків">
    @foreach(['asset' => 'Мої рахунки', 'expense' => 'Отримувачі витрат', 'revenue' => 'Джерела доходів', 'liabilities' => 'Зобов’язання'] as $recordType => $recordLabel)
        <a class="btn btn-sm {{ $objectType === $recordType ? 'btn-primary' : 'btn-outline-secondary' }}" @if($objectType === $recordType) aria-current="page" @endif href="{{ route('accounts.index', [$recordType]) }}">{{ $recordLabel }}</a>
    @endforeach
</nav>
@include('reports.record-filter', ['recordFilterKey' => 'accounts', 'recordFilterRootSelector' => '[data-record-accounts]', 'recordFilterRowSelector' => 'tbody > tr[data-id]'])
