# Reporting regression checks

These standalone checks exercise mixed currency projections with synthetic
fixtures. Run each PHP file in its own process after installing the root
Composer dependencies:

```sh
for file in tests/custom/*.php; do php "$file" || exit; done
node tests/custom/dashboard_boxes.mjs
```

After installing the npm dependencies, check the responsive chart with Node 24+
and Google Chrome:

```sh
node tests/custom/dashboard_layout.cjs
```

This uses the dashboard Blade markup and installed Chart.js in a fresh headless
browser with synthetic data. It checks stable height through desktop, tablet,
mobile and high-DPI resizing. Screenshots and measurements go to the ignored
`storage/framework/testing/chart-layout` directory; no Firefly login is needed.
Use `--chrome /path/to/chrome` for another Chrome installation.

The checks cover reporting request boundaries, dated conversion, expense
and budget presentation, profit periods, per-currency dashboard cards, and
daily net worth valuation. The dashboard JavaScript check executes the
native source module without network or DOM access. PHP fixtures replace
selected collaborators and do not require a database or user state.

The reporting profile presents expenses and budgets in UAH, profit in the
selected UAH or EUR currency, and the net-worth card and daily history in EUR.
The other dashboard cards retain their original currencies. Net worth uses
the report-day NBU UAH bridge and rounds the combined portfolio once; tokens
without an NBU quote retain the stored manual valuation.
The monthly table covers the current calendar month through today and the
previous two months; the weekly table covers a rolling three-month window.
Both remain independent of chart dates and stack in the right-hand column.
Original journal amounts and stored currency fields remain authoritative.
The dashboard net worth endpoint is selected with `net_worth_eur=1`.

Build the frontend with the root lockfile and the v3 workspace:

```sh
npm ci
npm run build --workspace=resources/assets/v3
```

Generated `public/build` files remain ignored by Git.
