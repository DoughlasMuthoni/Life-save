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
    <h1 class="text-xl font-semibold text-slate-900">Welcome, {{ auth()->user()->name }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ now()->format('l, F j, Y') }}</p>

    @if (! $this->hasAccounts)
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center">
            <p class="text-sm text-slate-600">You don't have any financial accounts yet.</p>
            <a href="{{ route('finance.accounts') }}" class="mt-3 inline-block rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                Add your first account
            </a>
        </div>
    @else
        {{-- 1. Financial state --}}
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-sm text-slate-500">Net Available Cash</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ Money::formatMinor($this->summary['net_available_cash']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-sm text-slate-500">M-Pesa Balance</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ Money::formatMinor($this->summary['mpesa_balance']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-sm text-slate-500">Savings Total</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ Money::formatMinor($this->summary['savings_total']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-sm text-slate-500">This Month Spending</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ Money::formatMinor($this->summary['this_month']['expense_minor']) }}</p>
            </div>
        </div>

        {{-- 2. Changes / trends --}}
        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-slate-700">This month vs last month</h2>
                <div class="mt-3 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <p class="text-slate-500">Income</p>
                        <p class="font-medium text-slate-900">{{ Money::formatMinor($this->comparison['period_b']['income_minor']) }}</p>
                        @if ($this->comparison['income_change_percent'] !== null)
                            <p class="text-xs {{ $this->comparison['income_change_percent'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $this->comparison['income_change_percent'] >= 0 ? '+' : '' }}{{ $this->comparison['income_change_percent'] }}%
                            </p>
                        @endif
                    </div>
                    <div>
                        <p class="text-slate-500">Expenses</p>
                        <p class="font-medium text-slate-900">{{ Money::formatMinor($this->comparison['period_b']['expense_minor']) }}</p>
                        @if ($this->comparison['expense_change_percent'] !== null)
                            <p class="text-xs {{ $this->comparison['expense_change_percent'] <= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $this->comparison['expense_change_percent'] >= 0 ? '+' : '' }}{{ $this->comparison['expense_change_percent'] }}%
                            </p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('reports.monthly') }}" class="mt-3 inline-block text-xs font-medium text-blue-600 hover:text-blue-700">View full report &rarr;</a>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-slate-700">Top categories this month</h2>
                <div class="mt-3 space-y-2">
                    @forelse ($this->categorySpending as $category)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-600">{{ $category['name'] }}</span>
                            <span class="font-medium text-slate-900">{{ Money::formatMinor($category['amount_minor']) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">No spending yet this month.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- 3. Items requiring attention --}}
        @if (count($this->attentionItems) > 0)
            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50/40 p-4">
                <h2 class="text-sm font-semibold text-amber-800">Needs attention</h2>
                <div class="mt-2 space-y-1">
                    @foreach ($this->attentionItems as $item)
                        <a href="{{ $item['url'] }}" class="block text-sm font-medium {{ $item['tone'] === 'red' ? 'text-red-700' : 'text-amber-800' }} hover:underline">
                            {{ $item['label'] }} &rarr;
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 4. Savings goals --}}
        @if ($this->savingsGoals->isNotEmpty())
            <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-700">Savings goals</h2>
                    <a href="{{ route('savings-goals') }}" class="text-xs font-medium text-blue-600 hover:text-blue-700">View all &rarr;</a>
                </div>
                <div class="mt-3 space-y-3">
                    @foreach ($this->savingsGoals as $goal)
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-slate-700">{{ $goal['title'] }}</span>
                                <span class="text-slate-500">{{ $goal['progress_percent'] }}%</span>
                            </div>
                            <div class="mt-1 h-1.5 rounded-full bg-slate-100">
                                <div class="h-1.5 rounded-full bg-blue-600" style="width: {{ $goal['progress_percent'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 5. Recent transactions --}}
        <div class="mt-6 rounded-xl border border-slate-200 bg-white">
            <div class="flex items-center justify-between px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-700">Recent transactions</h2>
                <a href="{{ route('finance.transactions') }}" class="text-xs font-medium text-blue-600 hover:text-blue-700">View all &rarr;</a>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($this->recentTransactions as $journal)
                    <div class="flex items-center justify-between px-4 py-3 text-sm">
                        <div>
                            <p class="text-slate-900">{{ $journal->description ?: ucfirst($journal->journal_type->value) }}</p>
                            <p class="text-xs text-slate-400">{{ $journal->occurred_at->format('M j, Y') }}</p>
                        </div>
                        <span class="text-slate-500">{{ ucfirst($journal->journal_type->value) }}</span>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-slate-500">No transactions yet.</p>
                @endforelse
            </div>
        </div>

        {{-- 7. Insights (deterministic — see FinancialReportingService; open-ended questions go to the AI Assistant) --}}
        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Insights</h2>
                <a href="{{ route('ai-assistant') }}" class="text-xs font-medium text-blue-600 hover:text-blue-700">Ask the AI Assistant &rarr;</a>
            </div>
            <div class="mt-2 space-y-1 text-sm text-slate-600">
                @if ($this->comparison['expense_change_percent'] !== null)
                    <p>
                        You've spent {{ abs($this->comparison['expense_change_percent']) }}%
                        {{ $this->comparison['expense_change_percent'] <= 0 ? 'less' : 'more' }} than last month.
                    </p>
                @endif
                @if ($this->categorySpending->isNotEmpty())
                    <p>Your top spending category this month is {{ $this->categorySpending->first()['name'] }}.</p>
                @endif
            </div>
        </div>
    @endif
</div>
