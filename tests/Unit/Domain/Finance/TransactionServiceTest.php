<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Services\TransactionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class TransactionServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private TransactionService $transactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactions = app(TransactionService::class);
    }

    public function test_recording_income_increases_the_account_balance(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        $this->transactions->recordIncome($user, $mpesa, $salary, 9634000, description: 'May salary');

        $this->assertSame(9634000, $mpesa->balanceMinor());
    }

    public function test_recording_an_expense_decreases_the_account_balance(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);

        $this->transactions->recordIncome($user, $mpesa, $salary, 1000000);
        $this->transactions->recordExpense($user, $mpesa, $groceries, 245000, description: 'Quickmart');

        $this->assertSame(755000, $mpesa->balanceMinor());
    }

    public function test_an_expense_cannot_use_an_income_category(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        $this->expectException(InvalidArgumentException::class);

        $this->transactions->recordExpense($user, $mpesa, $salary, 100000);
    }

    public function test_an_account_belonging_to_another_user_is_rejected(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $mpesa = $this->createFinancialAccount($owner);
        $salary = $this->createIncomeCategory($owner);

        $this->expectException(InvalidArgumentException::class);

        $this->transactions->recordIncome($intruder, $mpesa, $salary, 100000);
    }
}
