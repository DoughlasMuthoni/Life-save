<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\TransactionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class RecordExpenseTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_user_can_record_an_expense_through_the_ui(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 1000000);

        Livewire::actingAs($user)
            ->test('finance.record-expense')
            ->set('financialAccountId', (string) $mpesa->id)
            ->set('categoryId', (string) $groceries->id)
            ->set('amount', '2450.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('finance.transactions'));

        $this->assertSame(1000000 - 245000, $mpesa->fresh()->balanceMinor());
    }

    public function test_an_income_category_cannot_be_used_for_an_expense(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        Livewire::actingAs($user)
            ->test('finance.record-expense')
            ->set('financialAccountId', (string) $mpesa->id)
            ->set('categoryId', (string) $salary->id)
            ->set('amount', '100.00')
            ->call('save')
            ->assertHasErrors(['categoryId']);

        // The category dropdown only lists expense categories, so this
        // simulates a tampered request; the component must still refuse
        // it rather than trusting a client-supplied category id.
        $this->assertSame(0, $mpesa->fresh()->balanceMinor());
    }
}
