<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Services\TransactionService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class BalanceAsOfTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_balance_as_of_ignores_transactions_after_the_cutoff(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);

        $transactions = app(TransactionService::class);

        $transactions->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-05-01'));
        $transactions->recordExpense($user, $mpesa, $groceries, 200000, Carbon::parse('2025-05-15'));
        $transactions->recordExpense($user, $mpesa, $groceries, 300000, Carbon::parse('2025-06-01'));

        // As of May 20th, only the first two transactions should count.
        $this->assertSame(800000, $mpesa->ledgerAccount->balanceMinorAsOf(Carbon::parse('2025-05-20')));

        // The full running balance includes all three.
        $this->assertSame(500000, $mpesa->fresh()->balanceMinor());
    }

    public function test_balance_as_of_the_exact_transaction_time_includes_that_transaction(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 500000, Carbon::parse('2025-05-01 13:41:00'));

        $this->assertSame(500000, $mpesa->ledgerAccount->balanceMinorAsOf(Carbon::parse('2025-05-01 13:41:00')));
        $this->assertSame(0, $mpesa->ledgerAccount->balanceMinorAsOf(Carbon::parse('2025-05-01 13:40:59')));
    }
}
