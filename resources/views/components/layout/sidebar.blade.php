<ul
    class="nav sidebar-menu flex-column"
    data-lte-toggle="treeview"
    role="navigation"
    aria-label="{{ __('interface.main_navigation') }}"
    data-accordion="false"
    id="navigation"
    x-data="sidebar"
>
    <li class="nav-item search-xl">
        <form action="{{ route('search.index') }}" method="get" class="pb-2">
            <div class="form-row align-items-center">
                <div class="col-auto">
                    <div class="input-group mb-2">
                    <label class="sr-only" for="searchInput">{{ trans('firefly.searchPlaceholder') }}</label>
                    <input autocomplete="off" type="text" id="searchInput" name="search" class="form-control" placeholder="{{ trans('firefly.searchPlaceholder') }}" value="{{ $query ?? '' }}" spellcheck="false">
                        <div class="input-group-append">
                            <button type='submit' name='go' id='search-btn' class="btn btn-outline-secondary"><span class="bi bi-search"></span></button>
                        </div>
                </div>
            </div>
            </div>
        </form>
    </li>
    <li class="nav-item search-xs" style="display:none;">
        <a href="{{ route('search.index') }}" class="nav-link {{ \FireflyIII\Support\Blade\Navigation::menuItemActive('search.index') }}">
            <em class="nav-icon bi bi-search"></em>
            <p>{{ __('firefly.search') }}</p>
        </a>
    </li>
    <li class="nav-item">
        <a href="{{ route('index') }}" class="nav-link {{ \FireflyIII\Support\Blade\Navigation::menuItemActive('index') }}">
            <em class="nav-icon bi bi-speedometer"></em>
            <p>{{ __('firefly.dashboard') }}</p>
        </a>
    </li>
    <li class="nav-header text-uppercase">{{ __('firefly.financial_control') }}</li>
    <li class="nav-item">
        <a href="{{ route('budgets.index') }}" class="nav-link {{ menu_item_active_partial('budgets.') }}">
            <em class="nav-icon bi bi-pie-chart"></em>
            <p>
                {{ __('firefly.budgets') }}
            </p>
        </a>
    </li>
    <li class="nav-item">
        <a href="{{ route('subscriptions.index') }}" class="nav-link {{ menu_item_active_partial('subscriptions.') }}">
            <em class="nav-icon bi bi-calendar"></em>
            <p>
                {{ __('firefly.subscriptions') }}
            </p>
        </a>
    </li>
    <li class="nav-item">
        <a href="{{ route('piggy-banks.index') }}" class="nav-link {{ menu_item_active_partial('piggy-banks.') }}">
            <em class="nav-icon bi bi-bullseye"></em>
            <p>
                {{ __('firefly.piggyBanks') }}
            </p>
        </a>
    </li>
    <li class="nav-header text-uppercase">{{ __('firefly.accounting') }}</li>

    <li class="nav-item {{ menu_open_partial('transactions.') }}">
        <a href="#" class="nav-link">
            <em class="nav-icon bi bi-wallet"></em>
            <p>
                {{ __('firefly.transactions') }}
                <em class="nav-arrow bi bi-chevron-right"></em>
            </p>
        </a>
        <ul class="nav nav-treeview">
            <li class="nav-item sub-nav-item">
                <a href="{{ route('transactions.index', ['withdrawal']) }}" class="nav-link {{ menu_sub_item_active('transactions.index','withdrawal') }}">
                    <em class="nav-icon bi bi-arrow-left"></em>
                    <p>{{ __('firefly.expenses') }}</p>
                </a>
            </li>
            <li class="nav-item sub-nav-item">
                <a href="{{ route('transactions.index', ['deposit']) }}" class="nav-link {{ menu_sub_item_active('transactions.index','deposit') }}">
                    <em class="nav-icon bi bi-arrow-right"></em>
                    <p>{{ __('firefly.income') }}</p>
                </a>
            </li>
            <li class="nav-item sub-nav-item">
                <a href="{{ route('transactions.index', ['transfers']) }}" class="nav-link {{ menu_sub_item_active('transactions.index','transfers') }}">
                    <em class="nav-icon bi bi-arrow-left-right"></em>
                    <p>{{ __('firefly.transfers') }}</p>
                </a>
            </li>
            <li class="nav-item sub-nav-item">
                <a href="{{ route('transactions.index', ['all']) }}" class="nav-link {{ menu_sub_item_active('transactions.index','all') }}">
                    <em class="nav-icon bi bi-arrow-repeat"></em>
                    <p>{{ __('firefly.all_transactions') }}</p>
                </a>
            </li>
        </ul>
    </li>
    @include('components.layout.workspace-navigation')
    <li class="nav-header text-uppercase">{{ __('firefly.others') }}</li>

    @if(true === $featuringCer)
    <li class="nav-item">
        <a href="{{ route('exchange-rates.index') }}" class="nav-link {{ menu_item_active_partial('exchange-rates.') }}">
            <em class="nav-icon bi bi-currency-exchange"></em>
            <p>Курси НБУ</p>
        </a>
    </li>
    @endif
    @if(false === $featuringCer)
        <li class="nav-item">
            <span class="nav-link {{ menu_item_active_partial('exchange-rates.') }}">
                <em class="nav-icon bi bi-currency-exchange"></em>
                <p>Курси НБУ ({{ trans('firefly.cer_menu_disabled') }})</p>
            </span>
        </li>
    @endif

    @include('reports.profit-sidebar')
    @include('reports.payment-review-sidebar')
    <li class="nav-item">
        <a href="{{ route('export.index') }}" class="nav-link {{ menu_item_active_partial('export.') }}">
            <em class="nav-icon bi bi-upload"></em>
            <p>{{ __('firefly.export_data_menu') }}</p>
        </a>
    </li>

    @if('web' === $authGuard)
        <li class="nav-item">
            <a href="{{ route('logout') }}" @click="logoutUser" class="nav-link logout-link">
                <em class="nav-icon bi bi-person money-negative"></em>
                <p>{{ __('firefly.logout') }}</p>
            </a>
        </li>
    @endif
    @if('remote_user_guard' === $authGuard && '' !== $logoutUrl)
        <li class="nav-item">
            <a href="{{ $logoutUrl }}" class="nav-link logout-link">
                <em class="nav-icon bi bi-person money-negative"></em>
                <p>{{ __('firefly.logout') }}</p>
            </a>
        </li>
    @endif
</ul>
