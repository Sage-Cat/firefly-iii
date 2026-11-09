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
the report-day NBU UAH bridge and rounds the combined portfolio once.
USDT follows the explicit 1:1 USD valuation convention via the NBU UAH bridge;
stale stored USDT quotes cannot override the USD reference.
The monthly table covers the current calendar month through today and the
previous two months; the weekly table covers a rolling three-month window.
Both remain independent of chart dates and stack in the right-hand column.
Original journal amounts and stored currency fields remain authoritative.
The dashboard net worth endpoint is selected with `net_worth_eur=1`.
Use `capital_period=30d`, `3m` or `1y` for its independent history range.
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
