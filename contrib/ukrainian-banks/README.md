# Ukrainian bank import tools

These optional Python 3 tools import monobank and PrivatBank FOP statements,
plan a PrivatBank personal XLSX import, maintain dated NBU exchange rates, and
assign configured expense budgets. They use the standard library and run
separately from the Firefly III application.

These tools are covered by the repository's GNU Affero General Public
License, version 3 or later; see [LICENSE](../../LICENSE) and
[COPYING](../../COPYING).

The bank clients make read-only requests. Applying an import or budget plan
writes to Firefly III. The Firefly client deliberately accepts only the local
API at `http://127.0.0.1:8080/api/v1` and refuses redirects and proxy settings.
Keep that boundary when deploying these tools.

## Private configuration

Run commands from this directory. Store tokens and configuration beneath the
ignored `.state/` directory: `api-token`, `monobank-token`, `privat-fop-token`,
and the provider-specific configuration consumed by each script. Token and
state files must be private; provider state directories require mode `0700`.
No credentials, account bindings, statements, or runtime state are included.
The tests demonstrate configuration shapes with synthetic identifiers.

Start with `python3 monobank_sync.py plan` or
`python3 privat_fop_import.py plan`. Review reconciliation results before
using the corresponding `sync` command. Missing rates and unexplained balance differences defer the import.
Missing categories and unconfirmed counterpart accounts do not. Personal XLSX imports
support planning only. NBU reporting targets are UAH and EUR; supported fiat
sources are UAH, EUR, USD, and CHF. USDT explicitly follows the profile convention of 1 USDT = 1 USD, using
dated NBU USD quotes for UAH and EUR valuation. This is a USD proxy, not
an official NBU stablecoin quote. Historical rate writes use bounded batches.

## Payment review

Valid uncategorized payments are imported without a category. A suspected own
transfer with an unconfirmed counterpart uses a zero-opening asset account
marked `bank_review_suspense` for the original currency. Its opposing balance
is provisional; it does not produce income or spending. Only needed suspense
accounts are provisioned, after a successful dry reconciliation in write mode.

The native **Уточнення платежів** sidebar page at `/transactions/review` offers
category suggestions based on confirmed history and clearly labelled bank
hints. It never applies a suggestion automatically. Ordinary category choices
update only the category relation. A queued transfer choice records a bound
`bank_review` decision in the existing note JSON, preserving bank evidence;
the next sync applies the counterpart or external category. Queued decisions
remain immutable until applied, preventing a concurrent sync from losing a
newer choice. Repeated imports preserve manual categories and resolved choices.

Only same-currency owned accounts may be chosen as counterparts. Cross-bank
opposing records can reuse a uniquely confirmed journal with retained source
aliases. Separate similar payments are not collapsed. If both bank legs were
already staged independently, `paired_pending` keeps them visible without a
non-atomic merge; remaining valid payments continue importing. Resolve that
pair conflict before finalizing its reroute.

## Refunds and cashback

Refund synchronization is opt-in: set `"auto_refunds": true` in the provider
configuration to run it after a successful import. It recognizes explicit
settled monobank cancellations, preserves manual categories, and links only
one unambiguous same-account, same-currency, equal-amount purchase within ten
minutes on the same day. Ambiguous matches remain available for review.

Classification uses the native `Повернення коштів` category and this fork's
`If-Match-Category` conditional API update. The expected category is checked
while locking the journal; a stale category returns 409. Only the category
relation changes, with rules and webhooks disabled. Native `Refund` links
connect purchases to refunds without changing original movements or balances.
Reports treat refunds as expense credits rather than income; validated links
credit the original category, budget, merchant and subscription on the refund
date. Unlinked classified refunds remain separate expense credits.

Keep cashback in a separate account from purchases and refunds. A provider's
cashback metadata alone does not create a financial movement; import an actual
cashback payout only when its statement confirms it.

## Balance valuation history

`capital_history.py plan` reads private Sheets values/formulas plus account
bindings and verified native-coverage dates from `.state/capital-history`.
It ignores dynamic date formulas, keeps the last snapshot per day, and
preserves valuation currencies. `apply` stores versioned history in existing
account notes, without changing openings, transactions, income or expenses.
Existing notes are backed up by the operator and preserved outside the
history block; repeated application is idempotent. The shared bank lock
serializes cooperating imports.

Applying requires this fork's `If-Match-Notes` API support. It checks owner
and hash while holding the existing note-row lock, returns 409 on conflicts,
and accepts notes-only updates. Missing or duplicate note rows fail closed.
The conditional path writes only the Note, suppressing account-update
financial hooks. Ordinary account API requests retain upstream behavior.
Keep bindings, source rows, coverage evidence and backup plans private.

## Tests and scheduling

Run the offline synthetic suite:

```sh
python3 -m unittest discover -s tests
```

The optional user service and timer templates in `systemd/` assume this
directory is `$HOME/firefly-iii/contrib/ukrainian-banks`. Adjust their paths
and schedules for the installation. They use private file permissions and
share the synchronization lock. Copying the templates does not activate
them; configure and review private state before enabling any timer.
