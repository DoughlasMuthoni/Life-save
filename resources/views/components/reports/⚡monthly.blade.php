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
    <x-ui.page-header title="Monthly Report" subtitle="Deterministic figures, calculated directly from your ledger.">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <button wire:click="previousMonth" aria-label="Previous month" class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50">
                    <x-icon name="chevron-left" class="h-4 w-4" />
                </button>
                <span class="w-32 text-center text-sm font-medium text-slate-900">{{ $this->periodStart()->format('F Y') }}</span>
                <button wire:click="nextMonth" aria-label="Next month" class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50">
                    <x-icon name="chevron-right" class="h-4 w-4" />
                </button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card icon="trend-up" label="Income" color="green">
            {{ Money::formatMinor($this->summary['income_minor']) }}
        </x-ui.stat-card>
        <x-ui.stat-card icon="trend-down" label="Expenses" color="red">
            {{ Money::formatMinor($this->summary['expense_minor']) }}
        </x-ui.stat-card>
        <x-ui.stat-card icon="scale" label="Net Cash Flow" :color="$this->summary['net_cash_flow_minor'] >= 0 ? 'blue' : 'red'">
            {{ Money::formatMinor($this->summary['net_cash_flow_minor']) }}
        </x-ui.stat-card>
        <x-ui.stat-card icon="flag" label="Savings Rate" color="purple">
            {{ $this->summary['savings_rate_percent'] }}%
        </x-ui.stat-card>
    </div>

    <x-ui.section title="Compared to the previous month" class="mt-6">
        <div class="grid grid-cols-2 gap-6 p-5 text-sm sm:grid-cols-4">
            <div>
                <p class="text-slate-500">Income</p>
                <p class="mt-1 flex items-center gap-1 font-medium {{ ($this->comparison['income_change_percent'] ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    @if ($this->comparison['income_change_percent'] !== null)
                        <x-icon :name="$this->comparison['income_change_percent'] >= 0 ? 'trend-up' : 'trend-down'" class="h-3.5 w-3.5" />
                    @endif
                    {{ $this->comparison['income_change_percent'] === null ? '—' : ($this->comparison['income_change_percent'] >= 0 ? '+' : '').$this->comparison['income_change_percent'].'%' }}
                </p>
            </div>
            <div>
                <p class="text-slate-500">Expenses</p>
                <p class="mt-1 flex items-center gap-1 font-medium {{ ($this->comparison['expense_change_percent'] ?? 0) <= 0 ? 'text-green-600' : 'text-red-600' }}">
                    @if ($this->comparison['expense_change_percent'] !== null)
                        <x-icon :name="$this->comparison['expense_change_percent'] >= 0 ? 'trend-up' : 'trend-down'" class="h-3.5 w-3.5" />
                    @endif
                    {{ $this->comparison['expense_change_percent'] === null ? '—' : ($this->comparison['expense_change_percent'] >= 0 ? '+' : '').$this->comparison['expense_change_percent'].'%' }}
                </p>
            </div>
        </div>
    </x-ui.section>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.section title="Spending by category">
            @if ($this->categorySpending->isEmpty())
                <x-ui.empty-state icon="tag" title="No spending this month" />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($this->categorySpending as $category)
                        <div class="flex items-center justify-between px-5 py-3 text-sm">
                            <span class="text-slate-700">{{ $category['name'] }}</span>
                            <span class="font-medium text-slate-900">{{ Money::formatMinor($category['amount_minor']) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.section>

        <x-ui.section title="Largest transactions">
            @if ($this->largestTransactions->isEmpty())
                <x-ui.empty-state icon="list" title="No transactions this month" />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($this->largestTransactions as $transaction)
                        <div class="flex items-center justify-between px-5 py-3 text-sm">
                            <div>
                                <p class="text-slate-700">{{ $transaction['description'] ?: 'Expense' }}</p>
                                <p class="text-xs text-slate-400">{{ $transaction['occurred_at']->format('M j, Y') }}</p>
                            </div>
                            <span class="font-medium text-slate-900">{{ Money::formatMinor($transaction['amount_minor']) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.section>
    </div>

    <x-ui.section title="Account balances" class="mt-6">
        <div class="divide-y divide-slate-100">
            @foreach ($this->accountBalances as $account)
                <div class="flex items-center justify-between px-5 py-3 text-sm">
                    <span class="text-slate-700">{{ $account['name'] }}</span>
                    <span class="font-medium text-slate-900">{{ Money::formatMinor($account['balance_minor']) }}</span>
                </div>
            @endforeach
        </div>
    </x-ui.section>
</div>
