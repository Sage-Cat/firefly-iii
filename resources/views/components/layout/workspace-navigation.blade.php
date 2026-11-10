<li class="nav-header text-uppercase">Рахунки й категорії</li>
<li class="nav-item"><a href="{{ route('accounts.index', ['asset']) }}" class="nav-link {{ menu_sub_item_active('accounts.index', 'asset') }}"><em class="nav-icon bi bi-wallet2"></em><p>Мої рахунки</p></a></li>
<li class="nav-item"><a href="{{ route('categories.index') }}" class="nav-link {{ menu_item_active_partial('categories.') }}"><em class="nav-icon bi bi-bookmark"></em><p>Категорії</p></a></li>
<li class="nav-item">
    <details class="workspace-advanced" @if(request()->routeIs('rules.*', 'rule-groups.*', 'recurring.*', 'tags.*', 'object-groups.*', 'webhooks.*') || (request()->routeIs('accounts.index') && request()->route('objectType') !== 'asset')) open @endif>
        <summary class="nav-link"><em class="nav-icon bi bi-sliders"></em> Додатково</summary>
        <ul class="nav flex-column">
<li class="nav-item"><a href="{{ route('rules.index') }}" class="nav-link {{ menu_item_active_partial('rules.') }}"><em class="nav-icon bi bi-shuffle"></em><p>Правила категоризації</p></a></li>
<li class="nav-item"><a href="{{ route('recurring.index') }}" class="nav-link {{ menu_item_active_partial('recurring.') }}"><em class="nav-icon bi bi-arrow-repeat"></em><p>Повторювані операції</p></a></li>
<li class="nav-item"><a href="{{ route('tags.index') }}" class="nav-link {{ menu_item_active_partial('tags.') }}"><em class="nav-icon bi bi-tags"></em><p>Теги</p></a></li>
<li class="nav-item"><a href="{{ route('object-groups.index') }}" class="nav-link {{ menu_item_active_partial('object-groups.') }}"><em class="nav-icon bi bi-collection"></em><p>Групи</p></a></li>
<li class="nav-item"><a href="{{ route('accounts.index', ['expense']) }}" class="nav-link {{ menu_sub_item_active('accounts.index', 'expense') }}"><em class="nav-icon bi bi-cart"></em><p>Одержувачі витрат</p></a></li>
<li class="nav-item"><a href="{{ route('accounts.index', ['revenue']) }}" class="nav-link {{ menu_sub_item_active('accounts.index', 'revenue') }}"><em class="nav-icon bi bi-box-arrow-in-down"></em><p>Джерела доходів</p></a></li>
<li class="nav-item"><a href="{{ route('accounts.index', ['liabilities']) }}" class="nav-link {{ menu_sub_item_active('accounts.index', 'liabilities') }}"><em class="nav-icon bi bi-credit-card"></em><p>Боргові рахунки</p></a></li>
            @if(true === $featuringWebhooks)
                <li class="nav-item"><a href="{{ route('webhooks.index') }}" class="nav-link {{ menu_item_active_partial('webhooks.') }}"><em class="nav-icon bi bi-lightning"></em><p>Вебхуки</p></a></li>
            @endif
        </ul>
    </details>
</li>
