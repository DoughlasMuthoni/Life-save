<?php

use App\Domain\Finance\Services\FinancialReportingService;
use App\Domain\Finance\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
    }

    private function periodStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
    }

    private function periodEnd(): Carbon
    {
        return $this->periodStart()->copy()->endOfMonth();
    }

    public function previousMonth(): void
    {
        $this->month = $this->periodStart()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->periodStart()->addMonthNoOverflow()->format('Y-m');
    }

    public function getSummaryProperty(FinancialReportingService $reports)
    {
        return $reports->getFinancialSummary(auth()->user(), $this->periodStart(), $this->periodEnd());
    }

    public function getComparisonProperty(FinancialReportingService $reports)
    {
        $priorStart = $this->periodStart()->copy()->subMonthNoOverflow();
        $priorEnd = $priorStart->copy()->endOfMonth();

        return $reports->compareFinancialPeriods(auth()->user(), $priorStart, $priorEnd, $this->periodStart(), $this->periodEnd());
    }

    public function getCategorySpendingProperty(FinancialReportingService $reports)
    {
        return $reports->getCategorySpending(auth()->user(), $this->periodStart(), $this->periodEnd());
    }

    public function getLargestTransactionsProperty(FinancialReportingService $reports)
    {
        return $reports->getLargestTransactions(auth()->user(), $this->periodStart(), $this->periodEnd());
    }

    public function getAccountBalancesProperty(FinancialReportingService $reports)
    {
        return $reports->getAccountBalances(auth()->user());
    }
};
?>

<div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Monthly Report</h1>
            <p class="mt-1 text-sm text-slate-500">Deterministic figures, calculated directly from your ledger.</p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="previousMonth" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">&larr;</button>
            <span class="w-32 text-center text-sm font-medium text-slate-900">{{ $this->periodStart()->format('F Y') }}</span>
            <button wire:click="nextMonth" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">&rarr;</button>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Income</p>
            <p class="mt-1 text-lg font-semibold text-slate-900">{{ Money::formatMinor($this->summary['income_minor']) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Expenses</p>
            <p class="mt-1 text-lg font-semibold text-slate-900">{{ Money::formatMinor($this->summary['expense_minor']) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Net Cash Flow</p>
            <p class="mt-1 text-lg font-semibold {{ $this->summary['net_cash_flow_minor'] >= 0 ? 'text-slate-900' : 'text-red-600' }}">
                {{ Money::formatMinor($this->summary['net_cash_flow_minor']) }}
            </p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Savings Rate</p>
            <p class="mt-1 text-lg font-semibold text-slate-900">{{ $this->summary['savings_rate_percent'] }}%</p>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-slate-700">Compared to the previous month</h2>
        <div class="mt-3 grid grid-cols-2 gap-6 text-sm sm:grid-cols-4">
            <div>
                <p class="text-slate-500">Income</p>
                <p class="font-medium {{ ($this->comparison['income_change_percent'] ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $this->comparison['income_change_percent'] === null ? '—' : ($this->comparison['income_change_percent'] >= 0 ? '+' : '').$this->comparison['income_change_percent'].'%' }}
                </p>
            </div>
            <div>
                <p class="text-slate-500">Expenses</p>
                <p class="font-medium {{ ($this->comparison['expense_change_percent'] ?? 0) <= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $this->comparison['expense_change_percent'] === null ? '—' : ($this->comparison['expense_change_percent'] >= 0 ? '+' : '').$this->comparison['expense_change_percent'].'%' }}
                </p>
            </div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">Spending by category</h2>
            <div class="divide-y divide-slate-100">
                @forelse ($this->categorySpending as $category)
                    <div class="flex items-center justify-between px-4 py-3 text-sm">
                        <span class="text-slate-700">{{ $category['name'] }}</span>
                        <span class="font-medium text-slate-900">{{ Money::formatMinor($category['amount_minor']) }}</span>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-slate-500">No spending this month.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">Largest transactions</h2>
            <div class="divide-y divide-slate-100">
                @forelse ($this->largestTransactions as $transaction)
                    <div class="flex items-center justify-between px-4 py-3 text-sm">
                        <div>
                            <p class="text-slate-700">{{ $transaction['description'] ?: 'Expense' }}</p>
                            <p class="text-xs text-slate-400">{{ $transaction['occurred_at']->format('M j, Y') }}</p>
                        </div>
                        <span class="font-medium text-slate-900">{{ Money::formatMinor($transaction['amount_minor']) }}</span>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-slate-500">No transactions this month.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">Account balances</h2>
        <div class="divide-y divide-slate-100">
            @foreach ($this->accountBalances as $account)
                <div class="flex items-center justify-between px-4 py-3 text-sm">
                    <span class="text-slate-700">{{ $account['name'] }}</span>
                    <span class="font-medium text-slate-900">{{ Money::formatMinor($account['balance_minor']) }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
