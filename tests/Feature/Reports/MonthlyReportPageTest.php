<?php

namespace Tests\Feature\Reports;

use App\Domain\Finance\Services\TransactionService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class MonthlyReportPageTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_it_renders_the_current_month_by_default(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $transactions = app(TransactionService::class);

        $transactions->recordIncome($user, $mpesa, $salary, 1000000);
        $transactions->recordExpense($user, $mpesa, $groceries, 250000, description: 'Quickmart');

        Livewire::actingAs($user)
            ->test('reports.monthly')
            ->assertSee(now()->format('F Y'))
            ->assertSee('Quickmart')
            ->assertSee('Groceries');
    }

    public function test_navigating_to_the_previous_month_updates_the_figures(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        $lastMonth = now()->subMonthNoOverflow();
        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 2000000, $lastMonth);

        Livewire::actingAs($user)
            ->test('reports.monthly')
            ->call('previousMonth')
            ->assertSee($lastMonth->format('F Y'))
            ->assertSee('KSh 20,000.00');
    }

    public function test_a_specific_month_can_be_requested_via_the_url(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 500000, Carbon::parse('2025-03-15'));

        Livewire::actingAs($user)
            ->test('reports.monthly', ['month' => '2025-03'])
            ->assertSee('March 2025')
            ->assertSee('KSh 5,000.00');
    }
}
