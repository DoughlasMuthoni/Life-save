# CLAUDE.md — Operating Manual for This Repository

This file governs how Claude Code must think and act while working on this project. It is
binding. Where a future instruction (from the user, an issue, or a mockup) conflicts with a
rule marked **MUST** or **MUST NOT** below — especially in the financial sections — Claude Code
must stop and flag the conflict instead of silently implementing it.

Conventions used throughout: **MUST** / **MUST NOT** = non‑negotiable. **SHOULD** = strong
default, deviate only with a stated reason. **MAY** = optional, contextual judgment call.

---

## 1. Product Purpose

A private, browser-based **Personal Life Management System**, finance-first, expanding over
time into broader personal-life tooling. It is a single user's system today, run by the
project owner, but built so a second user tier could be added later without a rewrite.

The emotional job of the product: give the user one trustworthy place to see "where do I
actually stand financially, what needs my attention, and what should I do next" — with AI as
an explainer and assistant, never as the source of financial truth.

## 2. Product Priorities

In order:

1. **Financial integrity** — the ledger must always be correct and reproducible.
2. **Trustworthy ingestion** — SMS-derived transactions must never silently corrupt the ledger.
3. **Clarity of the dashboard** — the user must see their real financial state at a glance.
4. **Savings and goals** — helping the user allocate and track progress honestly.
5. **Daily operations** — lightweight tasks, kept simple until finance is solid.
6. **AI assistance** — a helpful layer on top of all of the above, never a shortcut around it.
7. Everything else (shopping detail, health, habits, notes, calendar, achievements) is
   explicitly later and must not be pulled forward.

## 3. Technology Stack

**MUST use:**
- Laravel (latest stable) + PHP (typed, modern PHP — 8.4.1+)
- MySQL 8+, via Laravel migrations and Eloquent
- Livewire for interactive server-rendered UI
- Alpine.js for light client-side interactivity where Livewire is overkill
- Tailwind CSS for styling
- A web app manifest + service worker for PWA installability
- Laravel Scheduler for periodic jobs (cron-driven)
- Laravel Queues using the **database** driver (no Redis dependency)
- Chart.js or ApexCharts for charts/visualizations
- Claude API (via Laravel backend only) for AI features, behind a provider abstraction

**MUST NOT introduce** (without explicit user approval and a strong stated reason):
- PostgreSQL, Supabase
- A microservices split
- Docker as a hard requirement to run the app
- Redis as a hard requirement
- A separate Node/NestJS backend
- A separate React/Vue/etc. SPA
- Any infrastructure a decent shared PHP/MySQL host can't run

## 4. Architecture Principles

- **The database is the source of truth for finance.** Everything else — UI, AI, cached
  figures — is a derived view.
- **Domain-oriented, service-based Laravel app**, not a thin CRUD scaffold. Business rules
  live in application/domain services, not controllers or Livewire components.
- **AI is bolted on, not baked in.** Domain logic MUST be callable and testable without any AI
  provider present. AI calls happen through a small number of well-defined tool/service
  interfaces, never by giving a model direct data access.
- **Deterministic before probabilistic**, everywhere: parsing, categorization, affordability,
  reporting. AI is the fallback and the narrator, not the calculator.
- **Single-user now, multi-user-shaped later.** Every table that holds personal data MUST carry
  a `user_id` (or clear path to one) from day one, and authorization MUST be written as if
  other users' data exists, even though today there is only one user. Do not build tenancy
  infrastructure (teams, invites, roles) prematurely — just don't foreclose it.
- **Boring, inspectable architecture over cleverness.** This is a personal finance system;
  legibility and correctness beat elegance.

## 5. Project Structure Conventions

Standard Laravel app layout, organized by domain inside `app/`. Suggested (not yet created —
create incrementally as modules are built):

```
app/
  Domain/
    Finance/
      Models/            FinancialAccount, LedgerAccount, Journal, LedgerEntry,
                          TransactionCategory, BalanceObservation, ...
      Services/           LedgerService, TransactionService, TransferService,
                          ReversalService, ReconciliationService
      Enums/              JournalType, LedgerEntrySide, ReconciliationStatus, ...
    Ingestion/
      Parsers/            MpesaParser, MshwariParser, KcbMpesaParser, BankSmsParser,
                           GenericFinancialMessageParser
      Services/           FinancialMessageParserService, DuplicateDetectionService
      Models/              FinancialMessage, ParseAttempt, ProposedTransaction
    Savings/
      Models/              Goal, GoalAllocationEvent
      Services/            SavingsAllocationService, GoalProgressService
    Wishlist/
      Models/              WishlistItem
      Services/            WishlistAffordabilityService
    Shopping/
      Models/              Purchase, PurchaseItem, Merchant
    Tasks/
      Models/              Task
    AI/
      Contracts/           AIProviderInterface
      Providers/           ClaudeProvider, (later) OpenAIProvider
      Services/            AIService
    Audit/
      Models/              AuditEvent
      Services/            AuditLogger
  Livewire/                 Thin UI-coordinating components, grouped by module
  Http/Controllers/         Only where Livewire isn't the natural fit (webhooks, exports, PWA)
```

- Group by **domain**, not by technical layer. Keep `Models`, `Services`, `Enums` inside each
  domain folder rather than dumping everything into `app/Models`.
- Migrations stay under standard `database/migrations`; name them descriptively and keep them
  domain-scoped (one concern per migration where practical).
- Tests mirror the domain structure under `tests/Unit/Domain/...` and
  `tests/Feature/...`.

## 6. Finance / Ledger Non‑Negotiable Rules

This is the core of the system. Treat this section as law.

- The system MUST use **double-entry accounting** internally: every financial event is a
  `Journal` with two or more balanced `LedgerEntry` postings (debits = credits).
- A **transfer** between the user's own accounts (e.g. M-Pesa → M-Shwari) MUST NOT be recorded
  as income or expense. It affects only asset ledger accounts.
- **Transaction fees** MUST be posted as their own ledger effect (separate expense/charge
  entry), not folded silently into the transfer or expense amount.
- **Posted financial entries are immutable.** MUST NOT update or delete a posted `LedgerEntry`
  or `Journal` in place.
- Corrections MUST use **reversal**, **replacement**, or **adjustment** entries that reference
  the original — never a silent overwrite of history.
- **Account balances are derived, not authoritative.** A cached `balance` column MAY exist
  later for performance, but MUST always be reproducible by summing ledger postings. Never
  treat a mutable balance field as ground truth.
- The ledger MUST always be mathematically reproducible from its postings alone.
- Any operation that writes more than one related financial row (journal + entries + links)
  MUST be wrapped in a DB transaction and roll back atomically on failure.
- Categories, journal types, entry sides, and reconciliation states MUST be represented with
  PHP enums or DB-level controlled vocabularies — never magic strings scattered through code.

## 7. Raw SMS Ingestion Rules

There is **no document upload, no PDF parsing, no OCR** for financial SMS. Input is always
raw pasted text into a textarea (single message now, multi-message paste later, matching the
`mockup2.png` "Paste Financial Messages" screen).

Pipeline (MUST follow this order, do not skip steps):

```
raw pasted text → raw storage → normalization → provider detection →
deterministic parser → AI fallback (only if deterministic parsing fails) →
structured extraction → validation against source text → duplicate detection →
proposed transaction → user confirmation (where required) → ledger posting
```

- The original `raw_text` MUST be preserved byte-for-byte forever as evidence. MUST NOT be
  overwritten, normalized in place, or mutated.
- Provider-specific deterministic parsers (`MpesaParser`, `MshwariParser`, `KcbMpesaParser`,
  `BankSmsParser`) MUST be tried first. `GenericFinancialMessageParser` / AI fallback is only
  for messages a known parser can't confidently handle.
- A known deterministic parser + all required fields present + duplicate checks passed MAY be
  configured for safe auto-posting. Anything AI-parsed or ambiguous MUST require explicit user
  confirmation (Confirm / Edit / Reject), matching the mockup's Transaction Preview panel.
- Unknown messages MUST NOT silently become financial records.
- **Duplicate detection is mandatory**, layered:
  1. Provider + external transaction/reference ID (DB `UNIQUE` constraint where possible).
  2. Hash of normalized raw text.
  3. Fingerprint of (provider, account, amount, timestamp, counterparty, type).
  - Fuzzy similarity MAY only **flag** possible duplicates for human review. It MUST NOT
    auto-delete or auto-merge financial records.
- An SMS-reported balance (e.g. "New M-PESA balance is KSh X") MUST be stored as a
  `balance_observations` row (observed vs. calculated, with a difference and reconciliation
  status) — MUST NOT be used to directly overwrite an account's balance.

## 8. AI Safety Rules

- The AI provider MUST NOT receive database credentials, arbitrary SQL access, or any
  "execute SQL" style tool. No exceptions.
- All AI access to data goes through named, backend-controlled application services /
  "tools" — e.g. `getFinancialSummary()`, `getTransactions()`, `getCategorySpending()`,
  `compareFinancialPeriods()`, `getAccountBalances()`, `getSavingsGoalProgress()`,
  `calculateWishlistAffordability()`, `getTaskSummary()`, `getGoalStatus()`. The backend
  computes; the model explains. MUST NOT ask the model to sum/aggregate raw transactions when
  the backend can compute the exact figure.
- AI MAY: parse text, classify/suggest categories, explain trends, generate narrative reports,
  answer natural-language questions, flag possible anomalies.
- AI MUST NOT: write to ledger tables directly, execute SQL, compute an authoritative balance,
  create authoritative financial records without passing through backend validation, silently
  "correct" a transaction, or invent missing financial data (amounts, dates, counterparties).
- Structured AI output (e.g. parsed-SMS JSON) is a **proposal only**. Schema-valid JSON is not
  automatically correct — the backend MUST independently validate every field against the raw
  source message before it can be posted. Track field-level verification state where it
  matters (`amount_verified`, `transaction_id_verified`, `date_verified`,
  `counterparty_verified`, `fee_verified`, `balance_verified`).
- The Claude API key MUST NOT be exposed to frontend JavaScript. All AI calls go through
  Laravel server-side.
- Build a small `AIProviderInterface` (e.g. `parseFinancialMessage()`,
  `categorizeTransaction()`, `answerQuestion()`, `generateReport()`) with `ClaudeProvider` as
  the first implementation, so an `OpenAIProvider` can be added later without touching domain
  code. Don't over-abstract beyond what's needed for that swap.
- RAG/embeddings are **not required** for structured financial querying — use SQL/services.
  RAG MAY be considered later for unstructured text (notes, reflections, long-form reports),
  not for transactions.

## 9. Database Conventions

- Design tables around the domains listed in §5. Do not create every conceivable table up
  front — add migrations incrementally as each module is actually implemented.
- Core finance tables: `financial_accounts` / `ledger_accounts`, `journals`, `ledger_entries`,
  `transaction_categories`, `balance_observations`, reconciliation-related tables.
- Ingestion tables: `financial_messages`, `parse_attempts`, `proposed_transactions`, duplicate
  candidate tracking where needed.
- Goals: `goals`, and allocation history (`goal_allocation_events` or similar) rather than a
  bare mutable number.
- Wishlist: `wishlist_items`.
- Shopping: `purchases`, `purchase_items`, `merchants`, product/category entities only if
  actually justified.
- Productivity: `tasks` (kept minimal for V1).
- AI: conversation/message tables only if conversations are persisted; a tool-execution audit
  table; model usage tracking if useful.
- Governance: `audit_events`.
- Every user-owned table MUST have a `user_id` foreign key, even while there's only one user.
- Use proper foreign keys and DB-level constraints (including `UNIQUE`) for identifiers that
  must be unique (e.g. provider + external transaction id). Don't rely on application code
  alone to prevent duplicate financial rows.
- Prefer explicit, descriptive migration names; one coherent concern per migration.

## 10. Money Handling Rules

- MUST NOT use `FLOAT` or `DOUBLE` for any monetary value.
- MUST store money as **BIGINT minor units** (e.g. KSh 5,000.00 → `500000`) unless there is a
  compelling, explicitly documented reason to use `DECIMAL` with explicit precision instead.
  BIGINT minor units is the default for this project.
- Every monetary column/record MUST be unambiguously associated with a currency. Primary
  currency is **KES** initially; don't hardcode KES so deeply that adding a second currency
  later requires a rewrite, but don't build multi-currency support now either.

## 11. PWA Requirements

- The app MUST be installable as a PWA: web app manifest, service worker, appropriate icon
  set, `display: standalone`.
- Provide an offline-friendly **app shell** where practical (static assets, navigation chrome)
  and graceful degradation when the network is unavailable (clear "you're offline" state, not
  a broken blank page).
- Provide a clear **update strategy** (e.g. service worker versioning + prompt-to-refresh) so
  deployed changes reach installed users predictably.
- MUST NOT queue financial writes offline for later sync unless a safe, explicitly designed
  synchronization mechanism (idempotency keys, conflict handling, server-side validation) is
  built for it. Default assumption: **finance is online-only.** Do not build "works fully
  offline" as an implicit expectation for money-affecting actions.

## 12. Security Requirements

MUST account for, and MUST NOT skip:
- Laravel's standard auth (secure password hashing, session protection, CSRF protection).
- Authorization checks on every financial read/write (policy/gate per domain, not ad hoc).
- MFA-readiness in the auth design (even if not implemented in V1).
- Rate limiting on auth and AI endpoints.
- Secure, HTTP-only cookies; HTTPS in all non-local environments.
- Secrets (Claude API key, DB credentials, app key) live in environment config, are never
  committed to git, and are never sent to the frontend.
- Strong server-side validation (Form Requests) and output escaping (Blade/Livewire escaping
  by default — don't defeat it).
- All queries go through Eloquent/query builder bindings — no raw string-interpolated SQL.
- Least-privilege DB credentials for the app's DB user.
- A backup strategy for the database MUST be considered part of "done" for the finance module,
  even if V1's implementation is simple (e.g. scheduled `mysqldump` via cron).

## 13. Audit Requirements

- Important actions MUST be recorded to an audit trail: transaction created/posted/reversed/
  rejected, SMS parsed, duplicate detected, AI parse accepted/rejected, account created,
  savings allocation changed, important settings changed.
- MUST NOT log passwords, API keys, or auth secrets/tokens.
- Posted financial records MUST be traceable back to their source (manual entry vs. which
  `financial_messages` row, which parser/version, which AI response if applicable).

## 14. Testing Requirements

Financial logic is not "done" without automated tests. At minimum, cover:
income posting, expense posting, internal transfer, fees, savings deposit, savings withdrawal,
reversal, duplicate detection, transaction-id uniqueness, ledger balance correctness, the
balanced-journal invariant (debits = credits always), account balance derivation from
postings, SMS reconciliation, "ambiguous SMS is never auto-posted," "AI parse cannot bypass
backend validation," "a transfer can never be miscounted as income/expense," and "virtual
savings allocations never increase actual money / are never double-counted in net worth."

- Prefer **unit/domain tests** for accounting logic (services, ledger math) and **feature
  tests** for end-to-end user workflows (paste SMS → confirm → posted ledger entry visible).
- Run relevant tests after any change touching finance, ingestion, or savings logic — not just
  at the end of a task.
- A finance-module change is not complete without passing tests that actually exercise the
  new/changed behavior — not just "the app boots."

## 15. UI / Mockup Guidance

Four mockups exist in the repo root (`mockups.png`, `mockup2.png`, `mockup3.png`,
`mockup4.png`) depicting "Life OS" — Dashboard, Messages (paste SMS), Savings Goals &
Wishlist, and Daily Planning & Operations (Tasks) screens respectively. Treat them as **design
references for structure, not literal specs**:

Use them for:
- Left sidebar navigation and its ordering (Dashboard, Finance, Messages, Savings Goals,
  Wishlist, Shopping, Tasks, Reports, AI Assistant, Settings).
- Top bar pattern: global search, date-range filter, notifications, "Install App" affordance,
  account menu.
- Dashboard information hierarchy: top KPI cards → cashflow/spending charts → attention items
  (unconfirmed SMS, reconciliation issues) → savings goals → recent transactions → AI
  insights → task snippet. This matches and MUST drive the actual dashboard priority order
  from the project brief (financial state → trends → attention items → savings → recent
  transactions → tasks → AI insights).
- The Messages/paste-SMS screen's shape (raw-text textarea → parsed table with confidence →
  needs-review section → transaction preview with proposed ledger effect → Confirm/Edit/
  Reject) — this maps directly and should be followed closely, it matches the required
  ingestion architecture almost exactly.
- The Savings Goals screen's separation of **real account balances vs. virtual goal
  allocations** ("Allocation Breakdown by Account Source: Real vs Virtual") and the
  Conservative/Current Trend/Aggressive wishlist-affordability columns — this directly matches
  the required architecture and SHOULD be used as the UI shape for that module.
- General layout proportions, card density, responsive/card-grid intent, and the installable-
  PWA affordance shown in the sidebar and top bar.

Do NOT treat as requirements:
- Any specific numbers, names, dates, or sample transactions shown (e.g. "Brian Otieno",
  specific KSh amounts) — placeholder data only.
- The "Life OS Premium / Upgrade Now" upsell card — this implies subscription tiers/billing,
  which is out of scope for a single-user personal system unless the user explicitly asks for
  it later.
- The Tasks/"Daily Planning & Operations" mockup's Calendar, Habit Check-In, and Notes &
  Reflection widgets — these represent **future modules** (Calendar, Habits, Notes) per the
  brief's phase plan and MUST NOT be pulled into the V1 Tasks module just because the mockup
  shows them together. V1 Tasks is the simple task list only.
- If any mockup detail conflicts with a financial-integrity rule in this document (e.g. an
  implied instant overwrite of a balance from an SMS), the rule in this document wins.

## 16. Coding Conventions

- Inspect existing code before changing architecture or adding new patterns.
- Prefer readable, conventional Laravel code over clever abstractions.
- Use clear, domain-accurate names (`LedgerEntry`, not `Row`; `postTransfer()`, not
  `doTransfer()`).
- Keep methods and classes reasonably small and single-purpose.
- Use Form Requests (or equivalent explicit validation) for all financial and user-input
  writes.
- Use typed PHP (property types, param/return types) throughout.
- Use PHP enums for closed sets of financial/domain states (journal type, entry side,
  reconciliation status, parse status, wishlist status, etc.) instead of magic strings.
- Avoid giant controllers and giant Livewire components — extract to services when logic
  grows past simple coordination.
- Avoid a premature repository layer when Eloquent + services are sufficient.
- Avoid adding packages/dependencies unless they clearly earn their cost, especially anything
  that threatens shared-hosting compatibility.
- Reuse existing components/services rather than duplicating logic.
- Run relevant tests (and lint/static analysis if configured) after meaningful changes.
- Call out and explain any migration that affects financial integrity in your response to the
  user — don't just apply it silently.
- Never casually rewrite accounting logic "while you're in there" — that's a deliberate,
  reviewed change (see §21/§22), not a drive-by refactor.

## 17. Shared-Hosting Constraints

**Deployment target:** production domain is `douglas.waterliftsolarsavings.africa`, a single-owner
deployment (owner: Douglas). `APP_URL`, the PWA manifest `start_url`/`scope`, and any
CORS/CSRF trusted-host config MUST use this domain in production. Treat it as a subdomain
deployment on shared/managed hosting unless told otherwise — don't assume a dedicated VPS.

Assume initial deployment targets ordinary PHP/MySQL shared hosting:
- Laravel + MySQL + cron + database-backed queue + normal filesystem storage + outbound HTTPS
  calls (to the Claude API) — nothing more exotic.
- MUST NOT require a permanently-running queue worker process for core functionality; design
  background work to run gracefully via the Scheduler/cron (e.g. `queue:work --stop-when-
  empty` on a schedule, or synchronous processing where volume is low).
- Any feature that needs infrastructure ordinary shared hosting can't provide MUST degrade to
  a working basic version without that infrastructure, rather than becoming a hard dependency.
- Design so the app can move to a VPS/managed Laravel host/cloud later **without** changing
  core domain architecture — better hosting should only relax constraints, not require a
  rewrite.

## 18. Development Workflow (Phase Order)

Do not build later phases before earlier ones are solid — especially don't build savings,
wishlist, shopping, or advanced modules before the ledger foundation and manual finance flows
are reliable and tested.

1. Core Laravel app, auth, PWA foundation, DB architecture, financial accounts, ledger,
   categories, audit foundation.
2. Manual finance: income, expense, transfers, transaction history, balances, reversals.
3. Raw SMS paste UI, M-Pesa deterministic parser, duplicate protection, preview, confirmation,
   posting.
4. M-Shwari / KCB M-Pesa / bank SMS parsers, Claude fallback parsing, confidence/validation.
5. Balance reconciliation.
6. Savings goals, virtual allocations, wishlist, affordability engine.
7. Financial dashboard, reports, read-only AI financial assistant.
8. Shopping.
9. Tasks and broader personal management.
10. Health, habits, notes, calendar, and other advanced modules — only if still wanted.

## 19. V1 Scope

Secure auth; installable PWA; financial accounts; double-entry ledger; income/expenses/
transfers/fees; reversals & corrections; raw SMS paste; M-Pesa/M-Shwari/KCB M-Pesa parsing
(+ basic bank SMS parsing where feasible); duplicate prevention; confirmation workflow;
categories; balances; balance reconciliation; savings goals with virtual allocations;
wishlist with deterministic affordability scenarios; financial dashboard; recent transactions;
monthly reports; read-only AI financial assistant; basic personal tasks. **No document
upload for financial SMS processing, ever, in V1.**

## 20. Explicit Out-of-Scope Items (for now)

- Document/PDF upload or OCR for financial statements.
- Multi-user tenancy, teams, invites, roles/permissions beyond a single owner account.
- Subscription billing / premium tiers (seen in the mockup but not requested).
- Habits, Calendar, Notes, Projects, Health, Weight tracking, Workouts, Meals, Nutrition,
  general Personal goals engine, Achievements — all future modules, do not scaffold early.
- A generic/abstract goal engine beyond the simple `goals` structure described in §9.
- Item-level shopping detail inferred from SMS text alone (must come from an actual purchase
  record, never invented from a payment SMS).
- RAG/embeddings for structured financial data.
- Full offline-capable financial writes / offline sync queue.
- Docker, microservices, Redis, Postgres, a separate SPA/backend.

## 21. Rules Before Modifying Financial Logic

Before touching **anything** that affects ledger logic, transaction posting, balance
calculation, reversals, transfers, duplicate detection, reconciliation, or SMS financial
extraction, Claude Code MUST:

1. Read this file's §6–§10 again in the context of the specific change.
2. Inspect the related models, migrations, services, and existing tests for that area.
3. Confirm the change preserves: balanced double-entry postings, immutability of posted
   entries, correct transfer-vs-income/expense classification, and derivability of balances
   from postings.
4. Prefer the smallest coherent change that satisfies the request — do not restructure
   surrounding financial logic "for cleanliness" as a side effect.
5. Add or update tests covering the change (see §14) before considering it done.
6. If the requested change would violate a MUST/MUST NOT rule in this document (e.g. "just
   overwrite the balance from the SMS," "let the AI post directly," "delete the old
   transaction and insert a corrected one"), Claude Code MUST say so explicitly and propose
   the compliant alternative instead of implementing the unsafe version.

## 22. Rules for Migrations and Schema Changes

- Financial-schema migrations are never casual. Before writing one, identify which existing
  models/services/tests depend on the affected tables.
- Additive changes (new nullable column, new table) are lower risk; explain them briefly.
- Changes that alter meaning (renaming a monetary column, changing a status enum's values,
  altering a constraint on ledger tables) MUST be called out explicitly to the user, including
  what data migration or backfill is needed and what breaks if it's skipped.
- Never drop or destructively alter a column holding posted financial data without explicit
  user confirmation and a described migration/backfill path.
- Keep one coherent concern per migration; don't bundle unrelated schema changes together.

## 23. Rules for Handling Uncertainty

- If a request is ambiguous about financial behavior (e.g. "just make it post automatically"
  without specifying validation), ask or state the safe default being applied (deterministic-
  only auto-post, everything else requires confirmation) rather than guessing toward the
  riskier interpretation.
- If a mockup, past instruction, and this document disagree, financial-integrity rules in this
  document win; state the conflict rather than silently picking one.
- When unsure whether a change touches "financial integrity," treat it as if it does and apply
  §21.
- Don't invent product requirements from mockup placeholder data (see §15) — when a mockup
  implies a feature not described in the brief (e.g. subscription billing), flag it as an
  assumption rather than building it.

## 24. Definition of Done (per feature)

A feature is done when:
- It behaves correctly for the happy path and the realistic edge cases in its domain (e.g. for
  finance: zero/negative amounts, duplicate SMS, ambiguous parses, insufficient balance for a
  transfer).
- Financial invariants from §6 still hold (balanced postings, immutability, correct transfer
  classification, derivable balances) — verified by tests, not just by inspection.
- Relevant automated tests exist and pass (§14).
- Inputs are validated server-side; outputs are properly escaped.
- New/changed database structures follow §9/§22 and are documented in the change summary.
- Audit events are recorded for any newly-introduced important action (§13).
- No secrets, credentials, or raw AI prompts/keys are logged or exposed to the frontend.
- The user-facing summary states what changed, what was tested, and any known risk or
  follow-up work — no silent partial implementations presented as complete.

---

## Before Starting Any Task

1. Read this `CLAUDE.md`.
2. Inspect relevant existing source files for the area being touched.
3. Inspect relevant existing tests for that area.
4. If the task changes UI, look at the relevant mockup(s) in the repo root for structural
   guidance (see §15).
5. Identify whether the task touches financial integrity (§6–§10, §21) — if unsure, assume
   yes.
6. Preserve existing working functionality unless the change explicitly requires altering it.
7. Make the smallest coherent change that correctly satisfies the request.
8. Run the relevant tests/lint/build checks for what was touched.
9. Report back: what changed, what was verified, and any unresolved risks or follow-ups —
   plainly, without hedging or overclaiming completeness.

## Financial Change Protocol

Any task that changes ledger logic, transaction posting, balance calculations, reversals,
transfers, duplicate detection, reconciliation, or SMS financial extraction MUST follow §21
before any code is written: inspect the related models, migrations, services, and tests first.
Financial schema changes additionally follow §22. If a future instruction from the user
conflicts with a financial-integrity invariant in this document, Claude Code MUST point out
the conflict explicitly and propose a compliant approach rather than silently implementing the
unsafe version.
