<div class="card mb-3">
    <div class="card-body">
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Види операцій">
            @foreach(['all' => 'Усі операції', 'withdrawal' => 'Витрати', 'deposit' => 'Доходи', 'transfers' => 'Власні перекази'] as $recordType => $recordLabel)
                <a class="btn btn-sm {{ $objectType === $recordType || ($objectType === 'transfer' && $recordType === 'transfers') ? 'btn-primary' : 'btn-outline-secondary' }}"
                   @if($objectType === $recordType || ($objectType === 'transfer' && $recordType === 'transfers')) aria-current="page" @endif
                   href="{{ is_countable($periods) && count($periods) > 0 ? route('transactions.index', [$recordType, $start->format('Y-m-d'), $end->format('Y-m-d')]) : route('transactions.index.all', [$recordType]) }}">{{ $recordLabel }}</a>
            @endforeach
        </nav>
        <form method="get" action="{{ route('search.index') }}" class="d-flex flex-wrap align-items-end gap-2">
            <div style="flex: 1 1 16rem; min-width: 0">
                <label for="record-global-search" class="form-label">Пошук у всіх операціях</label>
                <input type="search" id="record-global-search" name="search" class="form-control" placeholder="Отримувач, опис або сума" required>
            </div>
            <button type="submit" class="btn btn-primary">Знайти</button>
        </form>
        <p class="small text-muted mb-0 mt-2">Перемикачі видів зберігають обраний період. Пошук відкриває загальний список результатів.</p>
    </div>
</div>
