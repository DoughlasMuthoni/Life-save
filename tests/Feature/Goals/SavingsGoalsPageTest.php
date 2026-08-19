<?php

namespace Tests\Feature\Goals;

use App\Domain\Finance\Services\TransactionService;
use App\Domain\Goals\Services\SavingsAllocationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class SavingsGoalsPageTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_user_can_create_a_savings_goal_through_the_ui(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('savings-goals')
            ->set('title', 'Emergency Fund')
            ->set('targetAmount', '100000.00')
            ->set('monthlyContribution', '4000.00')
            ->set('priority', 'high')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('goals', [
            'user_id' => $user->id,
            'title' => 'Emergency Fund',
            'target_value' => 10000000,
            'monthly_contribution_minor' => 400000,
        ]);
    }

    public function test_a_user_can_allocate_to_a_goal_through_the_ui(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $goal = $this->createSavingsGoal($user, 'Emergency Fund', 10000000);

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);

        Livewire::actingAs($user)
            ->test('savings-goals')
            ->call('startAllocating', $goal->id)
            ->set('allocateAccountId', (string) $mshwari->id)
            ->set('allocateAmount', '3000.00')
            ->call('confirmAllocate')
            ->assertHasNoErrors();

        $this->assertSame(300000, $goal->fresh()->allocatedAmountMinor());
    }

    public function test_over_allocating_shows_a_validation_error(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $goal = $this->createSavingsGoal($user);

        Livewire::actingAs($user)
            ->test('savings-goals')
            ->call('startAllocating', $goal->id)
            ->set('allocateAccountId', (string) $mshwari->id)
            ->set('allocateAmount', '1000.00')
            ->call('confirmAllocate')
            ->assertHasErrors(['allocateAmount']);
    }

    public function test_the_allocation_breakdown_flags_an_over_allocated_account(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $goal = $this->createSavingsGoal($user);

        $transactions = app(TransactionService::class);
        $transactions->recordIncome($user, $mshwari, $salary, 5000000);
        app(SavingsAllocationService::class)->allocate($user, $goal, $mshwari, 4000000);
        $transactions->recordExpense($user, $mshwari, $groceries, 2000000);

        Livewire::actingAs($user)
            ->test('savings-goals')
            ->assertSee('Over-allocated');
    }
}
