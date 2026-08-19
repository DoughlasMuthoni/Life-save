<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Services\FinancialReportingService;
use App\Domain\Finance\Services\ReversalService;
use App\Domain\Finance\Services\TransactionService;
use App\Domain\Finance\Services\TransferService;
use App\Domain\Goals\Services\SavingsAllocationService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class FinancialReportingServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private FinancialReportingService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = app(FinancialReportingService::class);
    }

    public function test_financial_summary_totals_income_and_expenses_within_the_period(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $transactions = app(TransactionService::class);

        $transactions->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-05-05'));
        $transactions->recordExpense($user, $mpesa, $groceries, 300000, Carbon::parse('2025-05-10'));
        // Outside the period — must not be counted.
        $transactions->recordExpense($user, $mpesa, $groceries, 999999, Carbon::parse('2025-04-01'));

        $summary = $this->reports->getFinancialSummary($user, Carbon::parse('2025-05-01'), Carbon::parse('2025-05-31 23:59:59'));

        $this->assertSame(1000000, $summary['income_minor']);
        $this->assertSame(300000, $summary['expense_minor']);
        $this->assertSame(700000, $summary['net_cash_flow_minor']);
        $this->assertSame(70.0, $summary['savings_rate_percent']);
    }

    public function test_a_transfer_never_counts_as_income_or_expense_in_the_summary(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-05-01'));
        app(TransferService::class)->recordTransfer($user, $mpesa, $mshwari, 400000, occurredAt: Carbon::parse('2025-05-02'));

        $summary = $this->reports->getFinancialSummary($user, Carbon::parse('2025-05-01'), Carbon::parse('2025-05-31 23:59:59'));

        $this->assertSame(1000000, $summary['income_minor']);
        $this->assertSame(0, $summary['expense_minor']);
    }

    public function test_a_transfer_fee_still_counts_as_a_real_expense(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);
        $fees = $this->createExpenseCategory($user, 'Transaction Fees');

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-05-01'));
        app(TransferService::class)->recordTransfer($user, $mpesa, $mshwari, 400000, $fees, 1500, occurredAt: Carbon::parse('2025-05-02'));

        $summary = $this->reports->getFinancialSummary($user, Carbon::parse('2025-05-01'), Carbon::parse('2025-05-31 23:59:59'));

        $this->assertSame(1500, $summary['expense_minor']);
    }

    public function test_a_reversed_expense_still_counts_in_the_period_it_actually_happened_in(): void
    {
        // Reversing doesn't rewrite history: the original expense genuinely
        // happened in May, so May's report still shows it. ReversalService
        // posts the correction as of "now" (today, whenever the test
        // runs) — that's when the correction actually occurred.
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-05-01'));
        $expense = app(TransactionService::class)->recordExpense($user, $mpesa, $groceries, 300000, Carbon::parse('2025-05-10'));
        app(ReversalService::class)->reverseJournal($user, $expense, 'entered twice');

        $mayOnly = $this->reports->getFinancialSummary($user, Carbon::parse('2025-05-01'), Carbon::parse('2025-05-31 23:59:59'));
        $this->assertSame(300000, $mayOnly['expense_minor']);

        // A range spanning both the original expense and today's reversal
        // nets to zero, as it should.
        $spanningBoth = $this->reports->getFinancialSummary($user, Carbon::parse('2025-05-01'), now()->endOfDay());
        $this->assertSame(0, $spanningBoth['expense_minor']);
    }

    public function test_category_spending_is_grouped_and_sorted_descending(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user, 'Groceries');
        $transport = $this->createExpenseCategory($user, 'Transport');
        $transactions = app(TransactionService::class);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 2000000, Carbon::parse('2025-05-01'));
        $transactions->recordExpense($user, $mpesa, $groceries, 150000, Carbon::parse('2025-05-05'));
        $transactions->recordExpense($user, $mpesa, $groceries, 100000, Carbon::parse('2025-05-06'));
        $transactions->recordExpense($user, $mpesa, $transport, 80000, Carbon::parse('2025-05-07'));

        $spending = $this->reports->getCategorySpending($user, Carbon::parse('2025-05-01'), Carbon::parse('2025-05-31 23:59:59'));

        $this->assertSame('Groceries', $spending->first()['name']);
        $this->assertSame(250000, $spending->first()['amount_minor']);
        $this->assertSame(80000, $spending->last()['amount_minor']);
    }

    public function test_compare_financial_periods_computes_percent_change(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $transactions = app(TransactionService::class);

        $transactions->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-04-01'));
        $transactions->recordExpense($user, $mpesa, $groceries, 200000, Carbon::parse('2025-04-05'));

        $transactions->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-05-01'));
        $transactions->recordExpense($user, $mpesa, $groceries, 100000, Carbon::parse('2025-05-05'));

        $comparison = $this->reports->compareFinancialPeriods(
            $user,
            Carbon::parse('2025-04-01'), Carbon::parse('2025-04-30 23:59:59'),
            Carbon::parse('2025-05-01'), Carbon::parse('2025-05-31 23:59:59'),
        );

        $this->assertSame(0.0, $comparison['income_change_percent']);
        $this->assertSame(-50.0, $comparison['expense_change_percent']);
    }

    public function test_net_available_cash_subtracts_goal_allocations(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);
        $goal = $this->createSavingsGoal($user, 'Emergency Fund');

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        app(SavingsAllocationService::class)->allocate($user, $goal, $mshwari, 2000000);

        $netAvailable = $this->reports->netAvailableCashMinor($user, app(SavingsAllocationService::class));

        $this->assertSame(3000000, $netAvailable);
    }

    public function test_savings_total_only_counts_savings_oriented_providers(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 1000000);
        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 2000000);

        $this->assertSame(2000000, $this->reports->savingsTotalMinor($user));
        $this->assertSame(1000000, $this->reports->mpesaBalanceMinor($user));
    }

    public function test_get_transactions_returns_recent_journals_within_the_period(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $transactions = app(TransactionService::class);

        $transactions->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-05-01'));
        $transactions->recordExpense($user, $mpesa, $groceries, 250000, Carbon::parse('2025-05-05'), description: 'Quickmart');
        $transactions->recordExpense($user, $mpesa, $groceries, 999999, Carbon::parse('2025-04-01'));

        $results = $this->reports->getTransactions($user, Carbon::parse('2025-05-01'), Carbon::parse('2025-05-31 23:59:59'));

        $this->assertCount(2, $results);
        $this->assertSame('Quickmart', $results->first()['description']);
        $this->assertSame(250000, $results->first()['amount_minor']);
    }

    public function test_unusual_spending_flags_a_category_well_above_its_recent_average(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $transactions = app(TransactionService::class);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 10000000, Carbon::parse('2025-02-01'));

        // ~2,000/mo for the prior 3 months.
        $transactions->recordExpense($user, $mpesa, $groceries, 200000, Carbon::parse('2025-02-10'));
        $transactions->recordExpense($user, $mpesa, $groceries, 200000, Carbon::parse('2025-03-10'));
        $transactions->recordExpense($user, $mpesa, $groceries, 200000, Carbon::parse('2025-04-10'));

        // This month: way above that average.
        $transactions->recordExpense($user, $mpesa, $groceries, 1000000, Carbon::parse('2025-05-10'));

        $unusual = $this->reports->detectUnusualSpending($user, Carbon::parse('2025-05-01'));

        $this->assertCount(1, $unusual);
        $this->assertSame('Groceries', $unusual->first()['name']);
        $this->assertSame(1000000, $unusual->first()['this_month_minor']);
    }

    public function test_goals_behind_target_flags_a_goal_with_little_progress_and_little_time_left(): void
    {
        $user = User::factory()->create();
        $goal = $this->createSavingsGoal($user, 'Vacation', 1000000, monthlyContributionMinor: null);
        // Created 6 months ago, due in 1 more month — 6/7 of the timeline
        // elapsed, but nothing has been allocated yet.
        $goal->forceFill(['created_at' => now()->subMonths(6), 'target_date' => now()->addMonth()])->save();

        $behind = $this->reports->goalsBehindTarget($user);

        $this->assertCount(1, $behind);
        $this->assertSame('Vacation', $behind->first()['title']);
    }

    public function test_goals_behind_target_ignores_goals_without_a_target_date(): void
    {
        $user = User::factory()->create();
        $this->createSavingsGoal($user, 'Emergency Fund', 1000000);

        $this->assertCount(0, $this->reports->goalsBehindTarget($user));
    }

    public function test_goals_behind_target_ignores_a_goal_on_pace(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $goal = $this->createSavingsGoal($user, 'Vacation', 1000000);
        $goal->forceFill(['created_at' => now()->subMonths(6), 'target_date' => now()->addMonth()])->save();

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 5000000);
        app(SavingsAllocationService::class)->allocate($user, $goal, $mpesa, 900000);

        $this->assertCount(0, $this->reports->goalsBehindTarget($user));
    }

    public function test_unusual_spending_does_not_flag_normal_variation(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $transactions = app(TransactionService::class);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 10000000, Carbon::parse('2025-02-01'));

        $transactions->recordExpense($user, $mpesa, $groceries, 200000, Carbon::parse('2025-02-10'));
        $transactions->recordExpense($user, $mpesa, $groceries, 200000, Carbon::parse('2025-03-10'));
        $transactions->recordExpense($user, $mpesa, $groceries, 200000, Carbon::parse('2025-04-10'));
        $transactions->recordExpense($user, $mpesa, $groceries, 220000, Carbon::parse('2025-05-10'));

        $this->assertCount(0, $this->reports->detectUnusualSpending($user, Carbon::parse('2025-05-01')));
    }
}
