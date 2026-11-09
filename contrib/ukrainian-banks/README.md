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

By default, valid uncategorized payments are imported without a category. A
suspected own transfer with an unconfirmed counterpart uses a zero-opening
asset account marked `bank_review_suspense` for the original currency. Its
opposing balance is provisional; it does not produce income or spending. Only needed suspense
accounts are provisioned, after a successful dry reconciliation in write mode.

The native **Уточнення платежів** sidebar page at `/transactions/review` offers
category suggestions based on confirmed history and clearly labelled bank
hints. It never applies a suggestion automatically. Ordinary category choices
update the category and clear only its managed provisional marker. A queued
transfer choice records a bound `bank_review` decision in the existing note
JSON, preserving bank evidence; the next sync applies the counterpart or
external category. Queued decisions remain immutable until applied,
preventing a concurrent sync from losing a
newer choice. Repeated imports preserve manual categories and resolved choices.

Only same-currency owned accounts may be chosen as counterparts. Cross-bank
opposing records can reuse a uniquely confirmed journal with retained source
aliases. Separate similar payments are not collapsed. If both bank legs were
already staged independently, `paired_pending` keeps them visible without a
non-atomic merge; remaining valid payments continue importing. Resolve that
pair conflict before finalizing its reroute.

## Provisional category defaults

Set `"auto_category_fallback": true` in a provider configuration to assign
ordinary uncategorized income and spending to the actual `Інше` category after
verified import, refunds and configured classification. This is opt-in and
creates no financial movement. Own transfers and suspense records do not
receive an ordinary fallback.

Automatic defaults carry the managed tag `Потребує уточнення категорії` and
remain in payment review as `Інше · потребує уточнення`. A confirmed rule or
an explicit category choice removes only that tag. Choosing `Інше` explicitly
also confirms it; existing manual `Інше` without the tag stays final. Ordinary
bank settlement and partial amount/date/note updates retain provisional status.
Unknown payments remain reviewable until classification is confirmed.

The native default path uses `If-Category-Default`, `If-Match-Category` and
`If-Match-Payment`. The last header binds the caller's complete payment
snapshot, including owner metadata and financial scope, to locked reads.
Concurrent edits return 409, preserving the newer owner choice. Classification
and refund upgrades can replace provisional defaults, while final manual
categories retain their existing protection. Amounts, notes and unrelated
tags are preserved throughout.

## Pending monobank authorizations

Set `"pending_authorizations": true` in the monobank configuration to import
pending external withdrawals immediately with the managed tag
`monobank: очікує підтвердження`. Settlement updates the same journal and
removes only its managed pending status, preserving owner categories, budgets,
tags and unrelated notes. A changed bank ID creates a replacement only after
a unique exact match; ambiguous matches and conflicting owner edits stay for
review. The planner also protects owner edits made between a replacement
creation, a rejected release and a retry.

A disappeared or zeroed authorization is retired only with coherent fresh
bank evidence and reconciled balances; no compensating income is created.
Refresh coverage reaches the oldest live authorization even after an
interrupted import. Updates and releases use `If-Match-Bank-Authorization`
against a fresh, locked journal snapshot. Private source backups and an
archived note retain release evidence and assignment metadata. Without this
option, pending authorizations wait for settlement. Pending incoming payments
and own transfers require separate confirmation.

## Refunds and cashback

Refund synchronization is opt-in: set `"auto_refunds": true` in the provider
configuration to run it after a successful import. It recognizes explicit
settled monobank cancellations, preserves manual categories, and links only
one unambiguous same-account, same-currency, equal-amount purchase within ten
minutes on the same day. Ambiguous matches remain available for review.

Classification uses the native `Повернення коштів` category and this fork's
`If-Match-Category` conditional API update. The expected category is checked
while locking the journal; a stale category returns 409. Category changes also
clear an existing provisional marker, with rules and webhooks disabled. Native
`Refund` links connect purchases to refunds without changing original movements
or balances.
Reports treat refunds as expense credits rather than income; validated links
credit the original category, budget, merchant and subscription on the refund
date. Unlinked classified refunds remain separate expense credits.

Keep cashback in a separate account from purchases and refunds. A provider's
cashback metadata alone does not create a financial movement; import an actual
cashback payout only when its statement confirms it.

## Confirmed classification rules

Set `"auto_classification": true` in the provider configuration to apply
private, confirmed metadata rules after closing-balance verification and,
where enabled, refund synchronization. Configure version 1 rules in
`.state/bank-classification/config.json`, inside a private `0700` directory.
`merchant_rules` contain a description or merchant `prefix` and a `category`;
they match withdrawals only. `income_rules` select an owned `destination_id`
or a `description_prefix`, plus a `category` and optional `tag`; they match
external deposits only. Prefixes respect word boundaries and ignore case and
repeated whitespace. Rules and account bindings belong to each installation.

Classification preserves manual categories and existing tags, skips ambiguous
rules, refunds, unmanaged unsettled records and unresolved own transfers, and
never creates financial movements. Category writes use `If-Match-Category`.
Additive tag writes merge the freshly read list and use `If-Match-Tags` with
the expected category and full payment snapshot; concurrent owner changes
return 409. Both conditional paths disable rules and webhooks and leave amounts,
dates, budgets and notes unchanged. Cashback rules classify only the actual
net payout and may add a cashback tag; estimated bonuses and gross/tax
adjustments are not synthesized.

## Physical cards sharing one asset

Personal imports may include explicitly confirmed `internal_card_transfers`
when two physical cards map to one asset account. Confirmations bind an exact
credit key, card suffixes, currency, amount, minute, prior source key and
source balance to a bank-evidence SHA-256 digest. Keep these confirmations and
their source evidence private. Without matching evidence, the movement stays
in payment review awaiting a newer source-card statement.

The planner consumes both card legs once, or validates the missing source
debit against the confirmed closing balance. It records versioned evidence
in a raw `firefly-internal-cards:v1` HTML comment in account notes, preserving
unrelated notes and the `BalanceHistory` comment byte-for-byte. Note writes
use `If-Match-Notes`; retiring earlier technical suspense staging uses
`If-Match-Internal-Card`. The native API locks and checks the complete journal
snapshot and its relationships before soft deletion. Changed or categorized
staging is preserved for review. This reconciliation creates no income or
expense, and a later matching source-card debit is consumed without a second
movement.

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
