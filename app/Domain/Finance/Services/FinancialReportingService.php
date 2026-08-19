<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerEntrySide;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Models\Journal;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Goals\Enums\GoalStatus;
use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Services\SavingsAllocationService;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every number a report, the dashboard, or the AI assistant shows comes
 * from here — deterministic first, AI second (CLAUDE.md §REPORTING). AI
 * may narrate these figures; it never calculates its own.
 *
 * Income/expense totals are computed from ledger_entries filtered by
 * ledger_account TYPE (INCOME/EXPENSE), not by journal_type — this is
 * what makes a transfer's fee leg count as a real expense while the
 * transfer's principal never does (TransferService never posts to an
 * INCOME/EXPENSE account for the principal), and what makes a reversal
 * correctly net out rather than needing special-case handling.
 */
class FinancialReportingService
{
    private const SAVINGS_PROVIDERS = [
        FinancialAccountProvider::MSHWARI,
        FinancialAccountProvider::KCB_MPESA,
        FinancialAccountProvider::BANK,
    ];

    /**
     * @return array{income_minor: int, expense_minor: int, net_cash_flow_minor: int, savings_rate_percent: float}
     */
    public function getFinancialSummary(User $user, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $income = $this->netAmountForType($user, LedgerAccountType::INCOME, $periodStart, $periodEnd);
        $expense = $this->netAmountForType($user, LedgerAccountType::EXPENSE, $periodStart, $periodEnd);

        return [
            'income_minor' => $income,
            'expense_minor' => $expense,
            'net_cash_flow_minor' => $income - $expense,
            'savings_rate_percent' => $income > 0 ? round((($income - $expense) / $income) * 100, 1) : 0.0,
        ];
    }

    /**
     * @return Collection<int, array{transaction_category_id: int, name: string, amount_minor: int}>
     */
    public function getCategorySpending(User $user, CarbonInterface $periodStart, CarbonInterface $periodEnd): Collection
    {
        return LedgerEntry::query()
            ->join('journals', 'journals.id', '=', 'ledger_entries.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->join('transaction_categories', 'transaction_categories.id', '=', 'ledger_entries.transaction_category_id')
            ->where('journals.user_id', $user->id)
            ->where('ledger_accounts.type', LedgerAccountType::EXPENSE->value)
            ->whereBetween('journals.occurred_at', [$periodStart, $periodEnd])
            ->selectRaw("
                transaction_categories.id as transaction_category_id,
                transaction_categories.name as name,
                SUM(CASE WHEN ledger_entries.side = 'debit' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END) as amount_minor
            ")
            ->groupBy('transaction_categories.id', 'transaction_categories.name')
            ->havingRaw('SUM(CASE WHEN ledger_entries.side = \'debit\' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END) > 0')
            ->orderByDesc('amount_minor')
            ->get()
            ->map(fn ($row) => [
                'transaction_category_id' => (int) $row->transaction_category_id,
                'name' => $row->name,
                'amount_minor' => (int) $row->amount_minor,
            ]);
    }

    /**
     * @return array{period_a: array, period_b: array, income_change_percent: ?float, expense_change_percent: ?float}
     */
    public function compareFinancialPeriods(
        User $user,
        CarbonInterface $periodAStart,
        CarbonInterface $periodAEnd,
        CarbonInterface $periodBStart,
        CarbonInterface $periodBEnd,
    ): array {
        $periodA = $this->getFinancialSummary($user, $periodAStart, $periodAEnd);
        $periodB = $this->getFinancialSummary($user, $periodBStart, $periodBEnd);

        return [
            'period_a' => $periodA,
            'period_b' => $periodB,
            'income_change_percent' => $this->percentChange($periodA['income_minor'], $periodB['income_minor']),
            'expense_change_percent' => $this->percentChange($periodA['expense_minor'], $periodB['expense_minor']),
        ];
    }

    /**
     * @return Collection<int, array{financial_account_id: int, name: string, provider: string, balance_minor: int}>
     */
    public function getAccountBalances(User $user): Collection
    {
        return FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get()
            ->map(fn (FinancialAccount $account) => [
                'financial_account_id' => $account->id,
                'name' => $account->name,
                'provider' => $account->provider->value,
                'balance_minor' => $account->balanceMinor(),
            ]);
    }

    public function netAvailableCashMinor(User $user, SavingsAllocationService $allocations): int
    {
        $accounts = FinancialAccount::query()->where('user_id', $user->id)->where('is_active', true)->get();

        $totalBalance = $accounts->sum(fn (FinancialAccount $a) => $a->balanceMinor());
        $totalAllocated = $accounts->sum(fn (FinancialAccount $a) => $allocations->totalAllocatedForAccount($a));

        return (int) $totalBalance - (int) $totalAllocated;
    }

    public function mpesaBalanceMinor(User $user): int
    {
        return FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', FinancialAccountProvider::MPESA)
            ->where('is_active', true)
            ->get()
            ->sum(fn (FinancialAccount $a) => $a->balanceMinor());
    }

    /**
     * Heuristic: accounts on savings-oriented providers, summed. There's
     * no explicit "is this a savings account" flag on financial_accounts —
     * this is a pragmatic default, not a rigid categorization.
     */
    public function savingsTotalMinor(User $user): int
    {
        return FinancialAccount::query()
            ->where('user_id', $user->id)
            ->whereIn('provider', array_map(fn ($p) => $p->value, self::SAVINGS_PROVIDERS))
            ->where('is_active', true)
            ->get()
            ->sum(fn (FinancialAccount $a) => $a->balanceMinor());
    }

    /**
     * @return Collection<int, array{journal_id: int, description: ?string, occurred_at: CarbonInterface, amount_minor: int}>
     */
    public function getLargestTransactions(User $user, CarbonInterface $periodStart, CarbonInterface $periodEnd, int $limit = 5): Collection
    {
        return LedgerEntry::query()
            ->join('journals', 'journals.id', '=', 'ledger_entries.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('journals.user_id', $user->id)
            ->where('ledger_accounts.type', LedgerAccountType::EXPENSE->value)
            ->where('ledger_entries.side', LedgerEntrySide::DEBIT->value)
            ->whereBetween('journals.occurred_at', [$periodStart, $periodEnd])
            ->where('journals.is_reversed', false)
            ->orderByDesc('ledger_entries.amount_minor')
            ->limit($limit)
            ->select(['journals.id as journal_id', 'journals.description', 'journals.occurred_at', 'ledger_entries.amount_minor'])
            ->get()
            ->map(fn ($row) => [
                'journal_id' => (int) $row->journal_id,
                'description' => $row->description,
                // This query is built on LedgerEntry, whose model doesn't
                // cast 'occurred_at' (it's a Journal column, only present
                // here via the join/select alias) — cast it explicitly
                // rather than relying on Eloquent to have done it.
                'occurred_at' => Carbon::parse($row->occurred_at),
                'amount_minor' => (int) $row->amount_minor,
            ]);
    }

    /**
     * @return Collection<int, array{goal_id: int, title: string, target_value: int, allocated_minor: int, progress_percent: float, months_remaining: ?int}>
     */
    public function getSavingsGoalProgress(User $user): Collection
    {
        return Goal::query()
            ->where('user_id', $user->id)
            ->where('status', GoalStatus::ACTIVE)
            ->get()
            ->map(fn (Goal $goal) => [
                'goal_id' => $goal->id,
                'title' => $goal->title,
                'target_value' => $goal->target_value,
                'allocated_minor' => $goal->allocatedAmountMinor(),
                'progress_percent' => $goal->progressPercent(),
                'months_remaining' => $goal->monthsRemaining(),
            ]);
    }

    /**
     * Goals with a target_date where more of the timeline has elapsed than
     * progress made toward the target — a simple, deterministic "behind
     * schedule" signal for the dashboard's attention items. Goals with no
     * target_date are never flagged (nothing to be behind).
     *
     * @return Collection<int, array{goal_id: int, title: string, progress_percent: float, expected_percent: float}>
     */
    public function goalsBehindTarget(User $user): Collection
    {
        return Goal::query()
            ->where('user_id', $user->id)
            ->where('status', GoalStatus::ACTIVE)
            ->whereNotNull('target_date')
            ->get()
            ->map(function (Goal $goal) {
                $totalDays = max(1, $goal->created_at->diffInDays($goal->target_date));
                $elapsedDays = min($totalDays, $goal->created_at->diffInDays(now()));
                $expectedPercent = round(($elapsedDays / $totalDays) * 100, 1);

                return [
                    'goal_id' => $goal->id,
                    'title' => $goal->title,
                    'progress_percent' => $goal->progressPercent(),
                    'expected_percent' => $expectedPercent,
                ];
            })
            ->filter(fn ($row) => $row['progress_percent'] < $row['expected_percent'] - 10)
            ->values();
    }

    /**
     * A category's spending this month vs its trailing 3-month average.
     * Flags categories running well above their own recent normal —
     * simple, deterministic, and doesn't fire on tiny/noisy amounts.
     *
     * @return Collection<int, array{name: string, this_month_minor: int, average_minor: int, ratio: float}>
     */
    public function detectUnusualSpending(User $user, CarbonInterface $monthStart): Collection
    {
        $monthEnd = $monthStart->copy()->endOfMonth();
        $lookbackStart = $monthStart->copy()->subMonths(3)->startOfMonth();
        $lookbackEnd = $monthStart->copy()->subDay()->endOfDay();

        $thisMonth = $this->getCategorySpending($user, $monthStart->copy()->startOfDay(), $monthEnd)
            ->keyBy('transaction_category_id');

        $trailing = $this->getCategorySpending($user, $lookbackStart, $lookbackEnd)
            ->keyBy('transaction_category_id');

        $minimumAmountMinor = 100000; // KSh 1,000 — ignore noise on trivial categories

        return $thisMonth
            ->filter(function ($current) use ($trailing, $minimumAmountMinor) {
                $previous = $trailing->get($current['transaction_category_id']);
                $averageMinor = $previous ? (int) round($previous['amount_minor'] / 3) : 0;

                return $current['amount_minor'] >= $minimumAmountMinor
                    && $averageMinor > 0
                    && $current['amount_minor'] > $averageMinor * 1.5;
            })
            ->map(function ($current) use ($trailing) {
                $previous = $trailing->get($current['transaction_category_id']);
                $averageMinor = (int) round($previous['amount_minor'] / 3);

                return [
                    'name' => $current['name'],
                    'this_month_minor' => $current['amount_minor'],
                    'average_minor' => $averageMinor,
                    'ratio' => round($current['amount_minor'] / $averageMinor, 2),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, array{journal_id: int, occurred_at: CarbonInterface, journal_type: string, description: ?string, amount_minor: int}>
     */
    public function getTransactions(User $user, CarbonInterface $periodStart, CarbonInterface $periodEnd, int $limit = 20): Collection
    {
        return Journal::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$periodStart, $periodEnd])
            ->with('entries')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(function ($journal) {
                // The "primary" amount for a journal — the largest single
                // leg — is a reasonable one-number summary for a list view.
                $amountMinor = $journal->entries->max('amount_minor') ?? 0;

                return [
                    'journal_id' => $journal->id,
                    'occurred_at' => $journal->occurred_at,
                    'journal_type' => $journal->journal_type->value,
                    'description' => $journal->description,
                    'amount_minor' => (int) $amountMinor,
                ];
            });
    }

    private function netAmountForType(User $user, LedgerAccountType $type, CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        $normalSide = $type->normalBalanceSide();

        $entries = LedgerEntry::query()
            ->join('journals', 'journals.id', '=', 'ledger_entries.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('journals.user_id', $user->id)
            ->where('ledger_accounts.type', $type->value)
            ->whereBetween('journals.occurred_at', [$periodStart, $periodEnd]);

        $sameSideTotal = (clone $entries)->where('ledger_entries.side', $normalSide->value)->sum('ledger_entries.amount_minor');
        $oppositeSideTotal = (clone $entries)->where('ledger_entries.side', $normalSide->opposite()->value)->sum('ledger_entries.amount_minor');

        return (int) $sameSideTotal - (int) $oppositeSideTotal;
    }

    private function percentChange(int $fromMinor, int $toMinor): ?float
    {
        if ($fromMinor === 0) {
            return null;
        }

        return round((($toMinor - $fromMinor) / abs($fromMinor)) * 100, 1);
    }
}
