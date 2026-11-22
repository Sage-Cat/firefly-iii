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
and budget presentation, profit periods, per-currency dashboard cards,
daily net worth valuation, and investment positions. The dashboard JavaScript check executes the
native source module without network or DOM access. PHP fixtures replace
selected collaborators and do not require a database or user state.

The reporting profile presents expenses, revenue accounts and budgets in UAH,
profit in the selected UAH or EUR currency, and the net-worth card and daily
history in EUR.
The other dashboard cards retain their original currencies. Net worth uses
the report-day NBU UAH bridge and rounds the combined portfolio once.
USDT follows the explicit 1:1 USD valuation convention via the NBU UAH bridge;
stale stored USDT quotes cannot override the USD reference.
The monthly table covers the current calendar month through today and the
previous two months; the weekly table covers a rolling three-month window.
Both remain independent of chart dates and stack in the right-hand column.
Original journal amounts and stored currency fields remain authoritative.
The dashboard net worth endpoint is selected with `net_worth_eur=1`.
Use `capital_period=30d`, `3m` or `1y` for its independent history range.
The homepage uses the same choices independently for capital, budgets,
categories, expense accounts and revenue accounts, defaulting to `30d`.
Each link preserves the other selections and returns to its own chart anchor.
Ranges come from the server's Europe/Kyiv calendar; they do not change the
global calendar, session or stored movements. Budget spending is clipped to
the selected range, while existing overlapping caps are retained without
proration. Other budget API callers keep their original per-limit contract.
Validated account-note snapshots fill earlier history in their original
valuation currency. Known subsequent movements apply until verified native
coverage starts; thereafter the original ledger wins. Incomplete totals
are labelled separately, and no income or expense entries are generated.

Build the frontend with the root lockfile and the v3 workspace:

```sh
npm ci
npm run build --workspace=resources/assets/v3
node tests/custom/dashboard_history_url.mjs
```

Generated `public/build` files remain ignored by Git.

Currency display checks keep native PHP rounding, locale, colour, precision
and anonymous masking while normalizing currency symbols and legacy aliases.
UAH uses `₴`; the exact legacy aliases `грн`, `грн.` and `₴` resolve to the
same symbol without replacing raw bank text, currency codes or account names.
`currency_symbols_php.php` compares the native formatter against a fixed
baseline fixture and verifies that currency models remain unchanged.
`currency_display.mjs` covers exact decimal strings, fiat/token precision,
unknown symbols and legacy accounting argument preservation:

```sh
node tests/custom/currency_display.mjs
```

Use Node 24+ for the normal Vite production build. After building, validate
the generated shared formatter, every JavaScript asset's SRI/import paths,
and synchronous helper loading with CSP nonce and content hashes in all six
base layouts:

```sh
npm run build --workspace=resources/assets/v3
node tests/custom/currency_symbols_native.mjs
```

The shared helper lives in `public/v1/js/ff/currency-display.js`; the source
Vite formatter delegates to it, and the native legacy accounting source uses
the same policy. No generated bundle or deployment patcher is committed.
Stored codes, currency IDs, precision, rates and currency preferences remain
authoritative. When using dependencies from an older separate installation,
preload the checked helper with
`php -d auto_prepend_file=app/Support/Reports/CurrencyDisplay.php` for PHP
fixture processes.

`revenue_chart.php` reconciles genuine income sources with the UAH income
ledger, including current NBU conversion and refund exclusions. Revenue,
expense and category chart renderers reject non-UAH metadata; stale EUR
amounts cannot be relabelled as hryvnia. Generic charts retain their original
currency metadata and formatting.

Payment-review checks cover recommendation-only choices, owner/category/account
scope, stale requests, immutable queued decisions, category-only writes and
CSRF protection. `payment_review_storage.php` creates an isolated in-memory
SQLite schema with synthetic rows; it does not read production accounts.
For testing against a separately installed application, set
`FIREFLY_TEST_APP_ROOT` to its root; the default is this repository.

Refund checks cover expense credits across profit, budgets, categories,
merchants, dashboard cards and subscription payments. Native `Refund` link
fixtures verify ownership, account/currency matching, ambiguity and cumulative
refund limits. `refund_storage.php` and `refund_category_update.php` use
isolated in-memory SQLite rows to check metadata and conditional category
updates, including stale requests and preservation of financial fields.

`subscription_overview.php` checks the active-subscription monthly forecast,
UAH/EUR conversion, deterministic cent allocation, empty/error states and
owner scope. It also checks the native list, controls and bounded doughnut
chart markup. These source checks do not replace a browser layout check.
With `FIREFLY_TEST_APP_ROOT` set, only Composer dependencies and application
bootstrap come from that installation; checked source and views stay in this
repository.

`conditional_tags_update.php` verifies additive tags, concurrent tag/category
changes, owner scope, preserved financial fields and the native controller's
guard ordering. `internal_card_delete.php` exercises conditional retirement of
technical same-asset card staging, complete snapshot checks, lock coverage,
rollback, native soft deletion and ordinary DELETE behavior. Both use isolated
in-memory SQLite fixtures. Cross-language Python fixtures verify the exact
fingerprints used by the native API.

`bank_authorization_mutation.php` checks conditional pending-withdrawal updates
and release, full snapshot conflicts, owner metadata, native controller
integration, account currencies and rollback. It uses isolated in-memory
SQLite rows and loads this repository's controllers explicitly. The Python
authorization suites cover pending/settled status, amount corrections, changed
bank IDs, release coverage, interrupted retries and concurrent owner edits.

`category_fallback.php` covers actual provisional categories, retained review
entries, explicit confirmation of unchanged `Інше`, owner races and complete
payment fingerprints. The authorization suite loads the native
`JournalUpdateService` hook from this repository and checks that settlement
retains the marker while explicit category edits finalize it. Python fixtures
cover fallback opt-in, idempotent repeats and confirmed classification/refund
upgrades without changing financial movements.

Dashboard module checks also cover the restored subscription summary tile,
exact signed/exponent zeroes, tiny nonzero flows, API entry order, anonymous
masking and filtered subtitle separators. Detailed subscription and savings
widgets remain hidden; the savings startup request remains disabled.
Expense checks verify tax-only payee grouping, mixed/unknown categories and
linked refund credits while retaining the original signed chart total and
conversion count. Payment-review layout changes preserve the native POST
actions, hidden scope fields and CSRF directives.

`ukrainian_ui.php` checks 36 reviewed labels, English fallbacks, interpolation,
unchanged plurals, native view bindings, all six CSP layouts and the generated
JSON overlay. It requires native Ukrainian locale files and generated JSON;
`FIREFLY_TEST_APP_ROOT` can supply these from a separate installation. The
checked dictionaries, views, build overlay and cache helper always come from
this repository. `ukrainian_ui_cache.mjs` verifies that only the two exact
Ukrainian translation cache keys are invalidated once per dictionary revision;
preferences, sessions, financial caches and other locales remain untouched.
The helper receives a content revision from the native PHP layout, covering
the four reviewed dictionaries and both served Ukrainian JSON assets. It uses
that same revision for LocalStorage and only the Ukrainian HTTP request URLs,
including when storage is disabled or already current.

The PHP loader merges `resources/lang-overrides` without changing core locale
files. The upstream `ff3:json-translations v3` tool reads core files directly,
so the release workflow runs the native build overlay immediately afterwards:

```sh
php .ci/translation-overrides.php
node tests/custom/ukrainian_ui_cache.mjs
node tests/custom/ukrainian_ui_i18next.mjs
```

Run that overlay after normal JSON generation for local release builds too.
It replaces only reviewed keys already present in `uk-UA.json` and `uk.json`
(also `uk_UA.json` when generated), preserving other keys and locales. Generated
translation assets remain ignored by Git. The content revision follows
dictionary and generated JSON changes automatically; the PHP fixture proves
that unrelated locale updates do not change it. The i18next
check uses the actual native source callback and installed HTTP backend with
synthetic requests; an optional argument selects a disposable generated app
root instead of this repository.
English-only quick releases skip this overlay when both requested Ukrainian
assets are absent; an incomplete generated pair is rejected.

`dashboard_chart_periods.php` exercises calendar boundaries, independent
query parameters and the actual four native controller projection guards,
including owner scope, partial budget periods and unchanged global dates.
`dashboard_chart_periods.mjs` checks same-origin URLs and server-supplied
ranges without changing the source registry or global calendar. After the
normal Vite build, verify the actual five chart callers, generated URLs,
selector wrappers and source asset hashes/SRI:

```sh
node tests/custom/dashboard_chart_periods.mjs
node tests/custom/dashboard_period_native.mjs
node tests/custom/dashboard_period_layout.mjs node_modules/chart.js/dist/chart.umd.js public/v1/js/ff/charts.js resources/assets/v3/js/shared/draw-chart.js public/v1/js/lib/Chart.bundle.min.js
```

The last check uses a fresh headless Chrome profile with synthetic responses.
It verifies desktop/mobile plot bounds and that both native chart runtimes
keep period controls visible after empty or failed requests.

`interface_readability.mjs` uses the actual Vite CSS, native chart source
hooks and real Chart.js 2/4 in fresh Chrome profiles. It compares the original
root size with the 106.25% scale at desktop, tablet and mobile widths, checking
control hierarchy, 13px chart fonts, revenue-before-expense order and overflow:

```sh
node tests/custom/interface_readability.mjs
```

Shared Sass applies the root scale in all six layouts. Legacy consumers load
the existing `charts.defaults.js` after the unchanged Chart.js bundle; its
content hash, SRI and CSP nonce cover all sixteen view references.
The browser helper uses CDP device emulation and asserts the actual 390px
viewport rather than Chrome's minimum window width. The native budget table
keeps its own responsive scroll container when larger text needs more room.
All three budget action/limit dropdowns use fixed Popper positioning. Twelve
real Bootstrap cases check the last menu item's hit target before and after
horizontal scrolling, with one/six rows at actual 390px/1440px widths. The
check uses the installed Bootstrap bundle; an optional argument overrides its
path.

Homepage rankings select positive net UAH values after the full server refund,
tax-grouping and NBU calculations: top three expense/income accounts and top
seven categories. Exact cent comparisons preserve large amounts and stable
ties. The complete API response and report totals remain unchanged; fewer
available entries are shown without padding or invented aggregate bars.
`category_chart_js.cjs` executes the native ranking and refresh hooks,
including palettes applied after the standard colorizer and empty states.
The readability fixture checks the paired income-left/expense-right layout
at the 768px boundary, mobile stacking, full titles and the separate account
sidebar while retaining the existing typography and menu checks.

Category colors follow normalized names rather than their rank or selected
period. Related semantic groups share a hue family with distinct deterministic
shades; unknown names use a stable hash fallback. Normalization affects only
color selection, preserving the original labels, amounts and complete data.

Category fills and borders stay within the orange/yellow and blue/violet hue
bands, reserving red and green for account charts. The native test covers all
semantic families and 1,000 unrecognized names without changing ranking,
amounts or the account palettes.


The homepage budget plot reuses the deterministic category-name palette.
Each budget keeps one hue across periods; spent, remaining, budgeted and
overspent stacks use distinct lightness levels without red or green fills
or borders. Raw names stay aligned after zero rows are skipped.
Only the homepage budget holder uses this palette; other plots and the
red spent-progress bar in the budget table retain their behavior.

```sh
node tests/custom/budget_chart_colors.mjs
node tests/custom/budget_chart_build.mjs
```

Profit chart resolution follows the selected range: daily for 30 days,
ISO weeks for three months, monthly for a year. Boundary weeks are clipped
to the report dates. The result line accumulates net flows from the start
of the range; totals, conversion and independent three-month tables stay
unchanged. The profit period tests cover calendar boundaries, empty weeks,
refunds and accumulated results in both report currencies.


## Finance page tools

Accounting contains transactions and payment review; Statistics contains Profit.
Accounts, categories, rules, recurring transactions, tags, groups, counterparties
and liabilities are direct links under Accounting Organization.
It changes navigation only, preserving configured rules and synchronization.

Index-list search is explicitly local to the current page. Toolbars stay outside
POST forms, Enter is cancelled, and filters use their own CSS marker instead of
changing native visibility or selections. Responsive table regions keep native
menus accessible, including buttons inserted later by Alpine. Tag-card search
keeps the count of selected hidden tags visible. Long pages offer section links.

Budget cards compare plan/spent/remaining for the selected period; subscriptions
show native expected dates and a twelve-month equivalent. Savings goals use
actual progress without a minimum fabricated width, retaining crypto precision
and privacy. Transactions preserve selected dates when changing type. Account
and payment-review searches keep their page scope. The NBU calculator uses exact
decimal arithmetic through UAH, with USDT using USD; it never creates a transfer.
Profit supplements the existing totals with daily averages and the retained share
of income. Empty, zero-income and anonymous states are explicit.

```sh
node tests/custom/workspace_tools.mjs
node tests/custom/record_page_tools.mjs firefly-iii-local:6.7.9
```

Research: [table tasks](https://www.nngroup.com/articles/data-tables/),
[progressive disclosure](https://www.nngroup.com/articles/progressive-disclosure/),
[Firefly budgets](https://github.com/firefly-iii/docs/blob/main/docs/docs/explanation/financial-concepts/budgets.md),
[Actual reports](https://actualbudget.org/docs/tour/reports/).
