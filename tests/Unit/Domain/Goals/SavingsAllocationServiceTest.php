<?php

namespace Tests\Unit\Domain\Goals;

use App\Domain\Finance\Services\TransactionService;
use App\Domain\Goals\Services\SavingsAllocationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class SavingsAllocationServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private SavingsAllocationService $allocations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocations = app(SavingsAllocationService::class);
    }

    public function test_allocating_to_a_goal_increases_its_progress(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $goal = $this->createSavingsGoal($user, 'Emergency Fund', 10000000);

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        $this->allocations->allocate($user, $goal, $mshwari, 3000000);

        $this->assertSame(3000000, $goal->allocatedAmountMinor());
        $this->assertSame(30.0, $goal->progressPercent());
        $this->assertSame(7000000, $goal->remainingAmountMinor());
    }

    public function test_allocating_more_than_the_unallocated_balance_is_rejected(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $goal = $this->createSavingsGoal($user, 'Emergency Fund');

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 1000000);

        $this->expectException(InvalidArgumentException::class);

        $this->allocations->allocate($user, $goal, $mshwari, 2000000);
    }

    public function test_virtual_allocations_never_increase_the_real_account_balance(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $goal = $this->createSavingsGoal($user, 'Emergency Fund');

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        $balanceBefore = $mshwari->fresh()->balanceMinor();

        $this->allocations->allocate($user, $goal, $mshwari, 3000000);

        $this->assertSame($balanceBefore, $mshwari->fresh()->balanceMinor());
    }

    public function test_two_goals_allocating_against_the_same_account_correctly_share_it(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $emergency = $this->createSavingsGoal($user, 'Emergency Fund', 10000000);
        $laptop = $this->createSavingsGoal($user, 'Laptop', 7000000);

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);

        $this->allocations->allocate($user, $emergency, $mshwari, 3000000);
        $this->allocations->allocate($user, $laptop, $mshwari, 1500000);

        $this->assertSame(500000, $this->allocations->unallocatedForAccount($mshwari));

        // A third allocation that would exceed what's left is rejected...
        $this->expectException(InvalidArgumentException::class);
        $this->allocations->allocate($user, $laptop, $mshwari, 600000);
    }

    public function test_releasing_frees_up_the_account_for_reallocation(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $goal = $this->createSavingsGoal($user, 'Emergency Fund');

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        $this->allocations->allocate($user, $goal, $mshwari, 3000000);
        $this->allocations->release($user, $goal, $mshwari, 1000000);

        $this->assertSame(2000000, $goal->allocatedAmountMinor());
        $this->assertSame(3000000, $this->allocations->unallocatedForAccount($mshwari));
    }

    public function test_releasing_more_than_allocated_is_rejected(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $goal = $this->createSavingsGoal($user, 'Emergency Fund');

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        $this->allocations->allocate($user, $goal, $mshwari, 1000000);

        $this->expectException(InvalidArgumentException::class);

        $this->allocations->release($user, $goal, $mshwari, 2000000);
    }

    public function test_reallocating_moves_money_from_one_goal_to_another(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $emergency = $this->createSavingsGoal($user, 'Emergency Fund');
        $laptop = $this->createSavingsGoal($user, 'Laptop');

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        $this->allocations->allocate($user, $emergency, $mshwari, 3000000);

        $this->allocations->reallocate($user, $emergency, $laptop, $mshwari, 1000000);

        $this->assertSame(2000000, $emergency->allocatedAmountMinor());
        $this->assertSame(1000000, $laptop->allocatedAmountMinor());
        // Reallocation doesn't change the total earmarked against the account.
        $this->assertSame(2000000, $this->allocations->unallocatedForAccount($mshwari));
    }

    public function test_a_later_expense_can_push_an_account_into_over_allocation_and_it_is_flagged_not_corrected(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $goal = $this->createSavingsGoal($user, 'Emergency Fund');

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        $this->allocations->allocate($user, $goal, $mshwari, 4000000);

        $this->assertFalse($this->allocations->isOverAllocated($mshwari));

        // Spending directly from a savings account is legitimate and not
        // prevented — but it can retroactively push allocations past the
        // real balance.
        app(TransactionService::class)->recordExpense($user, $mshwari, $groceries, 2000000);

        $this->assertTrue($this->allocations->isOverAllocated($mshwari));
        $this->assertSame(-1000000, $this->allocations->unallocatedForAccount($mshwari));

        // The goal's own allocation record is untouched — nothing silently
        // decided which goal "loses" the shortfall.
        $this->assertSame(4000000, $goal->allocatedAmountMinor());
    }

    public function test_a_user_cannot_allocate_to_another_users_goal(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $mshwari = $this->createFinancialAccount($owner, 'M-Shwari');
        $goal = $this->createSavingsGoal($owner, 'Emergency Fund');

        $this->expectException(InvalidArgumentException::class);

        $this->allocations->allocate($intruder, $goal, $mshwari, 100000);
    }
}
