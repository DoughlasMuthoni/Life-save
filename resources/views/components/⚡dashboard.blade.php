<?php

use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Models\Journal;
use App\Domain\Finance\Services\FinancialReportingService;
use App\Domain\Goals\Services\SavingsAllocationService;
use App\Domain\Finance\Support\Money;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Models\ProposedTransaction;
use App\Domain\Finance\Enums\ReconciliationStatus;
use App\Domain\Finance\Models\BalanceObservation;
use App\Domain\Finance\Models\FinancialAccount;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public function getHasAccountsProperty(): bool
    {
        return FinancialAccount::query()->where('user_id', auth()->id())->where('is_active', true)->exists();
    }

    public function getSummaryProperty(FinancialReportingService $reports)
    {
        $user = auth()->user();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        return [
            'net_available_cash' => $reports->netAvailableCashMinor($user, app(SavingsAllocationService::class)),
            'mpesa_balance' => $reports->mpesaBalanceMinor($user),
            'savings_total' => $reports->savingsTotalMinor($user),
            'this_month' => $reports->getFinancialSummary($user, $monthStart, $monthEnd),
        ];
    }

    public function getComparisonProperty(FinancialReportingService $reports)
    {
        $user = auth()->user();

        return $reports->compareFinancialPeriods(
            $user,
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
            now()->startOfMonth(),
            now()->endOfMonth(),
        );
    }

    public function getCategorySpendingProperty(FinancialReportingService $reports)
    {
        return $reports->getCategorySpending(auth()->user(), now()->startOfMonth(), now()->endOfMonth())->take(5);
    }

    public function getAttentionItemsProperty(FinancialReportingService $reports)
    {
        $user = auth()->user();
        $items = [];

        $unconfirmed = ProposedTransaction::where('user_id', $user->id)->where('status', ProposedTransactionStatus::PENDING_REVIEW)->count();
        if ($unconfirmed > 0) {
            $items[] = ['label' => "{$unconfirmed} unconfirmed SMS ".Str::plural('transaction', $unconfirmed), 'url' => route('finance.messages'), 'tone' => 'amber'];
        }

        $duplicates = ProposedTransaction::where('user_id', $user->id)->where('status', ProposedTransactionStatus::DUPLICATE)->count();
        if ($duplicates > 0) {
            $items[] = ['label' => "{$duplicates} possible duplicate ".Str::plural('transaction', $duplicates), 'url' => route('finance.messages'), 'tone' => 'amber'];
        }

        $mismatches = BalanceObservation::where('user_id', $user->id)->where('reconciliation_status', ReconciliationStatus::MISMATCHED)->count();
        if ($mismatches > 0) {
            $items[] = ['label' => "{$mismatches} reconciliation ".Str::plural('mismatch', $mismatches), 'url' => route('finance.reconciliation'), 'tone' => 'red'];
        }

        $behind = $reports->goalsBehindTarget($user);
        if ($behind->isNotEmpty()) {
            $items[] = ['label' => "{$behind->count()} savings ".Str::plural('goal', $behind->count())." behind schedule", 'url' => route('savings-goals'), 'tone' => 'amber'];
        }

        $unusual = $reports->detectUnusualSpending($user, now()->startOfMonth());
        if ($unusual->isNotEmpty()) {
            $items[] = ['label' => "Unusual spending in {$unusual->first()['name']}", 'url' => route('reports.monthly'), 'tone' => 'amber'];
        }

        return $items;
    }

    public function getRecentTransactionsProperty()
    {
        return Journal::query()
            ->where('user_id', auth()->id())
            ->with('entries.ledgerAccount')
            ->latest('occurred_at')
            ->limit(5)
            ->get();
    }

    public function getSavingsGoalsProperty(FinancialReportingService $reports)
    {
        return $reports->getSavingsGoalProgress(auth()->user())->take(3);
    }
};
?>

<div>
    <x-ui.page-header :title="'Welcome, '.explode(' ', auth()->user()->name)[0]" :subtitle="now()->format('l, F j, Y')" />

    @if (! $this->hasAccounts)
        <x-ui.empty-state icon="wallet" title="No financial accounts yet" description="Add your M-Pesa, bank, or cash accounts to start seeing your financial picture here." class="mt-6">
            <x-slot:actions>
                <x-ui.button :href="route('finance.accounts')" variant="primary">
                    <x-icon name="plus" class="h-4 w-4" /> Add your first account
                </x-ui.button>
            </x-slot:actions>
        </x-ui.empty-state>
    @else
        {{-- 1. Financial state --}}
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="Net Available Cash" icon="wallet" color="blue">
                {{ Money::formatMinor($this->summary['net_available_cash']) }}
            </x-ui.stat-card>
            <x-ui.stat-card label="M-Pesa Balance" icon="phone" color="green">
                {{ Money::formatMinor($this->summary['mpesa_balance']) }}
            </x-ui.stat-card>
            <x-ui.stat-card label="Savings Total" icon="flag" color="purple">
                {{ Money::formatMinor($this->summary['savings_total']) }}
            </x-ui.stat-card>
            <x-ui.stat-card label="This Month Spending" icon="cash" color="amber">
                {{ Money::formatMinor($this->summary['this_month']['expense_minor']) }}
            </x-ui.stat-card>
        </div>

        {{-- 2. Changes / trends --}}
        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-ui.section title="This month vs last month">
                <div class="grid grid-cols-2 gap-4 p-5 text-sm">
                    <div>
                        <p class="text-slate-500">Income</p>
                        <p class="mt-0.5 text-lg font-semibold text-slate-900">{{ Money::formatMinor($this->comparison['period_b']['income_minor']) }}</p>
                        @if ($this->comparison['income_change_percent'] !== null)
                            <p class="mt-0.5 flex items-center gap-1 text-xs font-medium {{ $this->comparison['income_change_percent'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                <x-icon :name="$this->comparison['income_change_percent'] >= 0 ? 'trend-up' : 'trend-down'" class="h-3.5 w-3.5" />
                                {{ $this->comparison['income_change_percent'] >= 0 ? '+' : '' }}{{ $this->comparison['income_change_percent'] }}%
                            </p>
                        @endif
                    </div>
                    <div>
                        <p class="text-slate-500">Expenses</p>
                        <p class="mt-0.5 text-lg font-semibold text-slate-900">{{ Money::formatMinor($this->comparison['period_b']['expense_minor']) }}</p>
                        @if ($this->comparison['expense_change_percent'] !== null)
                            <p class="mt-0.5 flex items-center gap-1 text-xs font-medium {{ $this->comparison['expense_change_percent'] <= 0 ? 'text-green-600' : 'text-red-600' }}">
                                <x-icon :name="$this->comparison['expense_change_percent'] >= 0 ? 'trend-up' : 'trend-down'" class="h-3.5 w-3.5" />
                                {{ $this->comparison['expense_change_percent'] >= 0 ? '+' : '' }}{{ $this->comparison['expense_change_percent'] }}%
                            </p>
                        @endif
                    </div>
                </div>
                <div class="border-t border-slate-100 px-5 py-3">
                    <a href="{{ route('reports.monthly') }}" class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700">
                        View full report <x-icon name="chevron-right" class="h-3 w-3" />
                    </a>
                </div>
            </x-ui.section>

            <x-ui.section title="Top categories this month">
                <div class="divide-y divide-slate-100">
                    @forelse ($this->categorySpending as $i => $category)
                        <div class="flex items-center justify-between px-5 py-2.5 text-sm">
                            <span class="flex items-center gap-2 text-slate-600">
                                <span class="h-2 w-2 shrink-0 rounded-full {{ ['bg-blue-500', 'bg-purple-500', 'bg-amber-500', 'bg-green-500', 'bg-red-400'][$i % 5] }}"></span>
                                {{ $category['name'] }}
                            </span>
                            <span class="font-medium text-slate-900">{{ Money::formatMinor($category['amount_minor']) }}</span>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-sm text-slate-400">No spending yet this month.</p>
                    @endforelse
                </div>
            </x-ui.section>
        </div>

        {{-- 3. Items requiring attention --}}
        @if (count($this->attentionItems) > 0)
            <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
                <h2 class="flex items-center gap-1.5 text-sm font-semibold text-amber-800">
                    <x-icon name="warning" class="h-4 w-4" /> Needs attention
                </h2>
                <div class="mt-2 space-y-1.5">
                    @foreach ($this->attentionItems as $item)
                        <a href="{{ $item['url'] }}" class="flex items-center gap-1 text-sm font-medium {{ $item['tone'] === 'red' ? 'text-red-700 hover:text-red-800' : 'text-amber-800 hover:text-amber-900' }}">
                            {{ $item['label'] }} <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 4. Savings goals --}}
        @if ($this->savingsGoals->isNotEmpty())
            <x-ui.section title="Savings goals" class="mt-6">
                <x-slot:actions>
                    <a href="{{ route('savings-goals') }}" class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700">
                        View all <x-icon name="chevron-right" class="h-3 w-3" />
                    </a>
                </x-slot:actions>
                <div class="space-y-4 p-5">
                    @foreach ($this->savingsGoals as $goal)
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-slate-700">{{ $goal['title'] }}</span>
                                <span class="font-medium text-slate-500">{{ $goal['progress_percent'] }}%</span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full bg-slate-100">
                                <div class="h-1.5 rounded-full bg-blue-600" style="width: {{ $goal['progress_percent'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.section>
        @endif

        {{-- 5. Recent transactions --}}
        <x-ui.section title="Recent transactions" class="mt-6">
            <x-slot:actions>
                <a href="{{ route('finance.transactions') }}" class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700">
                    View all <x-icon name="chevron-right" class="h-3 w-3" />
                </a>
            </x-slot:actions>
            <div class="divide-y divide-slate-100">
                @forelse ($this->recentTransactions as $journal)
                    @php
                        $typeColor = match ($journal->journal_type->value) {
                            'income' => 'green',
                            'expense' => 'red',
                            'transfer' => 'blue',
                            default => 'slate',
                        };
                        $typeIcon = match ($journal->journal_type->value) {
                            'income' => 'trend-up',
                            'expense' => 'trend-down',
                            'transfer' => 'arrow-path',
                            default => 'list',
                        };
                    @endphp
                    <div class="flex items-center gap-3 px-5 py-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ ['green' => 'bg-green-50 text-green-600', 'red' => 'bg-red-50 text-red-600', 'blue' => 'bg-blue-50 text-blue-600', 'slate' => 'bg-slate-100 text-slate-500'][$typeColor] }}">
                            <x-icon :name="$typeIcon" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-slate-900">{{ $journal->description ?: ucfirst($journal->journal_type->value) }}</p>
                            <p class="text-xs text-slate-400">{{ $journal->occurred_at->format('M j, Y') }}</p>
                        </div>
                        <x-ui.badge :color="$typeColor">{{ ucfirst($journal->journal_type->value) }}</x-ui.badge>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No transactions yet.</p>
                @endforelse
            </div>
        </x-ui.section>

        {{-- 7. Insights (deterministic — see FinancialReportingService; open-ended questions go to the AI Assistant) --}}
        <x-ui.section title="Insights" class="mt-6">
            <x-slot:actions>
                <a href="{{ route('ai-assistant') }}" class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700">
                    <x-icon name="sparkles" class="h-3.5 w-3.5" /> Ask the AI Assistant
                </a>
            </x-slot:actions>
            <div class="space-y-2 p-5 text-sm text-slate-600">
                @if ($this->comparison['expense_change_percent'] !== null)
                    <p class="flex items-start gap-2">
                        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                        You've spent {{ abs($this->comparison['expense_change_percent']) }}%
                        {{ $this->comparison['expense_change_percent'] <= 0 ? 'less' : 'more' }} than last month.
                    </p>
                @endif
                @if ($this->categorySpending->isNotEmpty())
                    <p class="flex items-start gap-2">
                        <x-icon name="tag" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                        Your top spending category this month is {{ $this->categorySpending->first()['name'] }}.
                    </p>
                @endif
            </div>
        </x-ui.section>
    @endif
</div>
