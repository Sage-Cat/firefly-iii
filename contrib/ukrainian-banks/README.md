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
using the corresponding `sync` command. Ambiguous transfers, missing rates,
and unexplained balance differences defer the import. Personal XLSX imports
support planning only. NBU reporting targets are UAH and EUR; supported fiat
sources are UAH, EUR, USD, and CHF. Token valuations require explicit dated
rates rather than an assumed currency peg.

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
