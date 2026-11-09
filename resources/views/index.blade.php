@extends('layout.v3.session')
@section('content')
    <!-- TODO internals modal voor pagina settings -->
    <!-- TODO wizard modal voor weet ik veel -->
    <x-dashboard.boxes :start="$start" :end="$end"/>

    <div class="row" x-data="index">
        <div class="col-lg-8 col-md-12 col-sm-12">
            <!--ACCOUNTS -->
            <div class="card mb-2 card-outline">
                <div class="card-header">
                    <div class="card-title">Чисті активи · EUR
                    </div>
                </div>
                <div class="card-body">
                    <div>
                        @include('reports.dashboard-capital-note')
                        <div id="dashboard-capital-chart">
                            <canvas id="accounts-chart" role="img" aria-label="Історія чистих активів у євро"></canvas>
                        </div>
                    </div>
                </div>
                <div class="d-print-none card-footer text-end">
                    <a href="{{ route('accounts.index',['asset']) }}" class="btn btn-primary btn-sm"><span
                            class="bi bi-cash"></span> {{ __('firefly.go_to_asset_accounts') }}</a>
                </div>
            </div>

            <!--BUDGETS -->
            <div class="card mb-2 card-outline">
                <div class="card-header ">
                    <div class="card-title"><a href="{{ route('budgets.index') }}"
                                               title="{{ __('firefly.budgetsAndSpending') }}">{{ __('firefly.budgetsAndSpending') }}</a>
                    </div>
                </div>
                <div class="card-body">
                    <div>
                        <canvas id="budgets-chart" class="wide-chart" height="400" width="100%"></canvas>
                    </div>
                    <!--
                    <div>
                        <canvas id="budgets-two-chart" class="wide-chart" height="400" width="100%"></canvas>
                    </div>
                    -->
                </div>
                <div class="card-footer text-end d-print-none">
                    <a href="{{ route('budgets.index') }}" class="btn btn-primary btn-sm">
                        <span class="bi bi-pie-chart"></span>
                        <span>{{ __('firefly.go_to_budgets') }}</span>
                    </a>
                </div>
            </div>
            <!--CATEGORIES -->
            <div class="card mb-2 card-outline">
                <div class="card-header">
                    <div class="card-title"><a href="{{ route('categories.index') }}"
                                               title="{{ __('firefly.categories') }}">{{ __('firefly.categories') }}</a>
                    </div>

                </div>
                <div class="card-body">
                    <canvas id="categories-chart" class="wide-chart" height="400" width="100%"></canvas>
                </div>
                <div class="card-footer text-end d-print-none">
                    <a href="{{ route('categories.index') }}" class="btn btn-primary btn-sm">
                        <span class="bi bi-bookmark"></span>
                        <span>{{ __('firefly.go_to_categories') }}</span>
                    </a>
                </div>
            </div>
            <!--EXPENSE ACCOUNTS -->
            <div class="card mb-2">
                <div class="card-header">
                    <div class="card-title"><a href="{{ route('accounts.index',['expense']) }}"
                                               title="{{ __('firefly.expense_accounts') }}">{{ __('firefly.expense_accounts') }}</a>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="expense-accounts-chart" class="wide-chart" height="400"
                            width="100%"></canvas>
                </div>
                <div class="card-footer text-end d-print-none">
                    <a href="{{ route('accounts.index', ['expense']) }}" class="btn btn-primary btn-sm"><span
                            class="bi bi-cart"></span> {{ __('firefly.go_to_expense_accounts') }}</a>
                </div>
            </div>
            <!--OPTIONAL REVENUE ACCOUNTS -->
            <div class="card mb-2">
                <div class="card-header">
                    <div class="card-title"><a href="{{ route('accounts.index',['revenue']) }}"
                                               title="{{ __('firefly.revenue_accounts') }}">{{ __('firefly.revenue_accounts') }}</a>
                    </div>

                </div>
                <div class="card-body">
                    <canvas id="revenue-accounts-chart" class="wide-chart" height="400"
                            width="100%"></canvas>
                </div>
                <div class="card-footer text-end d-print-none">
                    <a href="{{ route('accounts.index', ['revenue']) }}" class="btn btn-primary btn-sm"><span
                            class="bi bi-box-arrow-down"></span> {{ __('firefly.go_to_revenue_accounts') }}</a>
                </div>
            </div>

        </div>
        <div class="col-lg-4 col-md-12 col-sm-12" id="all_transactions">
            <!--TRANSACTIONS -->
            @foreach($transactions as $data)
                <div class="card card-outline mb-2">
                    <div class="card-header">
                        <div class="card-title"><a
                                href="{{ route('accounts.show', [$data['account']->id]) }}">{{ $data['account']->name }}</a>
                        </div>
                    </div>
                    @if(count($data['transactions']) > 0)
                        <div class="card-body p-0">
                            <x-lists.groups-tiny :account="$data['account']" :transactions="$data['transactions']"/>
                        </div>
                    @endif
                    @if(0 === count($data['transactions']))
                        <div class="card-body">
                            <p>
                                <em>
                                    {{ __('firefly.no_transactions_account', ['name' => $data['account']['name']]) }}
                                </em>
                            </p>

                        </div>
                    @endif
                    <div class="card-footer d-print-none">
                        <div class="row">
                            <div class="col-6">
                                <button type="button" class="btn btn-primary btn-sm dropdown-toggle"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false">{{ __('firefly.sidebar_frontpage_create') }}</button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item"
                                           href="{{ route('transactions.create', ['withdrawal']) }}?source={{ $data['account']['id'] }}">{{ __('firefly.create_new_withdrawal') }}</a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item"
                                           href="{{ route('transactions.create', ['deposit']) }}?destination={{ $data['account']['id'] }}">{{ __('firefly.create_new_deposit') }}</a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item"
                                           href="{{ route('transactions.create', ['transfer']) }}?source={{ $data['account']['id'] }}">{{ __('firefly.create_new_transfer') }}</a>
                                    </li>
                                </ul>
                            </div>
                            <div class="col-6 text-end">
                                <button type="button" class="btn btn-primary btn-sm dropdown-toggle"
                                        data-bs-toggle="dropdown" aria-expanded="false">
                                    {{ account_balance($data['account']) }}
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item"
                                           href="{{ route('accounts.show', [$data['account']['id']]) }}">{{ __('firefly.show') }}</a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item"
                                           href="{{ route('accounts.reconcile', [$data['account']['id']]) }}">{{ __('firefly.reconcile') }}</a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item"
                                           href="{{ route('accounts.edit', [$data['account']['id']]) }}?_from={{ urlencode($FF3_FROM) }}">{{ __('firefly.edit') }}</a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item"
                                           href="{{ route('accounts.delete', [$data['account']['id']]) }}">{{ __('firefly.delete') }}</a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach

        </div>
    </div>

@endsection
@section('scripts')
    @vite(['js/pages/dashboard/dashboard.js'])

     <script nonce="{{ $JS_NONCE }}">
        var lineColor = 'red';
        var lineTextColor = '#000';
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            lineColor = '#a00';
            lineTextColor = '#bec5cb';
        }
        var billCount = 0;
        var accountRevenueUrl = '{{ route('chart.account.revenue') }}';
        var accountExpenseUrl = '{{ route('chart.account.expense') }}';
    </script>

     <script src="v1/js/lib/Chart.bundle.min.js?v={{ $FF_BUILD_TIME }}"
            nonce="{{ $JS_NONCE }}"></script>
     <script src="v1/js/lib/chartjs-plugin-annotation.min.js?v={{ $FF_BUILD_TIME }}"
            nonce="{{ $JS_NONCE }}"></script>
     <script src="v1/js/ff/charts.defaults.js?v={{ $FF_BUILD_TIME }}"
            nonce="{{ $JS_NONCE }}"></script>
     <script src="v1/js/ff/charts.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
     <script src="v1/js/ff/index.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>

@endsection
