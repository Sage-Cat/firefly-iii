<style nonce="{{ $JS_NONCE }}">
    .dashboard-period-chart { position: relative; height: 400px; width: 100%; }
    .dashboard-period-canvas { position: relative; height: 100%; width: 100%; }
    .dashboard-period-canvas > canvas { position: absolute; inset: 0; display: block; width: 100% !important; height: 100% !important; }
    @media (max-width: 767px) { .dashboard-period-chart { height: 280px; } }
</style>
<script nonce="{{ $JS_NONCE }}">
    window.fireflyDashboardChartRanges = @json(\FireflyIII\Support\Reports\DashboardChartPeriods::all());
</script>
<script src="{{ asset('v1/js/ff/dashboard-chart-periods.js') }}?v={{ substr(hash_file('sha256', public_path('v1/js/ff/dashboard-chart-periods.js')), 0, 12) }}" nonce="{{ $JS_NONCE }}" integrity="sha384-{{ base64_encode(hash_file('sha384', public_path('v1/js/ff/dashboard-chart-periods.js'), true)) }}" crossorigin="anonymous"></script>
