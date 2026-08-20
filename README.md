# lifesave

A private, single-owner **Personal Life Management System** — finance-first, built to expand
into broader personal-life tooling over time. It gives its one user a single trustworthy place
to answer "where do I actually stand financially, what needs my attention, and what should I
do next" — with AI as an explainer and assistant, never as the source of financial truth.

Full product rules, non-negotiables, and phase plan live in [`CLAUDE.md`](CLAUDE.md) at the
repo root — that document governs this codebase and is the authoritative reference. This
README is a practical map of how each module actually works, plus a guide to actually using
and deploying the app.

Live at **https://douglas.waterliftsolarsavings.africa/**.

## Using the app

The sidebar is grouped by what you're doing, top to bottom:

- **Dashboard** — the one-glance view: net available cash, M-Pesa/savings balances, this
  month vs. last month, top spending categories, anything needing attention (unconfirmed SMS,
  reconciliation mismatches), savings goal progress, recent transactions, and a couple of
  AI-generated observations at the bottom. This is meant to be the "where do I stand" screen —
  everything on it is a real, computed figure, not a guess.
- **Finance**
  - **Messages** — paste raw M-Pesa (or bank) SMS text here, one or many at once separated by
    a blank line. Each gets parsed automatically; anything the deterministic parser can't
    confidently read falls back to AI, which only ever produces a *proposal* you still have to
    confirm. Confirm/Edit/Reject each one under "Ready to review" — nothing posts to the ledger
    until you confirm it. Possible duplicates are flagged separately for a second look.
  - **Accounts** / **Categories** — set these up first: your real accounts (M-Pesa, M-Shwari,
    bank, cash) and your income/expense categories. SMS parsing and manual entry both need
    these to exist. Most accounts are **Assets** (money you have); a debt like Fuliza should
    be added as a **Liability** instead — the toggle is on the "Add account" form, and a
    liability's balance is what you *owe*, shown separately from your available cash.
  - **Transactions** — the full ledger history, newest first, with a Reverse action on
    anything that needs correcting (this posts an offsetting entry — it never deletes or edits
    the original).
  - Manual entry — three separate forms for **Record Income**, **Record Expense** (with an
    optional fee), and **Record Transfer** (moving money between your own accounts — never
    treated as income/expense).
  - **Reconciliation** — shows up automatically when an SMS-reported balance doesn't match
    what the ledger calculates. Resolving one requires a note explaining what you found; it
    never silently overwrites a balance.
- **Planning** — **Savings Goals** (with real-vs-virtual allocation breakdown per account),
  **Wishlist** (affordability projected in three scenarios per item), **Shopping** (what you
  bought, separate from how you paid for it), **Tasks** (a plain to-do list).
- **Personal** — **Notes** (freeform), **Habits** (a daily check-in and streak per habit),
  **Calendar** (dated events, upcoming and past).
- **Health** — **Weight** (a log with the change vs. your last entry), **Workouts** (type +
  duration), **Meals** (a basic food log, no calorie/macro tracking).
- **Insights** — **Reports** (a proper monthly report: income/expense/net, period comparison,
  category breakdown, largest transactions), **AI Assistant** (ask it things like "where did
  most of my money go this month?" — it always pulls real numbers, never estimates one
  itself), **Achievements** (read-only badges computed from your actual activity — habit
  streaks, completed tasks/goals, purchased wishlist items).

Day-to-day, the loop is usually: paste SMS as it comes in → confirm the proposals → check the
dashboard → occasionally record something manually that didn't come via SMS (cash spending,
say) → ask the AI Assistant when you want a quick answer instead of digging through Reports.

## Stack

Laravel 13 (PHP 8.3+) · MySQL 8 · Livewire 4 (single-file components) · Alpine.js · Tailwind
CSS v4 + `@tailwindcss/forms` · Chart-ready but chart-free so far · database-backed queues ·
PWA (manifest + service worker) · Claude API via a provider-agnostic AI abstraction.

Everything runs on ordinary PHP/MySQL shared hosting — no Docker, no Redis, no separate
Node/SPA backend required.

## How the codebase is organized

Domain-oriented, not layer-oriented. Business logic lives in `app/Domain/{Module}/` — each
module owns its `Models/`, `Services/`, `Enums/`, and (where relevant) `Contracts/` and
`DataTransferObjects/`. Livewire single-file components under `resources/views/components/`
are thin UI coordinators that call into these services; they hold almost no business logic
themselves. The database is the source of truth for every financial figure — nothing in the
UI, AI layer, or a cached column is ever treated as authoritative over the ledger.

```
app/Domain/
  Finance/        double-entry ledger, accounts, categories, transfers, reversals, reporting
  Ingestion/      raw SMS → parsed → proposed transaction → confirmed posting
  AI/             Claude provider abstraction + read-only financial assistant tools
  Goals/          savings goals and virtual allocations
  Wishlist/       wishlist items + deterministic affordability scenarios
  Shopping/       purchases and line items, separate from how they were paid
  Tasks/          a minimal personal task list
  Notes/          freeform notes
  Habits/         daily check-ins + streaks
  Calendar/       dated events
  Health/         weight, workouts, meals — three independent logs
  Achievements/   read-only badges computed from other modules' data (no table of its own)
  Audit/          an append-only log of important actions
  Support/        small cross-domain primitives (e.g. the Priority enum)
```

---

## Finance — the ledger (`app/Domain/Finance`)

The core of the system and the one module every other module ultimately depends on. It's a
real **double-entry ledger**: every financial event is a `Journal` with two or more balanced
`LedgerEntry` postings (debits = credits, enforced at write time). Once posted, a `Journal`
and its `LedgerEntry` rows are **immutable** — both models throw
`ImmutableLedgerRecordException` if code ever tries to update or delete a posted entry
in place (`Journal` allows exactly two fields to change post-creation: `is_reversed` and
`reversed_journal_id`, so a reversal can reference what it reversed).

**Models:** `FinancialAccount` (a real-world account: M-Pesa, M-Shwari, a bank account, cash)
wraps a `LedgerAccount` (the actual ledger node its postings hit) plus `TransactionCategory`
(income/expense categories) and `BalanceObservation` (an SMS-reported balance, stored as a
data point to reconcile against — never used to overwrite a balance directly).

**Liabilities** (e.g. Fuliza): `FinancialAccountService::createAccount()` defaults to an ASSET
ledger account but accepts `type: LedgerAccountType::LIABILITY` for money you owe rather than
have — the Accounts page has an Asset/Liability toggle when adding one. `LedgerAccount`'s
balance math (`balanceMinor()`) is already fully generic across all five classical account
types (it derives the normal balance side from `LedgerAccountType`, not from any
asset-specific assumption), so this needed no ledger-core changes — only
`FinancialReportingService::netAvailableCashMinor()` needed a fix, since naively summing a
liability's balance alongside asset balances would have added debt to "available cash"
instead of subtracting it. A Fuliza drawdown SMS parses deterministically
(`MpesaParser::parseFuliza()`) into a transfer-shaped proposal — the liability is credited
(increases) and the M-Pesa account is debited (increases) by the borrowed amount, with the
access fee posting as a real expense, exactly like any other transfer with a fee.

**Services:**
- `LedgerService::postJournal()` — the only path anything uses to write to the ledger; wraps
  the journal + entries write in a DB transaction and rejects unbalanced postings
  (`UnbalancedJournalException`).
- `TransactionService::recordIncome()` / `recordExpense()` — manual income/expense entry;
  expenses support an optional separate fee leg.
- `TransferService::recordTransfer()` — moves money between the user's own accounts (e.g.
  M-Pesa → M-Shwari). This is never recorded as income or expense — only asset ledger
  accounts move.
- `ReversalService::reverseJournal()` — the only way to correct a posted entry: it posts a
  new, opposite journal referencing the original. Nothing is ever deleted or silently
  overwritten.
- `ReconciliationService` — compares SMS-observed balances against the calculated ledger
  balance and tracks reconciliation status.
- `FinancialReportingService` — every number the dashboard, monthly report, and AI assistant
  show is computed here: financial summaries, category spending, period-over-period
  comparisons, account balances, largest transactions, savings goal progress, and simple
  unusual-spending detection. The AI layer never does this math itself.

**Money handling:** every amount is a `BIGINT` in minor units (KSh 5,000.00 is stored as
`500000`) — never float/double. `Money::toMinorUnits()` / `Money::formatMinor()` are the only
places that convert between human-entered strings and stored integers.

Pages: Accounts, Categories, Transactions, Record Income/Expense/Transfer, Reconciliation.

---

## Ingestion — raw SMS to posted transaction (`app/Domain/Ingestion`)

There is no document upload and no OCR anywhere in this system, by design — the only input
is raw pasted SMS text. The pipeline is fixed and every message goes through it in order:

```
raw pasted text → raw storage → normalization → provider detection →
deterministic parser → AI fallback (only if the parser can't confidently handle it) →
structured extraction → validation against the source text → duplicate detection →
proposed transaction → user confirmation → ledger posting
```

**Models:** `FinancialMessage` stores the pasted text byte-for-byte, forever, as evidence —
it is never mutated. `ProposedTransaction` is the mutable staging row a message produces; it
stays mutable only until it's confirmed or rejected.

**Services:**
- `TextNormalizer` / `ProviderDetector` — clean and classify incoming text before parsing.
- `MpesaParser` — the deterministic parser, tried first. Recognizes five M-Pesa SMS shapes
  (send money, receive money, pay bill, buy goods, withdrawal) plus transaction cost/fee and
  confirmation-code extraction via regex. Anything it can't confidently match falls through
  rather than guessing.
- `BankSmsParser` — a basic deterministic parser for common bank SMS formats.
- `ClaudeProvider::parseFinancialMessage()` (AI fallback) — only invoked when no deterministic
  parser confidently handles a message. Its output is a **proposal only**.
- `AiExtractionValidator` — independently re-checks every AI-extracted field (amount, date,
  counterparty, fee) against the original raw text before anything is allowed to reach a
  proposed transaction. Schema-valid AI JSON is not treated as correct on its own.
- `DuplicateDetectionService` — layered duplicate checks: provider + external transaction
  reference, a hash of the normalized text, and a (provider, account, amount, timestamp,
  counterparty, type) fingerprint. Fuzzy matches can only flag a possible duplicate for human
  review — nothing here ever auto-deletes or auto-merges a financial record.
- `FinancialMessageIngestionService` — orchestrates the pipeline end to end for a pasted batch.
- `ProposedTransactionConfirmationService` — the only path from a proposed transaction to an
  actual ledger posting; this is where user-supplied overrides (account, category) get applied
  and validated before `LedgerService` is ever called.

An unknown message is stored as evidence and surfaced under "Needs review" — it never
silently becomes a financial record. Page: **Messages** (paste box, ready-to-review proposals,
possible-duplicate warnings, needs-review list).

---

## AI — Claude as narrator, never as ledger (`app/Domain/AI`)

`AIProviderInterface` is a small, deliberately narrow contract (`parseFinancialMessage()`,
`answerQuestion()`) implemented by `ClaudeProvider`, so a second provider could be swapped in
later without touching any domain code. The Claude API key never reaches the frontend — every
call goes through the Laravel backend.

The AI never gets database access, SQL, or a generic "run a query" tool. Instead,
`FinancialAssistantService` exposes exactly seven whitelisted, backend-computed tools, each a
closure bound to the authenticated user: `get_financial_summary`, `get_category_spending`,
`compare_financial_periods`, `get_account_balances`, `get_savings_goal_progress`,
`calculate_wishlist_affordability`, `get_transactions`. The model calls these tools (via the
Anthropic SDK's tool-runner loop) and narrates what they return — it never sums or estimates a
figure itself. The AI Assistant page is explicitly **read-only**: nothing it does can write to
the ledger.

In tests, `AIProviderInterface` is always rebound to `FakeAIProvider` — no automated test ever
calls the real Anthropic API.

Page: **AI Assistant** (suggested questions + free-form chat over the tools above).

---

## Goals — savings goals and virtual allocations (`app/Domain/Goals`)

A `Goal` (e.g. "Emergency fund", target amount, optional monthly target, priority) tracks
progress via `GoalAllocationEvent` rows — an append-only history, not a bare mutable number.
`SavingsAllocationService` handles allocate / release / reallocate and detects
over-allocation. Allocations are **virtual**: earmarking money toward a goal never moves it out
of the real account balance it lives in, and virtual allocations are never double-counted as
separate money in net worth — they're a view over the same ledger balances, not new funds.

Page: **Savings Goals**, including a breakdown of real account balances vs. virtual goal
allocations per account.

## Wishlist (`app/Domain/Wishlist`)

`WishlistItem` (name, estimated price, priority, optional linked `Goal`, target date) tracks
status through `Considering → Saving → Ready → Purchased/Cancelled`.
`WishlistAffordabilityService` computes three deterministic scenarios per item —
**Conservative / Current Trend / Aggressive** — projecting months-to-afford from the linked
goal's planned monthly contribution. No scenario is ever guessed by the AI; it's arithmetic
over real ledger and goal data.

Page: **Wishlist** — active items with affordability mini-cards, plus purchased/cancelled
history.

## Shopping (`app/Domain/Shopping`)

`Purchase` (what was bought, from which `Merchant`, optionally linked to the `Journal` that
paid for it) with `PurchaseItem` line items. This is deliberately separate from the finance
ledger: a purchase's items are never inferred from an SMS — they only exist if entered as an
actual purchase record. `PurchaseService` / `MerchantService` handle creation and
merchant find-or-create; `Purchase::itemsReconcileWithTotal()` flags when logged item lines
don't add up to the purchase total.

Page: **Shopping** — log a purchase, optionally link it to an existing unlinked expense
transaction, add items after the fact.

## Tasks (`app/Domain/Tasks`)

Deliberately minimal for V1: a `Task` (title, priority via the shared `Priority` enum, optional
due date/notes) moves through `Pending → Completed` or `Cancelled` via `TaskService`.
Calendar, habits, and notes are explicitly out of scope here — see `CLAUDE.md` §20.

Page: **Tasks** — open list, add form, recently-closed history with reopen.

## Notes, Habits, Calendar (`app/Domain/{Notes,Habits,Calendar}`)

Added as Phase 10, kept deliberately as minimal as Tasks was:

- **Notes** — a `Note` is just an optional title + body. Edit/delete in place, no tags, no
  rich text, no attachments.
- **Habits** — a `Habit` is just a name. A single tap toggles a `HabitCheckIn` for today
  (an immutable event log, unique per habit per day — undoing a mis-tap deletes and re-adds
  rather than mutating a "done?" flag). `Habit::currentStreak()` counts consecutive checked-in
  days ending today (or yesterday, if today isn't checked in yet — a streak doesn't look
  broken the moment a new day starts).
- **Calendar** — a `CalendarEvent` is a title, date, optional time, optional notes. No
  recurrence, no external calendar sync.

## Health (`app/Domain/Health`)

Three independent logs, scoped to exactly what was asked for — weight tracking, workouts, and
meals — with no calorie/macro computation and no structured training plans:

- **Weight** (`WeightEntry`) — date + weight in kg + optional notes. The Weight page shows the
  change vs. the previous entry (green when down, amber when up).
- **Workouts** (`WorkoutEntry`) — date, a freeform type (e.g. "Running", "Gym"), duration in
  minutes, optional notes.
- **Meals** (`MealEntry`) — a datetime, an optional `MealType` (breakfast/lunch/dinner/snack),
  and a description of what was eaten.

## Achievements (`app/Domain/Achievements`)

Read-only badges, computed live — no table, no rules engine, no manual awarding.
`AchievementService` is a fixed, hand-written list of thresholds read straight from other
modules' data: habit streaks at 7/30/100 days, tasks completed at 10/50, savings goals
completed at 1/5, wishlist items purchased at 1/5. Nothing here is persisted, so there's
nothing that can fall out of sync with what actually happened.

## Audit (`app/Domain/Audit`)

`AuditEvent` is an append-only log (`AuditLogger` service, `AuditAction` enum) recording the
things that matter: transactions created/posted/reversed/rejected, SMS parsed, duplicates
detected, AI parses accepted/rejected, accounts created, savings allocations changed. Never
logs passwords, API keys, or auth secrets. Posted financial records are traceable back to
their source — a manual entry vs. which `FinancialMessage`, which parser/version, which AI
response.

## Support (`app/Domain/Support`)

Small primitives shared across domains rather than duplicated — currently just the `Priority`
enum (`LOW`/`MEDIUM`/`HIGH`) used by both `Goal` and `WishlistItem`/`Task`.

---

## Reports and Dashboard

`Dashboard` and `reports/Monthly` are Livewire pages, not their own domain — they're read-only
views assembled entirely from `FinancialReportingService` and the `Goals`/`Wishlist` services.
The dashboard's information hierarchy (KPI cards → cashflow/spending → attention items →
savings goals → recent transactions → AI insights → task snippet) intentionally matches the
priority order in `CLAUDE.md` §2: financial state first, AI narration last.

## UI component library

`resources/views/components/icon.blade.php` (a single inline-SVG icon set) and
`resources/views/components/ui/{card,stat-card,badge,button,empty-state,page-header,section}.blade.php`
are the shared building blocks every page is built from — colored circular icon badges,
`rounded-2xl` cards, consistent empty states, and KSh-prefixed amount inputs throughout.
`layouts/authenticated.blade.php` is the shell: a fixed left sidebar (Dashboard / Finance /
Planning / Personal / Health / Insights) with an Alpine-powered mobile toggle. Native form controls
(`input`/`select`/`textarea`/checkbox) are reset consistently app-wide via
`@tailwindcss/forms`.

---

## Testing

Tests mirror the domain structure under `tests/Unit/Domain/...` and `tests/Feature/...`.
Financial logic (income/expense/transfer posting, fees, reversals, duplicate detection,
transaction-id uniqueness, balanced-journal invariant, balance derivation from postings,
SMS reconciliation, "ambiguous SMS never auto-posts", "AI parse can't bypass backend
validation") is covered by both unit tests against the domain services and feature tests that
exercise full Livewire page flows.

```bash
php artisan test          # full suite
./vendor/bin/pint          # code style
npm run build               # frontend assets
```

`Tests\TestCase::setUp()` always rebinds `AIProviderInterface` to a `FakeAIProvider` — the
real Claude API is never called by the automated suite.

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# configure DB credentials in .env, then:
php artisan migrate
npm run build
php artisan serve
```

## Deployment — how GitHub connects to cPanel

Live at `douglas.waterliftsolarsavings.africa`, on Truehost's shared PHP/MySQL hosting (cPanel
account `vdramulh`) — see `CLAUDE.md` §17 for the constraints that implies (no Docker/Redis, no
permanently-running queue worker, database-backed queue driven by cron). There's no CI/CD —
deploys are a deliberate manual step, matching "boring, inspectable" shared hosting.

**Repo:** https://github.com/DoughlasMuthoni/Life-save — two branches, two different jobs:

- **`main`** — the real source of truth. Every commit in this repo's history lives here.
  `vendor/` and `public/build/` are git-ignored, same as any normal Laravel repo.
- **`deploy`** — not a feature branch, and never opened as a PR. It's a single squashed commit,
  rebuilt from scratch off `main` every time a deploy happens: `composer install --no-dev
  --optimize-autoloader` and `npm run build`, then `vendor/` and `public/build/` are
  force-added and committed on top. It exists purely so the server never needs Composer or
  Node installed — cloning/pulling this one branch gets a fully ready-to-run app.

**On the server**, the app lives at `/home/vdramulh/lifesave-app` — **outside** the
subdomain's web-facing folder on purpose (`app/`, `.env`, `vendor/`, `composer.json` must never
be reachable by a browser). The subdomain's **Document Root** is set to
`/home/vdramulh/lifesave-app/public`, which is the only part of the app the web server ever
serves directly.

### Shipping a change

```bash
# locally
git checkout main
git pull
composer install --no-dev --optimize-autoloader
npm run build
php artisan test        # never skip this before a deploy

# rebuild the deploy branch (see the exact sequence used when this was first set up:
# clone main fresh into a scratch dir, composer install --no-dev, npm run build,
# git checkout -b deploy, git add -f vendor public/build, commit, force-push)
git push origin main
git push --force origin deploy
```

```bash
# on the server, over SSH
cd ~/lifesave-app
git fetch origin
git reset --hard origin/deploy   # .env is git-ignored — untouched by this
ea-php85 artisan migrate --force   # only if there are new migrations
```

### Two host-specific gotchas worth knowing before touching this again

- **PHP version:** the app requires PHP 8.3+ (see `composer.json`). This account's **CLI**
  `php` binary and **MultiPHP Manager**'s PHP-version selector for this domain don't reliably
  agree with each other, and switching the domain's PHP version there has previously caused a
  broken `AddHandler` line to get auto-written into the live `public/.htaccess`, breaking the
  site with a bare 403 (no log line — it took a static-file-vs-`.php`-file test to even
  isolate that it was PHP execution being blocked, not the docroot itself). Composer
  dependencies (Symfony components, specifically) were pinned to the `^7.2` line rather than
  the newer `8.1.x` line precisely so the app works on this account's actual, reliably-working
  PHP 8.3 — **don't "fix" that pin without good reason**, and avoid touching MultiPHP Manager
  for this domain at all if possible. Always invoke the CLI explicitly as `ea-php85` (or
  whichever `ea-phpXX` binary is confirmed working), never bare `php`, both interactively and
  in cron.
- **`storage:link` doesn't work here** — this account has PHP's `exec()` disabled (a common
  shared-hosting hardening), which Laravel's `storage:link` command depends on internally.
  Create the symlink directly instead: `ln -s ~/lifesave-app/storage/app/public
  ~/lifesave-app/public/storage`.

### Cron

One entry (cPanel → Cron Jobs), every minute:
```
* * * * * /usr/local/bin/ea-php85 /home/vdramulh/lifesave-app/artisan schedule:run >> /dev/null 2>&1
```
This is what fires the daily 2am database backup (`app:backup-database`) — see `DEPLOYMENT.md`
for the general, host-agnostic version of this whole guide.

## Where the rules live

This README describes *how* the code works. *Why* it's built this way, and the non-negotiable
rules for changing ledger logic, SMS parsing, or the AI boundary, live in
[`CLAUDE.md`](CLAUDE.md) — read it before touching anything under `Finance/`, `Ingestion/`, or
`AI/`.
