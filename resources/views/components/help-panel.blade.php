@php
    // Grouped the same way as the sidebar nav, so "what does this button do"
    // and "where do I find it" always agree with each other. Kept as plain
    // static copy (not per-route dynamic lookup) — the ask was "explain
    // everything happening in all modules" in one place, not a different
    // popup per page.
    $helpSections = [
        'Dashboard' => [
            'icon' => 'home',
            'items' => [
                ['Dashboard', 'The one-glance view of where you stand: net available cash, M-Pesa/savings balances, this month vs. last month, top spending categories, anything needing attention (unconfirmed SMS, reconciliation mismatches), savings goal progress, recent transactions, and a couple of AI-generated observations. Every figure here is computed by the app, never guessed.'],
            ],
        ],
        'Finance' => [
            'icon' => 'wallet',
            'items' => [
                ['Messages', 'Paste raw M-Pesa (or bank) SMS text here — one or many at once, separated by a blank line. Known formats parse automatically without AI; anything unfamiliar falls back to AI, which only ever produces a proposal. Nothing posts to your ledger until you Confirm it under "Ready to review." Possible duplicates are flagged separately.'],
                ['Accounts', 'The real places your money lives — M-Pesa, M-Shwari, bank, cash. Most are Assets (money you have); a debt like Fuliza should be added as a Liability instead, using the toggle on the "Add account" form. A liability\'s balance is what you owe, shown in red and subtracted from your net available cash, not added to it.'],
                ['Categories', 'Income and expense labels used when recording or confirming a transaction. Set these up before you\'ll be able to record anything.'],
                ['Transactions', 'The full ledger history, newest first. The Income / Expense / Transfer buttons at the top each open a dedicated form — see "How adding money works" below. Reverse posts an offsetting entry; nothing is ever edited or deleted in place.'],
                ['Reconciliation', 'Shows up automatically when an SMS-reported balance doesn\'t match what the ledger calculates. Resolving one requires a note explaining what you found — it never silently overwrites a balance.'],
            ],
        ],
        'Planning' => [
            'icon' => 'flag',
            'items' => [
                ['Savings Goals', 'Set a target and (optionally) a planned monthly contribution. Allocating money to a goal is virtual — it never actually moves out of the real account it lives in, and the breakdown shows real balances vs. what\'s earmarked per account.'],
                ['Wishlist', 'Things you\'re saving toward, each with three affordability projections (Conservative / Current Trend / Aggressive) based on a linked goal\'s contribution pace — never guessed by AI.'],
                ['Shopping', 'What you bought, kept separate from how you paid for it. You can optionally link a purchase to the expense transaction that paid for it.'],
                ['Tasks', 'A plain to-do list — add, complete, or cancel.'],
            ],
        ],
        'Personal' => [
            'icon' => 'document-text',
            'items' => [
                ['Notes', 'Freeform notes — a title (optional) and a body. Edit or delete anytime.'],
                ['Habits', 'A single tap checks a habit in for today. The streak counts consecutive days ending today (or yesterday, if today isn\'t checked in yet).'],
                ['Calendar', 'A plain list of dated events — title, date, optional time and notes. Upcoming and past are shown separately.'],
            ],
        ],
        'Health' => [
            'icon' => 'scale',
            'items' => [
                ['Weight', 'A log of weight entries. Each one shows the change vs. your previous entry.'],
                ['Workouts', 'Type, duration, and optional notes per session.'],
                ['Meals', 'A basic food log with an optional meal type (breakfast/lunch/dinner/snack) — no calorie or macro tracking.'],
            ],
        ],
        'Insights' => [
            'icon' => 'chart',
            'items' => [
                ['Reports', 'A proper monthly report: income/expense/net, comparison to the previous month, category breakdown, and your largest transactions for the period.'],
                ['AI Assistant', 'Ask it things in plain language, e.g. "where did most of my money go this month?" It always pulls real numbers through backend tools — it never estimates or invents a figure itself, and it can\'t write to your ledger.'],
                ['Achievements', 'Read-only badges computed from what you\'ve actually done — habit streaks, completed tasks/goals, purchased wishlist items. Nothing here is manually awarded.'],
            ],
        ],
    ];
@endphp

<div x-data="{ helpOpen: false }">
    <button
        @click="helpOpen = true"
        aria-label="Help"
        class="fixed right-5 bottom-5 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg transition hover:bg-blue-700 hover:shadow-xl"
    >
        <x-icon name="info" class="h-5 w-5" />
    </button>

    <div
        x-show="helpOpen"
        x-transition.opacity
        @click="helpOpen = false"
        class="fixed inset-0 z-50 bg-slate-900/50"
        style="display: none;"
    ></div>

    <div
        x-show="helpOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-xl"
        style="display: none;"
        @keydown.escape.window="helpOpen = false"
    >
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                    <x-icon name="info" class="h-4.5 w-4.5" />
                </span>
                <h2 class="text-base font-semibold text-slate-900">How everything works</h2>
            </div>
            <button @click="helpOpen = false" aria-label="Close" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                <x-icon name="x-mark" class="h-5 w-5" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-5 py-4">
            <p class="text-sm text-slate-500">
                A quick reference for every page in the sidebar, grouped the same way. AI narrates figures here and there — it never calculates or invents one; every number comes from your actual ledger.
            </p>

            <div class="mt-4 space-y-3">
                @foreach ($helpSections as $group => $section)
                    <details class="group rounded-xl border border-slate-200" @if ($loop->first) open @endif>
                        <summary class="flex cursor-pointer list-none items-center gap-2.5 px-4 py-3 select-none">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                                <x-icon :name="$section['icon']" class="h-4 w-4" />
                            </span>
                            <span class="flex-1 text-sm font-semibold text-slate-900">{{ $group }}</span>
                            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" />
                        </summary>
                        <div class="space-y-3 border-t border-slate-100 px-4 py-3">
                            @foreach ($section['items'] as [$name, $description])
                                <div>
                                    <p class="text-sm font-medium text-slate-800">{{ $name }}</p>
                                    <p class="mt-0.5 text-sm text-slate-500">{{ $description }}</p>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>

            <div class="mt-4 rounded-xl border border-blue-100 bg-blue-50/60 p-4">
                <p class="text-sm font-medium text-blue-900">How adding money works</p>
                <p class="mt-1 text-sm text-blue-800">
                    Paste an SMS (Messages) or fill in Record Income / Expense / Transfer manually — both roads end at
                    the same posting logic. SMS just autofills the same form for you, plus independent validation
                    against the original text and duplicate detection, and still waits for your Confirm before
                    anything reaches the ledger.
                </p>
            </div>
        </div>
    </div>
</div>
