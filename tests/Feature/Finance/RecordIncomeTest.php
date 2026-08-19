<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class RecordIncomeTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_user_can_record_income_through_the_ui(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        Livewire::actingAs($user)
            ->test('finance.record-income')
            ->set('financialAccountId', (string) $mpesa->id)
            ->set('categoryId', (string) $salary->id)
            ->set('amount', '5,000.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('finance.transactions'));

        $this->assertSame(500000, $mpesa->fresh()->balanceMinor());
        $this->assertDatabaseHas('journals', ['user_id' => $user->id, 'journal_type' => 'income']);
    }

    public function test_an_invalid_amount_is_rejected(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        Livewire::actingAs($user)
            ->test('finance.record-income')
            ->set('financialAccountId', (string) $mpesa->id)
            ->set('categoryId', (string) $salary->id)
            ->set('amount', 'not-a-number')
            ->call('save')
            ->assertHasErrors(['amount']);

        $this->assertSame(0, $mpesa->fresh()->balanceMinor());
    }
}
