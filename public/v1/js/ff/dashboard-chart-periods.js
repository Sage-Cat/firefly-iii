(function (root) {
    'use strict';

    const charts = Object.freeze(['capital', 'budget', 'category', 'expense', 'revenue']);
    const periods = Object.freeze(['30d', '3m', '1y']);

    function date(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return false;
        }
        const parsed = new Date(value + 'T00:00:00Z');
        return Number.isFinite(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
    }

    function url(chart, base) {
        if (!charts.includes(chart) || typeof base !== 'string' || !base) {
            throw new TypeError('Unknown dashboard chart URL.');
        }
        const range = root.fireflyDashboardChartRanges?.[chart];
        if (!range || !periods.includes(range.period) || !date(range.start) || !date(range.end) || range.start > range.end) {
            throw new TypeError('Invalid dashboard chart period.');
        }
        const target = new URL(base, root.document.baseURI);
        if (!['http:', 'https:'].includes(target.protocol) || target.origin !== root.location.origin || target.username || target.password) {
            throw new TypeError('Dashboard chart requests must remain on the application origin.');
        }
        target.searchParams.set('start', range.start);
        target.searchParams.set('end', range.end);
        target.searchParams.set(chart === 'capital' ? 'capital_period' : 'dashboard_period', range.period);
        return target.href;
    }

    root.fireflyDashboardPeriods = Object.freeze({url});
})(window);
